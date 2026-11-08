'use strict';

// Never enable Playwright/browser debug channels or inherit application credentials.
for (const key of ['DEBUG', 'PWDEBUG', 'DEBUG_FILE', 'CHROME_LOG_FILE']) delete process.env[key];
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const { HostedPolicy, egressProxy, assetMatches } = require('./hosted-policy.cjs');

// Contains only evidence constructed from fixed local labels/counts, never a caught Error.
class PreflightFailure extends Error {
  #safe;
  constructor(safe) { super('Browser preflight failed safely.'); this.#safe = Object.freeze(safe); }
  evidence() { return { ...this.#safe }; }
}
let startupPhase = 'configuration';

async function inspect(initial, playwright, fixtureOrigin = null, exercise = null, expected = null, render = false, observeConfig = false) {
  const policy = new HostedPolicy(initial, fixtureOrigin, expected, render, observeConfig);
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'airwallex-browser-preflight-'));
  await fs.chmod(directory, 0o700);
  let proxy;
  let browser;
  let context;
  let mainPage;
  let publicClientPromise;
  let methodClientPromise;
  let boundFrame;
  let shuttingDown = false;
  let result;
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
  const watchdog = setTimeout(() => {
    markFailure('watchdog');
    shuttingDown = true;
    if (context) void track(() => context.close());
    if (browser) void track(() => browser.close());
  }, render ? 40000 : 20000);
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
    proxy = await step('proxy_start', () => egressProxy(policy));
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
      if (methodHeaders !== null) {
        let delivered = false;
        try {
          if (shuttingDown) throw new Error('Browser preflight is shutting down.');
          if (!methodClientPromise) methodClientPromise = step('method_context', () => playwright.request.newContext({
            ignoreHTTPSErrors: false, proxy: { server: proxy.server, bypass: '' },
          }));
          const client = await methodClientPromise;
          if (shuttingDown) throw new Error('Browser preflight is shutting down.');
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
        if (shuttingDown) throw new Error('Browser preflight is shutting down.');
        if (controller && !publicClientPromise) {
          // Separate storage prevents even a public Set-Cookie from changing the browser's cookie jar.
          publicClientPromise = playwright.request.newContext({
            ignoreHTTPSErrors: false, proxy: { server: proxy.server, bypass: '' },
          });
        }
        const publicClient = controller ? await publicClientPromise : null;
        if (shuttingDown) throw new Error('Browser preflight is shutting down.');
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
    if (policy.bindingEvidence().payer_payload_delivered) await new Promise(resolve => setTimeout(resolve, 10000));
    let readiness = {};
    if (observeConfig) {
      readiness = { card_readiness_observed: false };
      if (boundFrame) {
        for (const name of ['cardnumber', 'expiry', 'cvc']) {
          const field = await step(`readiness_${name}_locator`, () => boundFrame.locator(`input[name="${name}"]`));
          const count = await step(`readiness_${name}_count`, () => field.count());
          readiness[`${name}_unique`] = count === 1;
          readiness[`${name}_visible`] = count === 1 && await step(`readiness_${name}_visible`, () => field.isVisible());
          readiness[`${name}_enabled`] = count === 1 && await step(`readiness_${name}_enabled`, () => field.isEnabled());
        }
        readiness.card_readiness_observed = true;
      }
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
      ...policy.bindingEvidence(), ...payer, ...(observeConfig ? { ...method, ...readiness } : {}),
      browser_version: /^[0-9]+(?:\.[0-9]+){1,3}$/u.test(browser.version()) ? browser.version() : 'unclassified',
      ...counts, proxy_rejections: proxy.counts.rejected,
      proxy_connections: proxy.counts.connected,
      proxy_external_connections: proxy.counts.externalConnected,
    };
  } catch {
    markFailure('unclassified');
  } finally {
    shuttingDown = true;
    clearTimeout(watchdog);
    if (context) await track(() => context.close(), 'shutdown_browser_context');
    if (browser) await track(() => browser.close(), 'shutdown_browser');
    for (const promise of [publicClientPromise, methodClientPromise]) if (promise) await track(async () => {
      const client = await promise;
      await client.dispose();
    }, 'shutdown_request_context');
    await Promise.all([...pending]);
    if (proxy) await track(() => proxy.close(), 'shutdown_proxy');
    await track(() => fs.rm(directory, { recursive: true, force: true }), 'shutdown_directory');
  }
  if (failed || result === undefined) throw new PreflightFailure({
    result: 'fail', reason: 'browser_preflight_failed', card_entry_enabled: false,
    browser_failure_phase: firstFailure ?? 'unclassified', ...counts,
    ...policy.renderEvidence(), ...policy.methodEvidence(),
    ...(payer.payer_http_status === undefined ? {} : { payer_http_status: payer.payer_http_status }),
    ...(method.method_config_http_status === undefined ? {} : { method_config_http_status: method.method_config_http_status }),
    ...(observeConfig ? { method_config_send_started: methodSendStarted } : {}),
  });
  return { ...result, ...counts, ...policy.renderEvidence(), ...policy.methodEvidence(), proxy_rejections: proxy.counts.rejected,
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
      || !['expected,url', 'expected,render,url', 'expected,observeConfig,render,url'].includes(Object.keys(input).sort().join(','))
      || (Object.hasOwn(input, 'observeConfig') && typeof input.observeConfig !== 'boolean')
      || (Object.hasOwn(input, 'render') && typeof input.render !== 'boolean') || typeof input.url !== 'string'
      || !input.expected || typeof input.expected !== 'object') {
    throw new Error('Invalid private browser input.');
  }
  // Reject non-provider input before requiring any browser module or launching Chrome.
  new HostedPolicy(input.url, null, input.expected, input.render ?? false, input.observeConfig ?? false);
  startupPhase = 'tooling_load';
  const playwright = require(process.argv[2]);
  startupPhase = 'browser_startup';
  const result = await inspect(input.url, playwright, null, null, input.expected, input.render ?? false, input.observeConfig ?? false);
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
