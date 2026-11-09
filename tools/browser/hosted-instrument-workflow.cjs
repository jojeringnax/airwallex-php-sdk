'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { randomBytes } = require('node:crypto');
const { InstrumentReads } = require('./instrument-reads.cjs');
const { InstrumentServices, canonical } = require('./instrument-services.cjs');
const { InstrumentFinancial } = require('./instrument-financial.cjs');
const { InstrumentConfig } = require('./instrument-config.cjs');
const { InstrumentJournal, hash } = require('./instrument-journal.cjs');
const { InstrumentRelay } = require('./instrument-relay.cjs');
const { InstrumentDocuments, installProjection } = require('./instrument-document.cjs');
const { InstrumentActions } = require('./instrument-actions.cjs');
const { egressProxy } = require('./hosted-policy.cjs');
const { parseInstrumentJson } = require('./instrument-json.cjs');

function sourceBook() {
  const names = [
    ...fs
      .readdirSync(__dirname)
      .filter((name) => /^instrument-.*\.cjs$/u.test(name) && !name.includes('.test.')),
    'hosted-instrument-workflow.cjs',
    'hosted-policy.cjs',
    'hosted-risk-policy.cjs'
  ].sort();
  const book = {};
  for (const name of names) {
    const file = path.join(__dirname, name);
    if (!fs.lstatSync(file).isFile()) throw new Error('Instrument source must be regular.');
    book['tools/browser/' + name] = hash(fs.readFileSync(file));
  }
  const entry = path.join(__dirname, '../hosted-instrument-workflow.php');
  if (!fs.lstatSync(entry).isFile()) throw new Error('Instrument entry must be regular.');
  book['tools/hosted-instrument-workflow.php'] = hash(fs.readFileSync(entry));
  return book;
}
function sameBook(book) {
  return JSON.stringify(book) === JSON.stringify(sourceBook());
}
function inputShape(input) {
  if (
    !input ||
    typeof input !== 'object' ||
    Array.isArray(input) ||
    Object.keys(input).sort().join(',') !==
      'buyer_email,context_sha256,expected,journal_directory,ownership_sha256,source_hashes,url' ||
    typeof input.url !== 'string' ||
    input.url.length > 16384 ||
    typeof input.journal_directory !== 'string' ||
    !path.isAbsolute(input.journal_directory) ||
    typeof input.buyer_email !== 'string' ||
    !/^[a-z0-9-]{1,100}@example\.test$/u.test(input.buyer_email) ||
    !/^[a-f0-9]{64}$/u.test(input.context_sha256) ||
    !/^[a-f0-9]{64}$/u.test(input.ownership_sha256) ||
    !input.expected ||
    Object.keys(input.expected).sort().join(',') !== 'checkout_id,currency,mode' ||
    input.expected.mode !== 'SETUP' ||
    input.expected.currency !== 'EUR' ||
    typeof input.expected.checkout_id !== 'string' ||
    !/^[A-Za-z0-9_-]{1,128}$/u.test(input.expected.checkout_id) ||
    !sameBook(input.source_hashes) ||
    Date.now() >= Date.parse('2026-11-15T00:00:00Z')
  ) {
    throw new Error('Explicit fresh instrument configuration is unavailable.');
  }
}
async function facts(frame) {
  return frame.evaluate(() => {
    const offset = new Date().getTimezoneOffset(),
      absolute = Math.abs(offset),
      remainder = absolute % 60;
    return {
      screen_height: screen.height,
      screen_width: screen.width,
      screen_color_depth: screen.colorDepth,
      language: navigator.language,
      timezone: (offset <= 0 ? '+' : '-') + Math.floor(absolute / 60) + (remainder ? ':' + remainder : ''),
      java_enabled: navigator.javaEnabled(),
      user_agent: navigator.userAgent,
      loadingColor: getComputedStyle(document.documentElement)
        .getPropertyValue('--color-icon-interactive')
        .trim()
    };
  });
}
function responseHeaders(response) {
  const output = {};
  for (const [name, value] of Object.entries(response.headers)) {
    if (
      ['content-length', 'content-encoding', 'transfer-encoding', 'connection', 'keep-alive'].includes(name)
    )
      continue;
    output[name] = Array.isArray(value) ? value.join('\n') : value;
  }
  return output;
}
function cosmetic(meta, initial) {
  const url = canonical(meta.url);
  if (!url || meta.method !== 'GET' || meta.body.length || !['image', 'font'].includes(meta.type))
    return false;
  if (['main', 'dropin'].includes(meta.sourceRole)) {
    if (
      url.origin === new URL(initial).origin &&
      !url.search &&
      (url.pathname === '/pay/favicon.ico' ||
        /^\/static\/media\/[A-Za-z0-9_-]+\.[a-f0-9]{8,32}\.(?:svg|png|webp|woff2?)$/u.test(url.pathname) ||
        /^\/pay\/static\/v2\.286\.0\/fonts\/CircularXXWeb-(?:Regular|Medium|Bold)\.[a-f0-9]{20}\.woff2?$/u.test(
          url.pathname
        ))
    )
      return true;
    if (
      url.origin === 'https://checkout.airwallex.com' &&
      !url.search &&
      /^\/fonts\/CircularXXWeb\/CircularXX-(?:Regular|Medium|Bold)\.otf$/u.test(url.pathname)
    )
      return true;
  }
  return (
    meta.sourceRole === 'google' &&
    !url.search &&
    ((url.hostname === 'www.gstatic.com' &&
      /^\/recaptcha\/api2\/logo[A-Za-z0-9_-]*\.(?:png|svg)$/u.test(url.pathname)) ||
      (url.hostname === 'fonts.gstatic.com' &&
        /^\/s\/roboto\/[A-Za-z0-9_./-]{1,200}\.woff2?$/u.test(url.pathname)))
  );
}
function monitoring(meta) {
  const url = canonical(meta.url);
  if (!url || !['main', 'dropin'].includes(meta.sourceRole)) return false;
  if (
    meta.sourceRole === 'main' &&
    meta.method === 'GET' &&
    meta.type === 'script' &&
    meta.body.length === 0 &&
    meta.url === 'https://www.googletagmanager.com/gtag/js?id=G-QGE5H07VBD'
  )
    return true;
  return (
    meta.method === 'POST' &&
    ['fetch', 'xhr', 'ping'].includes(meta.type) &&
    meta.body.length <= 65536 &&
    [
      'https://o11y.sandbox.airwallex.com/airtracker/logs',
      'https://o11y.sandbox.airwallex.com/airtracker/metrics'
    ].includes(meta.url)
  );
}

async function runInstrument(input, playwright, fixture = null) {
  inputShape(input);
  const start = performance.now(),
    ceiling = fixture?.budget ?? 180000;
  if (!Number.isFinite(ceiling) || ceiling < 3000 || ceiling > 180000)
    throw new Error('Invalid local fixture budget.');
  const admission = start + Math.min(150000, ceiling - 2000),
    release = start + Math.min(170000, ceiling - 1000),
    end = start + ceiling;
  const reads =
    fixture?.makeReads?.(input.url, input.expected, admission) ??
    new InstrumentReads(input.url, input.expected, admission);
  const services = fixture?.makeServices?.(reads) ?? new InstrumentServices(reads);
  const configuration = new InstrumentConfig(reads);
  const journal = new InstrumentJournal(input.journal_directory, {
    profile: 'bounded_hosted_instrument_v1',
    context_sha256: input.context_sha256,
    ownership_sha256: input.ownership_sha256,
    source_hashes: input.source_hashes,
    expected: input.expected
  });
  const relay = fixture?.relay ?? new InstrumentRelay();
  let stopped = false,
    firstFailure,
    phase = 'startup',
    shuttingDown = false,
    completed = false;
  let browser,
    context,
    page,
    documents,
    actions,
    financial,
    dropin,
    proxy,
    projection = { blocked: false, disabled: true };
  let worker,
    workerClosed = false,
    workerExpected = null,
    pointerFacts;
  let http = 0,
    withheldCosmetic = 0,
    withheldMonitoring = 0,
    directAttempts = 0,
    withheldTag = 0,
    withheldTracker = 0;
  const routes = new Set(),
    marker = '__awx_instrument_' + randomBytes(16).toString('hex');
  let wake, finish;
  const stoppedPromise = new Promise((resolve) => {
      wake = resolve;
    }),
    completedPromise = new Promise((resolve) => {
      finish = resolve;
    });
  const stop = (reason) => {
    if (!stopped && !completed) {
      stopped = true;
      firstFailure = reason;
      reads.base.stop();
      wake();
    }
  };
  const code = () => sameBook(input.source_hashes);
  const baseLive = () =>
    !stopped &&
    !shuttingDown &&
    performance.now() < release &&
    reads.base.requestScopeVerified() &&
    code() &&
    journal.healthy();
  const actionLive = () =>
    baseLive() &&
    performance.now() < admission &&
    documents?.live() &&
    !documents.pendingFrames() &&
    !projection.blocked;
  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  async function waitFor(check, deadline) {
    while (!check()) {
      if (!baseLive() || performance.now() >= deadline) throw new Error('Original wait window ended.');
      await sleep(Math.max(1, Math.min(20, deadline - performance.now())));
    }
  }
  async function projectionCheck() {
    if (!dropin) return;
    // Its original HTTP document must be released before its init script can establish a projection.
    if (phase === 'bootstrap' && !documents.entry(dropin)?.initial) return;
    await documents.assert(dropin);
    const value = await dropin.evaluate(
      (key) => (typeof window[key] === 'function' ? window[key]() : null),
      marker
    );
    if (
      !value ||
      typeof value.blocked !== 'boolean' ||
      typeof value.disabled !== 'boolean' ||
      !Number.isSafeInteger(value.updates) ||
      value.blocked
    ) {
      stop('document');
      throw new Error('Source update is unsupported.');
    }
    projection = value;
  }
  async function hidden(frame) {
    const element = await frame.frameElement();
    try {
      return await element.evaluate((node) => {
        const style = getComputedStyle(node),
          rect = node.getBoundingClientRect();
        return (
          style.display === 'none' ||
          style.visibility === 'hidden' ||
          rect.width === 0 ||
          rect.height === 0 ||
          rect.bottom <= 0 ||
          rect.right <= 0 ||
          rect.top >= innerHeight ||
          rect.left >= innerWidth
        );
      });
    } finally {
      await element.dispose();
    }
  }
  async function challengeCheck() {
    if (!documents) return;
    for (const frame of page.frames()) {
      if (documents.role(frame) === 'tencent' && !(await hidden(frame))) {
        stop('dependency');
        throw new Error('Interactive work is withheld.');
      }
    }
  }
  async function scoped(meta) {
    if (!baseLive() || !documents.live()) throw new Error('Original source scope ended.');
    if (meta.initial) return;
    if (['input', 'submit'].includes(phase) && documents.pendingFrames()) {
      await waitFor(() => !documents.pendingFrames(), Math.min(admission, performance.now() + 500));
    }
    const frame = meta.initiatorFrame;
    if (frame) await documents.assert(frame);
    await documents.assert(page.mainFrame());
    await projectionCheck();
    await challengeCheck();
    if (!baseLive() || !documents.live()) throw new Error('Original source scope ended.');
  }
  async function execute(claim, owner, meta) {
    await scoped(meta);
    if (performance.now() >= admission || http >= 240) throw new Error('New admission ended.');
    const preflight = owner.preflightFor?.(claim);
    if (preflight) await execute(preflight, owner, meta);
    await scoped(meta);
    if (performance.now() >= admission || http >= 240) throw new Error('New admission ended.');
    const action = journal.prepare(claim);
    http++;
    if (journal.evidence().possible_financial_send) reads.financialStarted?.();
    const live = (stage) =>
      baseLive() &&
      documents.live() &&
      !projection.blocked &&
      (!['input', 'submit'].includes(phase) || !documents.pendingFrames()) &&
      journal.check(claim, action) &&
      performance.now() < (stage === 'admit' ? admission : release);
    let response;
    try {
      response = await relay.send(claim, release, live, () => scoped(meta));
    } catch {
      stop('transport');
      throw new Error('Native transport failed.');
    }
    let identities,
      matched = false;
    try {
      if (!Buffer.isBuffer(response.body) || response.body.length > claim.responseLimit) throw new Error();
      identities = owner.validate(claim, response);
      matched = true;
    } catch {
      identities = owner.candidates?.(claim, response) ?? {};
    }
    // A received identity survives even when later scope/validation prevents browser delivery.
    journal.observed(claim, action, response, identities, matched);
    if (!matched) {
      stop('response');
      throw new Error('Response proof is unavailable.');
    }
    owner.durable?.(claim, identities);
    await scoped(meta);
    return response;
  }
  async function frameMetadata(request) {
    const frame = request.frame(),
      url = request.url(),
      method = request.method(),
      type = request.resourceType();
    const body = request.postDataBuffer() ?? Buffer.alloc(0),
      headers = await request.allHeaders();
    const meta = {
      frame,
      initiatorFrame: frame,
      url,
      method,
      type,
      body,
      headers,
      sourceRole: documents.role(frame),
      sourceURL: documents.entry(frame)?.inheritedSource ?? frame.url(),
      parentRole: documents.role(frame.parentFrame())
    };
    if (type === 'document' && frame !== page.mainFrame()) {
      const parent = frame.parentFrame();
      if (!parent || !documents.entry(parent)) throw new Error('Original parent is unavailable.');
      await documents.assert(parent);
      const element = await frame.frameElement();
      try {
        const info = await element.evaluate((node) => ({
          id: node.id,
          src: node.src,
          height: node.height,
          width: node.width,
          hidden: getComputedStyle(node).visibility === 'hidden'
        }));
        Object.assign(meta, {
          elementId: info.id,
          sourceRole: documents.role(parent),
          sourceURL: parent.url(),
          initiatorFrame: parent,
          parentRole: documents.role(parent),
          hidden: await hidden(frame)
        });
        if (meta.parentRole === 'main' && reads.base.renderRequestTarget(url)) {
          if (dropin) throw new Error('Drop-in cannot be replaced.');
          reads.bindFrame(info.src);
          documents.expect(frame, 'dropin', info.src);
          dropin = frame;
          return { meta, frameDocument: true, role: 'dropin_document' };
        }
      } finally {
        await element.dispose();
      }
    }
    if (!meta.sourceRole && frame.url() === 'about:blank' && meta.parentRole === 'risk') {
      const element = await frame.frameElement();
      try {
        const proof = await element.evaluate(
          (node) =>
            node.tagName === 'IFRAME' &&
            !node.getAttribute('src') &&
            node.height === '1' &&
            node.width === '1' &&
            getComputedStyle(node).visibility === 'hidden'
        );
        if (!proof) throw new Error('Passive child is unbound.');
        documents.expect(frame, 'cf', 'about:blank', frame.parentFrame().url());
        meta.sourceRole = 'cf';
        meta.sourceURL = frame.parentFrame().url();
        meta.initiatorFrame = frame;
      } finally {
        await element.dispose();
      }
    }
    if (services.session && reads.options && services.session !== reads.options.sessionId)
      throw new Error('SDK session changed.');
    if (meta.sourceRole === 'google') {
      if (workerExpected && !worker)
        await waitFor(() => Boolean(worker), Math.min(admission, performance.now() + 500));
      meta.workerBound = Boolean(worker && !workerClosed);
      meta.workerURL = workerExpected;
      // Public Playwright retains the anchor as the request frame. Exact worker referrer/history
      // qualifies this relationship; it does not establish exact per-request Worker identity.
      meta.worker = Boolean(workerExpected && meta.headers.referer === workerExpected);
    }
    return { meta };
  }
  let financialChain = Promise.resolve();
  async function handle(route) {
    if (!baseLive() || completed) {
      await route.abort().catch(() => {});
      return;
    }
    const request = route.request();
    if (!canonical(request.url())) {
      stop('dependency');
      await route.abort();
      return;
    }
    const { meta, frameDocument, role } = await frameMetadata(request);
    if (monitoring(meta)) {
      withheldMonitoring++;
      if (
        meta.url.startsWith('https://www.googletagmanager.com/') ? ++withheldTag > 1 : ++withheldTracker > 32
      )
        stop('dependency');
      await route.abort();
      return;
    }
    if (cosmetic(meta, input.url)) {
      if (++withheldCosmetic > 48) stop('dependency');
      await route.abort();
      return;
    }
    let claim, owner, newFrameRole;
    if (
      request.isNavigationRequest() &&
      request.frame() === page.mainFrame() &&
      meta.url === input.url &&
      meta.method === 'GET' &&
      meta.body.length === 0 &&
      http === 0
    ) {
      claim = {
        role: 'main_document',
        ordinal: 0,
        method: 'GET',
        url: meta.url,
        body: meta.body,
        headers: { accept: meta.headers.accept ?? 'text/html', 'user-agent': meta.headers['user-agent'] },
        scope: hash(input.url),
        responseLimit: 1048576,
        security: false
      };
      owner = {
        validate: (_claim, response) => {
          if (
            response.status !== 200 ||
            !/^text\/html(?:\s*;|$)/iu.test(response.headers['content-type'] ?? '')
          )
            throw new Error();
          return {};
        }
      };
      // Before this original commit there is no original main Document to compare.
      meta.initiatorFrame = null;
      meta.initial = true;
    } else if (frameDocument) {
      claim = {
        role,
        ordinal: 0,
        method: 'GET',
        url: meta.url,
        body: meta.body,
        headers: { accept: 'text/html' },
        scope: hash(input.url),
        responseLimit: 1048576,
        security: false
      };
      owner = {
        validate: (_claim, response) => {
          if (
            response.status !== 200 ||
            !/^text\/html(?:\s*;|$)/iu.test(response.headers['content-type'] ?? '')
          )
            throw new Error();
          return {};
        }
      };
    } else {
      if (
        services.session === undefined &&
        meta.url.startsWith('https://static.sandbox.airwallex.com/webapp/fraud/device-fingerprint/index.js?')
      ) {
        await waitFor(() => Boolean(dropin && reads.options), Math.min(admission, performance.now() + 2000));
        await documents.assert(meta.frame);
        meta.loaderProof = await meta.frame.evaluate((target) => {
          const nodes = [...document.querySelectorAll('script#airwallex-fraud-api')].filter(
            (node) => node.src === target
          );
          if (nodes.length !== 1) return null;
          const node = nodes[0];
          return {
            id: node.id,
            session: node.getAttribute('data-order-session-id'),
            orderSession: node.getAttribute('data-order-session-id'),
            disableTracking: node.getAttribute('data-disable-3rd-tracking'),
            merchant: node.getAttribute('data-merchant-id'),
            tenant: node.getAttribute('data-tenant') ?? node.getAttribute('tenant'),
            project: node.getAttribute('data-project-name')
          };
        }, meta.url);
        if (!meta.loaderProof) throw new Error('Original loader element is unavailable.');
        Object.assign(meta.loaderProof, { document: meta.sourceURL, role: meta.sourceRole });
        services.beforeCookies(await context.cookies());
      }
      if (meta.url.startsWith('https://bws.sandbox.airwallex.com/')) {
        if (!services.bindCookies(await context.cookies()))
          throw new Error('Fresh BWS cookies are unavailable.');
      }
      claim = reads.claim(meta);
      owner = reads;
      if (!claim) {
        claim = configuration.claim(meta);
        owner = configuration;
      }
      if (!claim) {
        claim = services.claim(meta);
        owner = services;
      }
      if (claim?.role === 'risk_document') newFrameRole = 'risk';
      if (claim?.role === 'google_anchor') newFrameRole = 'google';
      if (claim?.role === 'tencent_preload') newFrameRole = 'tencent';
      if (newFrameRole) documents.expect(meta.frame, newFrameRole, meta.url);
      if (claim?.role === 'google_worker_initial') workerExpected = meta.url;
      if (!claim && financial) {
        if (/\/payment_consents\/[^/]+\/verify$/u.test(new URL(meta.url).pathname)) {
          await waitFor(() => financial.captchaReady, Math.min(admission, performance.now() + 2000));
        }
        claim = financial.claim(meta);
        owner = financial;
      }
    }
    if (!claim) {
      fixture?.onFailure?.(new Error('Unlisted fixture request.'), meta.url);
      stop('dependency');
      await route.abort();
      return;
    }
    const perform = async () => {
      const response = await execute(claim, owner, meta);
      if (!baseLive() || performance.now() >= release) throw new Error('Response release ended.');
      await route.fulfill({
        status: response.status,
        headers: responseHeaders(response),
        body: response.body
      });
      owner.delivered?.(claim);
      if (role === 'dropin_document') reads.frameDelivered();
      if (claim.role === 'complete') {
        completed = true;
        finish();
      }
    };
    if (owner === financial) {
      const task = financialChain.then(perform);
      financialChain = task.catch(() => {});
      await task;
    } else await perform();
  }
  let cleanup = {
    transport: false,
    routes: false,
    actions: true,
    documents: false,
    context: false,
    browser: false,
    proxy: false
  };
  let signal, watchdog;
  try {
    watchdog = setTimeout(
      () => {
        stop('deadline');
        void relay.close().catch(() => {});
        if (context) void context.close().catch(() => {});
        if (browser) void browser.close().catch(() => {});
      },
      Math.max(1, release - performance.now())
    );
    proxy = await egressProxy(
      {
        allowsHttp: () => {
          if (++directAttempts > 96) stop('dependency');
          return false;
        },
        allowsAuthority: () => {
          directAttempts++;
          // Chrome can originate background connection attempts without a document request.
          // The proxy forwards none; source requests still require an intercepted finite claim.
          if (directAttempts > 96) stop('dependency');
          return false;
        }
      },
      true
    );
    browser = await playwright.chromium.launch({
      channel: 'chrome',
      headless: true,
      timeout: Math.max(1, Math.min(30000, admission - performance.now())),
      proxy: { server: proxy.server },
      args: [
        '--disable-quic',
        '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
        '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1',
        '--proxy-bypass-list=<-loopback>'
      ]
    });
    if (!/^157\./u.test(browser.version())) throw new Error('Reviewed Chrome is required.');
    context = await browser.newContext({
      ignoreHTTPSErrors: false,
      serviceWorkers: 'block',
      viewport: { width: 1440, height: 1600 },
      locale: 'en-US'
    });
    if (typeof context.routeWebSocket !== 'function')
      throw new Error('WebSocket containment is unavailable.');
    await context.routeWebSocket('**/*', (route) => {
      stop('dependency');
      void route.close().catch(() => {});
    });
    const stopBinding = marker + '_stop';
    await context.exposeBinding(stopBinding, () => stop('document'));
    await context.addInitScript(installProjection, {
      marker,
      origin: new URL(input.url).origin,
      buyerEmail: input.buyer_email,
      stopBinding
    });
    page = await context.newPage();
    documents = new InstrumentDocuments(
      page,
      release,
      (reason) => {
        if (!financial?.complete) stop(reason);
      },
      () => journal.evidence().unresolved_actions
    );
    documents.expect(page.mainFrame(), 'main', input.url);
    page.on('popup', (popup) => {
      stop('dependency');
      void popup.close().catch(() => {});
    });
    page.on('pageerror', (error) => {
      fixture?.onFailure?.(error, 'synthetic page');
      stop('dependency');
    });
    page.on('worker', (current) => {
      if (worker || !workerExpected || current.url() !== workerExpected) {
        fixture?.onFailure?.(new Error('Worker relationship unavailable.'), current.url());
        stop('dependency');
        return;
      }
      worker = current;
      current.on('close', () => {
        workerClosed = true;
        if (!shuttingDown && !completed) stop('document');
      });
    });
    services.beforeCookies(await context.cookies());
    await context.route('**/*', (route) => {
      const task = handle(route).catch((error) => {
        fixture?.onFailure?.(error, route.request().url());
        stop('dependency');
        return route.abort().catch(() => {});
      });
      routes.add(task);
      void task.finally(() => routes.delete(task));
      return task;
    });
    signal = () => stop('shutdown');
    process.once('SIGTERM', signal);
    process.once('SIGINT', signal);
    phase = 'bootstrap';
    await page.goto(input.url, {
      waitUntil: 'domcontentloaded',
      timeout: Math.max(1, Math.min(30000, admission - performance.now()))
    });
    await waitFor(() => Boolean(dropin && reads.options && documents.entry(dropin)?.capture), admission);
    await documents.assert(dropin);
    await projectionCheck();
    pointerFacts = await facts(dropin);
    financial = new InstrumentFinancial(reads, services, input.buyer_email, actionLive, pointerFacts);
    await waitFor(
      () =>
        configuration.ready() &&
        services.bootstrapReady() &&
        reads.ready(dropin.url()) &&
        !documents.pendingFrames(),
      admission
    );
    await projectionCheck();
    Object.assign(pointerFacts, await facts(dropin));
    if (
      !(await page
        .getByText(/^(test mode|sandbox)$/iu, { exact: true })
        .first()
        .isVisible())
    )
      throw new Error('Original Sandbox label is unavailable.');
    phase = 'input';
    actions = new InstrumentActions(
      page,
      dropin,
      documents,
      marker,
      financial,
      journal,
      actionLive,
      admission
    );
    await actions.chooseCard();
    await actions.capture();
    await actions.input();
    phase = 'submit';
    await actions.submit();
    let waitTimer;
    try {
      await Promise.race([
        completedPromise,
        stoppedPromise,
        new Promise((resolve) => {
          waitTimer = setTimeout(resolve, Math.max(1, release - performance.now()));
        })
      ]);
    } finally {
      clearTimeout(waitTimer);
    }
    if (!completed && !stopped) stop('deadline');
  } catch {
    stop(['startup', 'bootstrap', 'input', 'submit'].includes(phase) ? phase : 'dependency');
  } finally {
    clearTimeout(watchdog);
    shuttingDown = true;
    reads.base.stop();
    if (signal) {
      process.removeListener('SIGTERM', signal);
      process.removeListener('SIGINT', signal);
    }
    const shutdownDeadline = Math.min(end, performance.now() + 10000);
    const close = async (operation) => {
      let timer;
      try {
        return (
          (await Promise.race([
            Promise.resolve().then(operation),
            new Promise((resolve) => {
              timer = setTimeout(() => resolve(false), Math.max(1, shutdownDeadline - performance.now()));
            })
          ])) !== false
        );
      } catch {
        return false;
      } finally {
        clearTimeout(timer);
      }
    };
    cleanup.transport = await close(() => relay.close());
    cleanup.routes = await close(() => Promise.all([...routes]));
    if (actions) cleanup.actions = await close(() => actions.close());
    if (documents) cleanup.documents = await close(() => documents.close());
    if (context) cleanup.context = await close(() => context.close());
    if (browser) cleanup.browser = await close(() => browser.close());
    if (proxy) cleanup.proxy = await close(() => proxy.close());
  }
  const evidence = journal.evidence(),
    cleanJournal = journal.close();
  const clean = Object.values(cleanup).every((value) => value === true);
  return {
    result: completed && clean && cleanJournal && code() ? 'completed' : 'fail',
    phase: firstFailure ?? (completed ? 'complete' : phase),
    http_invocations: http,
    withheld_cosmetic: withheldCosmetic,
    withheld_monitoring: withheldMonitoring,
    direct_connection_attempts: directAttempts,
    direct_connections_forwarded: proxy?.counts.connected ?? 0,
    ...services.evidence(),
    ...configuration.evidence(),
    journal_records: evidence.journal_records,
    possible_financial_send: evidence.possible_financial_send,
    unresolved_actions: evidence.unresolved_actions,
    journal_closed_cleanly: cleanJournal,
    cleanup_complete: clean,
    security_graph_complete: false,
    ...(actions?.evidence() ?? {})
  };
}

async function main() {
  if (process.argv.length === 3 && process.argv[2] === '--source-book') {
    process.stdout.write(JSON.stringify(sourceBook()) + '\n');
    return;
  }
  if (process.argv.length !== 3 || !path.isAbsolute(process.argv[2]))
    throw new Error('Explicit tooling is required.');
  const chunks = [];
  let bytes = 0;
  for await (const chunk of process.stdin) {
    bytes += chunk.length;
    if (bytes > 32768) throw new Error('Private input is too large.');
    chunks.push(chunk);
  }
  const input = parseInstrumentJson(Buffer.concat(chunks));
  inputShape(input);
  process.stdin.destroy();
  const result = await runInstrument(input, require(process.argv[2]));
  process.stdout.write(JSON.stringify(result) + '\n');
  process.exitCode = result.result === 'completed' ? 0 : 1;
}
if (require.main === module)
  main().catch(() => {
    process.stdout.write(
      JSON.stringify({ result: 'fail', phase: 'configuration', security_graph_complete: false }) + '\n'
    );
    process.exitCode = 1;
  });
module.exports = { runInstrument, sourceBook, inputShape, facts, cosmetic, monitoring };
