'use strict';

// Never enable Playwright/browser debug channels or inherit application credentials.
for (const key of ['DEBUG', 'PWDEBUG', 'DEBUG_FILE', 'CHROME_LOG_FILE']) delete process.env[key];
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const { HostedPolicy, egressProxy, assetMatches } = require('./hosted-policy.cjs');
const { RiskJournalClient } = require('./hosted-risk-journal.cjs');
const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');

// Contains only evidence constructed from fixed local labels/counts, never a caught Error.
class PreflightFailure extends Error {
  #safe;
  constructor(safe) { super('Browser preflight failed safely.'); this.#safe = Object.freeze(safe); }
  evidence() { return { ...this.#safe }; }
}
let startupPhase = 'configuration';

async function inspect(initial, playwright, fixtureOrigin = null, exercise = null, expected = null, render = false, observeConfig = false, observeNormal = false, riskJournal = null, riskFixture = null) {
  const policy = new HostedPolicy(initial, fixtureOrigin, expected, render, observeConfig, observeNormal);
  if (riskJournal !== null && !observeNormal) throw new Error('Risk observation requires explicit normal reads.');
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'airwallex-browser-preflight-'));
  await fs.chmod(directory, 0o700);
  let proxy;
  let browser;
  let context;
  let mainPage;
  let publicClientPromise;
  let methodClientPromise;
  const normalClientPromises = new Set();
  const normalClientStates = new Map();
  const normalInFlight = new Set();
  let normalAdmissionClosed = false;
  let normalWindowTimer;
  let normalDeadlineFired = false;
  let browserContextCloseStarted = false;
  let browserContextClosed = false;
  const normal = {};
  for (const role of ['customer', 'consent_list', 'country', 'checkout_status']) {
    normal['normal_' + role + '_sends'] = 0;
    normal['normal_' + role + '_delivered'] = 0;
    normal['normal_' + role + '_observation'] = 'not_observed';
  }
  const normalStep = async action => {
    if (!observeNormal) return action();
    const remaining = policy.normalWindowRemaining() - 500;
    if (shuttingDown || remaining <= 0) throw new Error('The read-only observation window ended.');
    let timer;
    try {
      return await Promise.race([action(), new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error('The read-only observation window ended.')), remaining);
      })]);
    } finally { clearTimeout(timer); }
  };
  let boundFrame;
  let shuttingDown = false;
  let result;
  let readiness = {};
  let readinessAttachment = {};
  let failed = false;
  let firstFailure;
  let methodSendStarted = false;
  const markFailure = phase => { failed = true; firstFailure ??= phase; };
  const step = async (phase, action) => {
    try { return await action(); }
    catch { markFailure(phase); throw new Error('Browser preflight failed safely.'); }
  };
  const pending = new Set();
  const track = (action, phase = 'route_action') => {
    const safe = Promise.resolve().then(action).catch(() => { markFailure(phase); });
    pending.add(safe);
    void safe.then(() => pending.delete(safe));
    return safe;
  };
  const expireNormalWindow = () => {
    if (normalDeadlineFired) return;
    normalDeadlineFired = true;
    policy.closeNormalWindow();
    markFailure('normal_observation_deadline');
    shuttingDown = true;
    if (context) { browserContextCloseStarted = true; void track(() => context.close(), 'shutdown_browser_context'); }
  };
  const drainSentNormalReads = async () => {
    if (!observeNormal || shuttingDown || normalInFlight.size === 0) return;
    const remaining = policy.normalWindowRemaining();
    if (remaining <= 0) { expireNormalWindow(); return; }
    let timer;
    try {
      await Promise.race([Promise.all([...normalInFlight]), new Promise(resolve => {
        timer = setTimeout(() => { expireNormalWindow(); resolve(); }, remaining);
      })]);
    } finally { clearTimeout(timer); }
  };
  const caseDeadline = performance.now() + (render ? 40000 : 20000);
  const risk = new HostedRiskObserver(policy, riskJournal, playwright, riskFixture, caseDeadline);
  const watchdog = setTimeout(() => {
    markFailure('watchdog');
    shuttingDown = true;
    if (context) { browserContextCloseStarted = true; void track(() => context.close()); }
    if (browser) void track(() => browser.close());
  }, Math.max(1, caseDeadline - performance.now()));
  watchdog.unref();
  const counts = { blocked_requests: 0, blocked_websockets: 0, returns_intercepted: 0,
    verified_static_assets: 0, integrity_rejections: 0, verified_render_assets: 0, bound_render_frames: 0,
    controller_asset_requests: 0, verified_controller_assets: 0, controller_replays_blocked: 0 };
  let observationDone;
  const observed = new Promise(resolve => { observationDone = resolve; });
  let observationStarted;
  const started = new Promise(resolve => { observationStarted = resolve; });
  let method = { method_config_observation: 'not_observed', method_config_response_observed: false,
    method_config_payload_delivered: false };
  let payer = { payer_observation: 'not_observed', payer_response_observed: false,
    payer_mode_matches: false, payer_currency_matches: false };
  try {
    proxy = await step('proxy_start', () => egressProxy({
      allowsAuthority: (host, port) => policy.allowsAuthority(host, port) || risk.authority(host, port),
      allowsHttp: value => policy.allowsHttp(value),
    }));
    risk.start(proxy.server);
    browser = await step('browser_launch', () => playwright.chromium.launch({
      channel: 'chrome', headless: true, timeout: 15000,
      proxy: { server: proxy.server, bypass: '' },
      env: { PATH: process.env.PATH ?? '', LANG: 'en_US.UTF-8', TMPDIR: directory },
      args: ['--proxy-bypass-list=<-loopback>', '--disable-quic',
        '--force-webrtc-ip-handling-policy=disable_non_proxied_udp', '--disable-breakpad'],
    }));
    context = await step('browser_context', () => browser.newContext({
      ignoreHTTPSErrors: false, serviceWorkers: 'block', acceptDownloads: false,
      permissions: [],
    }));
    if (observeNormal) context.on('close', () => { browserContextClosed = true; });
    await context.addInitScript(() => {
      for (const name of ['Worker', 'SharedWorker']) {
        Object.defineProperty(globalThis, name, {
          value: class { constructor() { throw new Error('Workers are disabled in this preflight.'); } },
          writable: false, configurable: false,
        });
      }
    });
    await context.route('**/*', route => track(async () => {
      if (shuttingDown) throw new Error('Browser preflight is shutting down.');
      const request = route.request();
      if (normalAdmissionClosed) {
        counts.blocked_requests++;
        policy.noteDeniedRead(request.url(), request.method(), request.resourceType());
        await route.abort('blockedbyclient');
        return;
      }
      if (await risk.handle(route, context, mainPage?.mainFrame(), boundFrame,
        () => shuttingDown, () => normalAdmissionClosed)) return;
      // Script admission and integrity share one immutable, non-null expected hash.
      const script = request.resourceType() === 'script';
      const expectedHash = script ? policy.assetHash(request.url(), request.method(), request.resourceType()) : null;
      let action = script ? (expectedHash === null ? 'block' : 'allow')
        : policy.decide(request.url(), request.method(), request.resourceType());
      const controller = expectedHash !== null
        && policy.isControllerTarget(request.url(), request.method(), request.resourceType());
      if (controller) {
        if (policy.claimControllerRequest(request.url(), request.method(), request.resourceType())) {
          counts.controller_asset_requests++;
        } else {
          action = 'block';
          counts.controller_replays_blocked++;
        }
      }
      let renderFrame = false;
      if (render && request.resourceType() === 'document' && action === 'block') {
        policy.noteRenderCheck('document_candidates');
        if (request.method() !== 'GET') {
          policy.noteRenderCheck('method_mismatch');
        } else {
          let frame, parent;
          try { frame = request.frame(); parent = frame.parentFrame(); }
          catch { frame = null; policy.noteRenderCheck('frame_unavailable'); }
          if (frame) {
            if (!mainPage || parent !== mainPage.mainFrame()) {
              policy.noteRenderCheck('parent_mismatch');
            } else if (policy.renderRequestTarget(request.url())) {
              let element;
              try { element = await frame.frameElement(); }
              catch { policy.noteRenderCheck('source_unavailable'); }
              if (element) {
                let source;
                try { source = await element.getAttribute('src'); }
                catch { policy.noteRenderCheck('source_unavailable'); }
                finally { await element.dispose(); }
                if (source !== undefined) renderFrame = policy.claimRenderFrame(source);
              }
              action = renderFrame ? 'allow' : 'block';
            }
          }
        }
      }
      let payerFromMainPage = false;
      if (mainPage && ['xhr', 'fetch'].includes(request.resourceType())
          && request.url().startsWith(new URL('/billing/payer-web-api/checkout?', initial).href)) {
        try { payerFromMainPage = request.frame() === mainPage.mainFrame(); } catch { /* Deny unavailable frame. */ }
      }
      let methodHeaders = null;
      if (observeConfig && boundFrame && ['xhr', 'fetch'].includes(request.resourceType())) {
        let fromBoundFrame = false;
        try { fromBoundFrame = request.frame() === boundFrame; } catch { /* Deny detached or unavailable frames. */ }
        if (fromBoundFrame) methodHeaders = policy.claimMethodConfig(request.url(), request.method(),
          request.resourceType(), request.headers(), request.postData());
      }
      let normalRead = null;
      if (observeNormal && mainPage && ['xhr', 'fetch'].includes(request.resourceType())) {
        let fromMain = false, fromBound = false;
        try {
          const frame = request.frame();
          if (frame.isDetached() === false) {
            fromMain = frame === mainPage.mainFrame() && policy.normalSourceMatches(frame.url(), true);
            fromBound = frame === boundFrame && policy.normalSourceMatches(frame.url());
          }
        } catch { /* No unavailable or replacement document inherits a private read. */ }
        normalRead = policy.claimNormalRead(request.url(), request.method(), request.resourceType(),
          request.headers(), request.postData(), fromMain, fromBound);
      }
      if (normalRead !== null) {
        const { role, headers: outbound } = normalRead;
        if (role === 'checkout_status') delete normal.normal_checkout_status_state;
        const key = 'normal_' + role + '_';
        const originalSource = () => {
          try {
            const frame = request.frame();
            return frame.isDetached() === false && (role === 'checkout_status'
              ? frame === mainPage.mainFrame() && policy.normalSourceMatches(frame.url(), true)
              : frame === boundFrame && policy.normalSourceMatches(frame.url()));
          } catch { return false; }
        };
        let delivered = false;
        let activeRead, finishRead;
        const readStarted = performance.now();
        const clientState = { disposeStarted: false, disposed: false };
        let requestStarted, requestTimeout;
        let readPhase = 'normal_scope', readFailurePhase;
        const captureFailure = phase => {
          if (normal.normal_read_failure) return;
          const now = performance.now();
          const millis = (value, maximum) => Number.isFinite(value) && value >= 0 && value <= maximum
            ? Math.floor(value) : undefined;
          const evidence = { role, phase, deadline_fired: normalDeadlineFired,
            shutdown_started: shuttingDown, browser_context_close_started: browserContextCloseStarted,
            browser_context_closed: browserContextClosed, request_context_dispose_started: clientState.disposeStarted,
            request_context_disposed: clientState.disposed, window_closed: policy.normalEvidence().normal_read_window_closed };
          const timings = { window_remaining_ms: millis(policy.normalWindowRemaining(), 8000),
            elapsed_ms: millis(now - readStarted, 60000), request_timeout_ms: requestTimeout,
            request_elapsed_ms: requestStarted === undefined ? undefined : millis(now - requestStarted, 60000) };
          for (const [name, value] of Object.entries(timings)) if (value !== undefined) evidence[name] = value;
          normal.normal_read_failure = evidence;
        };
        const readStep = async (phase, action) => {
          readPhase = phase;
          try { return await step(phase, action); }
          catch { readFailurePhase ??= phase; captureFailure(phase); throw new Error('The normal read did not complete safely.'); }
        };
        try {
          if (shuttingDown || normalAdmissionClosed || !policy.normalSendAllowed(role) || !originalSource()) {
            throw new Error('The normal read scope ended.');
          }
          // One isolated storage context per send; no response cookie can cross polls or families.
          const construction = readStep('normal_context', () => playwright.request.newContext({
            ignoreHTTPSErrors: false, proxy: { server: proxy.server, bypass: '' },
          }));
          normalClientPromises.add(construction); // Retain before await, including shutdown races.
          normalClientStates.set(construction, clientState);
          const client = await construction;
          readPhase = 'normal_scope';
          const remaining = policy.normalWindowRemaining();
          if (shuttingDown || normalAdmissionClosed || remaining <= 0 || !policy.normalSendAllowed(role) || !originalSource()) {
            throw new Error('The normal read scope ended.');
          }
          normal[key + 'sends']++;
          activeRead = new Promise(resolve => { finishRead = resolve; });
          normalInFlight.add(activeRead);
          requestTimeout = Math.max(1, Math.min(10000, Math.floor(remaining)));
          requestStarted = performance.now();
          const response = await readStep('normal_get', () => client.get(request.url(), {
            headers: outbound, maxRedirects: 0, maxRetries: 0, timeout: requestTimeout,
          }));
          try {
            const status = await readStep('normal_status', () => {
              const value = response.status();
              if (!Number.isInteger(value) || value < 100 || value > 599) throw new Error('Invalid normal read status.');
              return value;
            });
            normal[key + 'http_status'] = status;
            normal[key + 'observation'] = status >= 300 && status < 400 ? 'redirect_rejected' : 'http_rejected';
            if (status === 200) {
              const fields = await readStep('normal_headers', () => response.headersArray());
              const content = fields.filter(field => field.name.toLowerCase() === 'content-type');
              const declared = fields.filter(field => field.name.toLowerCase() === 'content-length');
              const headerBytes = fields.reduce((sum, field) => sum + Buffer.byteLength(field.name + field.value), 0);
              if (headerBytes > 16384 || content.length !== 1
                  || !/^application\/json(?:\s*;[^\r\n]*)?$/iu.test(content[0].value)
                  || declared.length > 1 || (declared.length === 1
                    && (!/^[0-9]+$/u.test(declared[0].value) || Number(declared[0].value) > 65536))) {
                normal[key + 'observation'] = 'invalid_response';
              } else {
                // APIRequestContext already buffers; this is a post-buffer parse/delivery cap.
                const body = await readStep('normal_body', () => response.body());
                const observed = policy.observeNormalRead(role, body);
                normal[key + 'observation'] = observed.matched ? 'matched' : 'invalid_response';
                if (role === 'checkout_status') normal.normal_checkout_status_state = observed.state;
                if (role === 'consent_list' && observed.matched) {
                  for (const fact of ['items', 'pagination_known', 'has_more']) normal[key + fact] = observed[fact];
                }
                if (observed.matched && !shuttingDown && policy.normalSendAllowed(role) && originalSource()) {
                  const headers = Object.fromEntries(fields.filter(field => field.name.toLowerCase() !== 'set-cookie')
                    .map(field => [field.name, field.value]));
                  await readStep('normal_fulfill', () => route.fulfill({ response, headers, body }));
                  normal[key + 'delivered']++;
                  delivered = true;
                } else if (observed.matched) normal[key + 'observation'] = 'unavailable';
              }
            }
          } finally { await readStep('normal_response_dispose', () => response.dispose()); }
          if (delivered) policy.markNormalReadDelivered(role); // Release only after successful disposal.
        } catch {
          captureFailure(readFailurePhase ?? readPhase);
          policy.failNormalRead(role);
          if (!delivered) normal[key + 'observation'] = 'unavailable';
          throw new Error('The normal read did not complete safely.');
        } finally {
          try {
            if (!delivered) {
              policy.failNormalRead(role);
              await step('normal_abort', () => route.abort('blockedbyclient'));
            }
          } finally { if (activeRead) { normalInFlight.delete(activeRead); finishRead(); } }
        }
      } else if (methodHeaders !== null) {
        let delivered = false;
        try {
          if (shuttingDown || normalAdmissionClosed) throw new Error('Browser preflight is shutting down.');
          if (!methodClientPromise) methodClientPromise = step('method_context', () => playwright.request.newContext({
            ignoreHTTPSErrors: false, proxy: { server: proxy.server, bypass: '' },
          }));
          const client = await methodClientPromise;
          if (shuttingDown || normalAdmissionClosed) throw new Error('Browser preflight is shutting down.');
          methodSendStarted = true;
          const response = await step('method_get', () => client.get(request.url(), { headers: methodHeaders,
            maxRedirects: 0, maxRetries: 0, timeout: 10000 }));
          try {
            const status = await step('method_status', () => {
              const status = response.status();
              if (!Number.isInteger(status) || status < 100 || status > 599) throw new Error('Invalid method status.');
              return status;
            });
            method.method_config_response_observed = true;
            method.method_config_http_status = status;
            if (status >= 300 && status < 400) method.method_config_observation = 'redirect_rejected';
            else if (status !== 200) method.method_config_observation = 'http_rejected';
            else {
              const declared = (await step('method_headers', () => response.headers()))['content-length'];
              if (declared !== undefined && (!/^[0-9]+$/u.test(declared) || Number(declared) > 262144)) {
                method.method_config_observation = 'invalid_response';
              } else {
                const body = await step('method_body', () => response.body());
                method = { ...method, ...policy.observeMethodConfig(body) };
                if (policy.mayDeliverMethodConfig()) {
                  const headers = Object.fromEntries(Object.entries(await step('method_headers', () => response.headers()))
                    .filter(([key]) => key.toLowerCase() !== 'set-cookie'));
                  await step('method_fulfill', () => route.fulfill({ response, headers, body }));
                  method.method_config_payload_delivered = true;
                  delivered = true;
                }
              }
            }
          } finally { await step('method_response_dispose', () => response.dispose()); }
        } finally { if (!delivered) await step('method_abort', () => route.abort('blockedbyclient')); }
      } else if (payerFromMainPage
        && policy.claimPayerRequest(request.url(), request.method(), request.resourceType(),
        request.headers(), request.postData())) {
        observationStarted();
        let delivered = false;
        try {
          // Only the reviewed getter headers survive. Never follow a redirect or replay the GET.
          const response = await route.fetch({ headers: {
            authorization: request.headers().authorization,
            accept: 'application/json, text/plain, */*',
            cookie: '', // Playwright otherwise adds BrowserContext cookies despite the header override.
          }, maxRedirects: 0, maxRetries: 0, timeout: 10000 });
          try {
            const status = response.status();
            if (!Number.isInteger(status) || status < 100 || status > 599) {
              throw new Error('Invalid payer observation status.');
            }
            payer.payer_response_observed = true;
            payer.payer_http_status = status;
            if (status >= 300 && status < 400) {
              payer.payer_observation = 'redirect_rejected';
            } else if (status !== 200) {
              payer.payer_observation = 'http_rejected';
            } else {
              const body = await response.body();
              payer = { ...payer, ...policy.observePayer(body) };
              if (policy.mayDeliverPayer()) {
                policy.startNormalWindow();
                if (observeNormal) normalWindowTimer = setTimeout(expireNormalWindow, policy.normalWindowRemaining());
                await route.fulfill({ response, body });
                policy.markPayerDelivered();
                delivered = true;
              }
            }
          } finally { await response.dispose(); }
        } finally {
          // Default probe always withholds. Explicit rendering can deliver only the verified own payload.
          try { if (!delivered) await route.abort('blockedbyclient'); } finally { observationDone(); }
        }
      } else if (action === 'return') {
        counts.returns_intercepted++;
        await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Returned</title>' });
      } else if (action === 'allow') {
        // continue() can follow redirects without re-running the route handler.
        // Fetch exactly this approved GET once, then deliver only a nonredirect response.
        // The public bootstrap receives no page credentials, cookies or private referrer.
        const headers = controller ? { accept: '*/*', cookie: '', referer: '' } : { ...request.headers() };
        if (!controller) delete headers.referer;
        if (shuttingDown || normalAdmissionClosed) throw new Error('Browser preflight is shutting down.');
        if (controller && !publicClientPromise) {
          // Separate storage prevents even a public Set-Cookie from changing the browser's cookie jar.
          publicClientPromise = playwright.request.newContext({
            ignoreHTTPSErrors: false, proxy: { server: proxy.server, bypass: '' },
          });
        }
        const publicClient = controller ? await publicClientPromise : null;
        if (shuttingDown || normalAdmissionClosed) throw new Error('Browser preflight is shutting down.');
        const options = { headers, maxRedirects: 0, maxRetries: 0, timeout: 10000 };
        const response = controller ? await publicClient.get(request.url(), options) : await route.fetch(options);
        try {
          if (controller) {
            const status = response.status();
            if (!Number.isInteger(status) || status < 100 || status > 599) throw new Error('Invalid public asset status.');
            counts.controller_http_status = status;
          }
          if ((controller && response.status() !== 200) || (response.status() >= 300 && response.status() < 400)) {
            counts.blocked_requests++;
            if (renderFrame) policy.noteRenderCheck('document_redirect');
            await route.abort('blockedbyclient');
          } else {
            const body = await response.body();
            if (body.length > 2 * 1024 * 1024) throw new Error('Browser response exceeds the preflight boundary.');
            if (renderFrame && (response.status() !== 200
                || (fixtureOrigin === null && (!body.includes('window.checkoutVersion="v2.56.0"')
                  || !body.includes('/static/js/vendor.61207a66.js')
                  || !body.includes('/static/js/checkout.d2f9eaef.js'))))) {
              policy.noteRenderCheck('document_http_or_build_mismatch');
              throw new Error('The reviewed read-only frame document was not observed.');
            }
            if (expectedHash !== null) {
              if (!assetMatches(body, expectedHash)) {
                counts.integrity_rejections++;
                await route.abort('blockedbyclient');
                throw new Error('Browser script integrity did not match the reviewed build.');
              }
              if (controller) counts.verified_controller_assets++;
              else if (policy.isRenderAsset(request.url())) counts.verified_render_assets++;
              else counts.verified_static_assets++;
            }
            if (renderFrame) {
              policy.markRenderFrameDelivered();
              boundFrame = request.frame();
            }
            if (controller) {
              const publicHeaders = Object.fromEntries(Object.entries(response.headers())
                .filter(([key]) => key.toLowerCase() !== 'set-cookie'));
              await route.fulfill({ response, headers: publicHeaders, body });
            } else await route.fulfill({ response, body });
            if (renderFrame) {
              counts.bound_render_frames++;
              policy.noteRenderCheck('fulfilled');
            }
          }
        } finally { await response.dispose(); }
      } else {
        counts.blocked_requests++;
        policy.noteDeniedRead(request.url(), request.method(), request.resourceType());
        risk.noteDenied(request.url());
        await route.abort('blockedbyclient');
      }
    }));
    await context.routeWebSocket(/.*/, socket => {
      counts.blocked_websockets++;
      return track(() => socket.close(), 'websocket_close'); // No connectToServer: no remote handshake.
    });
    context.on('page', opened => {
      try {
        opened.on('dialog', dialog => { void track(() => dialog.dismiss(), 'dialog_dismiss'); });
      } catch { markFailure('page_setup'); }
    });
    const page = await step('page_setup', () => context.newPage());
    mainPage = page;
    page.setDefaultTimeout(3000);
    page.setDefaultNavigationTimeout(10000);
    await step('navigation', () => page.goto(initial, { waitUntil: 'domcontentloaded' }));
    if (policy.requestScopeVerified()) {
      let timer;
      await Promise.race([started, new Promise(resolve => { timer = setTimeout(resolve, 5000); })]);
      clearTimeout(timer);
      if (policy.bindingEvidence().payer_get_attempted) await observed;
    }
    if (policy.bindingEvidence().payer_payload_delivered) {
      const delay = observeNormal ? Math.max(0, policy.normalWindowRemaining() - 2500) : 10000;
      await new Promise(resolve => setTimeout(resolve, delay));
    }
    if (observeConfig) {
      readiness = { card_readiness_observed: false };
      if (boundFrame) {
        const detached = stage => {
          try {
            const value = boundFrame.isDetached();
            if (typeof value !== 'boolean') throw new Error('Invalid frame attachment observation.');
            readinessAttachment[stage === 'before_count'
              ? 'readiness_frame_detached_before_count' : 'readiness_frame_detached_after_count'] = value;
            return value;
          } catch { markFailure('readiness_attachment'); throw new Error('Browser preflight failed safely.'); }
        };
        const unavailable = stage => ({ card_readiness_observed: false,
          card_readiness_unavailable_reason: 'frame_detached', readiness_frame_detached: true,
          readiness_detach_stage: stage });
        const observations = {};
        for (const name of ['cardnumber', 'expiry', 'cvc']) {
          readinessAttachment = { readiness_attachment_field: name };
          if (detached('before_count')) { readiness = unavailable('before_count'); break; }
          let phase = `readiness_${name}_locator`;
          let countStarted = false;
          try {
            const field = boundFrame.locator(`input[name="${name}"]`);
            phase = `readiness_${name}_count`;
            countStarted = true;
            const count = await normalStep(() => field.count());
            if (detached('after_count')) { readiness = unavailable('after_count'); break; }
            observations[`${name}_unique`] = count === 1;
            phase = `readiness_${name}_visible`;
            observations[`${name}_visible`] = count === 1 && await normalStep(() => field.isVisible());
            phase = `readiness_${name}_enabled`;
            observations[`${name}_enabled`] = count === 1 && await normalStep(() => field.isEnabled());
            if (detached('after_count')) { readiness = unavailable('after_count'); break; }
          } catch {
            if (detached(countStarted ? 'after_count' : 'before_count')) {
              readiness = unavailable(countStarted ? 'after_count' : 'before_count'); break;
            }
            readiness = { card_readiness_observed: false };
            markFailure(phase);
            throw new Error('Browser preflight failed safely.');
          }
        }
        if (readiness.card_readiness_unavailable_reason === undefined) {
          readiness = { card_readiness_observed: true, ...observations };
        }
      }
    }
    if (observeNormal) {
      normal.normal_read_inspection_completed = readiness.card_readiness_observed === true
        && policy.normalWindowRemaining() > 500;
      normalAdmissionClosed = true; // No new request or late context-construction send after the fixed snapshot.
      await drainSentNormalReads(); // Only already sent reads retain the original <=8s delivery window.
      policy.closeNormalWindow();
    }
    const visible = await step('visible_label', () =>
      page.getByText(/^(test mode|sandbox)$/iu, { exact: true }).first().isVisible());
    if (exercise !== null) await exercise(page, context);
    result = {
      result: fixtureOrigin === null ? 'blocked' : 'pass',
      reason: fixtureOrigin === null ? 'financial_sandbox_boundary_unverified' : 'local_fixture_only',
      navigation_passed: true, test_mode_visible: visible,
      sandbox_origin_verified: false, card_entry_enabled: false,
      ...(policy.candidateOrigin() === null ? {} : { candidate_origin: policy.candidateOrigin() }),
      ...policy.bindingEvidence(), ...payer, ...(observeNormal ? { ...normal, ...policy.normalEvidence() } : {}), ...(observeConfig ? { ...method, ...readiness, ...readinessAttachment, method_config_send_started: methodSendStarted } : {}),
      browser_version: /^[0-9]+(?:\.[0-9]+){1,3}$/u.test(browser.version()) ? browser.version() : 'unclassified',
      ...counts, proxy_rejections: proxy.counts.rejected,
      proxy_connections: proxy.counts.connected,
      proxy_external_connections: proxy.counts.externalConnected,
    };
  } catch {
    markFailure('unclassified');
  } finally {
    if (observeNormal) { normalAdmissionClosed = true; await drainSentNormalReads(); }
    shuttingDown = true;
    policy.closeNormalWindow();
    clearTimeout(normalWindowTimer);
    clearTimeout(watchdog);
    await track(async () => {
      if (!await risk.close()) throw new Error('Risk relay did not close safely.');
    }, 'risk_shutdown');
    if (context) { browserContextCloseStarted = true; await track(() => context.close(), 'shutdown_browser_context'); }
    if (browser) await track(() => browser.close(), 'shutdown_browser');
    for (const promise of [publicClientPromise, methodClientPromise, ...normalClientPromises]) if (promise) await track(async () => {
      const client = await promise;
      const state = normalClientStates.get(promise);
      if (state) state.disposeStarted = true;
      await client.dispose();
      if (state) state.disposed = true;
    }, 'shutdown_request_context');
    await Promise.all([...pending]);
    if (proxy) await track(() => proxy.close(), 'shutdown_proxy');
    await track(() => fs.rm(directory, { recursive: true, force: true }), 'shutdown_directory');
  }
  if (failed || result === undefined) throw new PreflightFailure({
    result: 'fail', reason: 'browser_preflight_failed', card_entry_enabled: false,
    browser_failure_phase: firstFailure ?? 'unclassified', ...counts,
    ...(policy.candidateOrigin() === null ? {} : { candidate_origin: policy.candidateOrigin() }),
    ...policy.bindingEvidence(), ...payer, ...policy.renderEvidence(), ...policy.methodEvidence(),
    ...(observeNormal ? { ...normal, ...policy.normalEvidence() } : {}),
    ...(observeConfig ? { ...method, ...readiness, ...readinessAttachment, method_config_send_started: methodSendStarted } : {}),
    ...risk.evidence(),
  });
  return { ...result, ...counts, ...risk.evidence(), ...(observeNormal ? { ...normal, ...policy.normalEvidence() } : {}), ...policy.renderEvidence(), ...policy.methodEvidence(), proxy_rejections: proxy.counts.rejected,
    proxy_connections: proxy.counts.connected, proxy_external_connections: proxy.counts.externalConnected };
}

async function main() {
  // Only a fixed module location is an argument; the private URL arrives via stdin.
  if (process.argv.length !== 3 || !path.isAbsolute(process.argv[2])) throw new Error('Invalid browser configuration.');
  const chunks = [];
  let bytes = 0;
  for await (const chunk of process.stdin) {
    bytes += chunk.length;
    if (bytes > 20000) throw new Error('Invalid private browser input.');
    chunks.push(chunk);
  }
  const input = JSON.parse(Buffer.concat(chunks).toString('utf8'));
  if (!input || typeof input !== 'object' || Array.isArray(input)
      || !['expected,url', 'expected,render,url', 'expected,observeConfig,render,url', 'expected,observeConfig,observeNormal,render,url', 'expected,observeConfig,observeNormal,observeRisk,render,riskJournal,url'].includes(Object.keys(input).sort().join(','))
      || (Object.hasOwn(input, 'observeRisk') && input.observeRisk !== true)
      || (input.observeRisk === true && input.observeNormal !== true)
      || (Object.hasOwn(input, 'observeNormal') && typeof input.observeNormal !== 'boolean')
      || (Object.hasOwn(input, 'observeConfig') && typeof input.observeConfig !== 'boolean')
      || (Object.hasOwn(input, 'render') && typeof input.render !== 'boolean') || typeof input.url !== 'string'
      || !input.expected || typeof input.expected !== 'object') {
    throw new Error('Invalid private browser input.');
  }
  // Reject non-provider input before requiring any browser module or launching Chrome.
  new HostedPolicy(input.url, null, input.expected, input.render ?? false, input.observeConfig ?? false, input.observeNormal ?? false);
  startupPhase = 'tooling_load';
  const playwright = require(process.argv[2]);
  startupPhase = 'browser_startup';
  const journal = input.observeRisk === true ? RiskJournalClient.fromDescriptors(input.riskJournal) : null;
  const result = await inspect(input.url, playwright, null, null, input.expected, input.render ?? false, input.observeConfig ?? false, input.observeNormal ?? false, journal);
  process.stdout.write(JSON.stringify(result) + '\n');
}

if (require.main === module) {
  main().catch(error => {
    const safe = error instanceof PreflightFailure ? error.evidence() : { result: 'fail',
      reason: 'browser_preflight_failed', card_entry_enabled: false, browser_failure_phase: startupPhase };
    process.stdout.write(JSON.stringify(safe) + '\n');
    process.exitCode = 1;
  });
}
module.exports = { inspect };
