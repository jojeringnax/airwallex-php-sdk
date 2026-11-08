'use strict';

// Never enable Playwright/browser debug channels or inherit application credentials.
for (const key of ['DEBUG', 'PWDEBUG', 'DEBUG_FILE', 'CHROME_LOG_FILE']) delete process.env[key];
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const { HostedPolicy, egressProxy, assetMatches } = require('./hosted-policy.cjs');

async function inspect(initial, playwright, fixtureOrigin = null, exercise = null, expected = null) {
  const policy = new HostedPolicy(initial, fixtureOrigin, expected);
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'airwallex-browser-preflight-'));
  await fs.chmod(directory, 0o700);
  let proxy;
  let browser;
  let context;
  let result;
  let failed = false;
  const pending = new Set();
  const track = action => {
    const safe = Promise.resolve().then(action).catch(() => { failed = true; });
    pending.add(safe);
    void safe.then(() => pending.delete(safe));
    return safe;
  };
  const watchdog = setTimeout(() => {
    failed = true;
    if (context) void track(() => context.close());
    if (browser) void track(() => browser.close());
  }, 20000);
  watchdog.unref();
  const counts = { blocked_requests: 0, blocked_websockets: 0, returns_intercepted: 0,
    verified_static_assets: 0, integrity_rejections: 0 };
  let observationDone;
  const observed = new Promise(resolve => { observationDone = resolve; });
  let observationStarted;
  const started = new Promise(resolve => { observationStarted = resolve; });
  let payer = { payer_observation: 'not_observed', payer_response_observed: false,
    payer_mode_matches: false, payer_currency_matches: false };
  try {
    proxy = await egressProxy(policy);
    browser = await playwright.chromium.launch({
      channel: 'chrome', headless: true, timeout: 15000,
      proxy: { server: proxy.server, bypass: '' },
      env: { PATH: process.env.PATH ?? '', LANG: 'en_US.UTF-8', TMPDIR: directory },
      args: ['--proxy-bypass-list=<-loopback>', '--disable-quic',
        '--force-webrtc-ip-handling-policy=disable_non_proxied_udp', '--disable-breakpad'],
    });
    context = await browser.newContext({
      ignoreHTTPSErrors: false, serviceWorkers: 'block', acceptDownloads: false,
      permissions: [],
    });
    await context.addInitScript(() => {
      for (const name of ['Worker', 'SharedWorker']) {
        Object.defineProperty(globalThis, name, {
          value: class { constructor() { throw new Error('Workers are disabled in this preflight.'); } },
          writable: false, configurable: false,
        });
      }
    });
    await context.route('**/*', route => track(async () => {
      const request = route.request();
      const action = policy.decide(request.url(), request.method(), request.resourceType());
      if (policy.claimPayerRequest(request.url(), request.method(), request.resourceType(),
        request.headers(), request.postData())) {
        observationStarted();
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
              payer = { ...payer, ...policy.observePayer(await response.body()) };
            }
          } finally { await response.dispose(); }
        } finally {
          // Even a matching payload is withheld: renderer/collection approval is a separate step.
          try { await route.abort('blockedbyclient'); } finally { observationDone(); }
        }
      } else if (action === 'return') {
        counts.returns_intercepted++;
        await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Returned</title>' });
      } else if (action === 'allow') {
        // continue() can follow redirects without re-running the route handler.
        // Fetch exactly this approved GET once, then deliver only a nonredirect response.
        const headers = { ...request.headers() };
        delete headers.referer;
        const response = await route.fetch({
          headers, maxRedirects: 0, maxRetries: 0, timeout: 10000,
        });
        try {
          if (response.status() >= 300 && response.status() < 400) {
            counts.blocked_requests++;
            await route.abort('blockedbyclient');
          } else {
            const body = await response.body();
            if (body.length > 2 * 1024 * 1024) throw new Error('Browser response exceeds the preflight boundary.');
            const expected = policy.assetHash(request.url(), request.method(), request.resourceType());
            if (expected !== null) {
              if (!assetMatches(body, expected)) {
                counts.integrity_rejections++;
                await route.abort('blockedbyclient');
                throw new Error('Browser script integrity did not match the reviewed build.');
              }
              counts.verified_static_assets++;
            }
            await route.fulfill({ response, body });
          }
        } finally { await response.dispose(); }
      } else {
        counts.blocked_requests++;
        await route.abort('blockedbyclient');
      }
    }));
    await context.routeWebSocket(/.*/, socket => {
      counts.blocked_websockets++;
      return track(() => socket.close()); // No connectToServer: no remote handshake.
    });
    context.on('page', opened => {
      try {
        opened.on('dialog', dialog => { void track(() => dialog.dismiss()); });
      } catch { failed = true; }
    });
    const page = await context.newPage();
    page.setDefaultTimeout(3000);
    page.setDefaultNavigationTimeout(10000);
    await page.goto(initial, { waitUntil: 'domcontentloaded' });
    if (policy.requestScopeVerified()) {
      let timer;
      await Promise.race([started, new Promise(resolve => { timer = setTimeout(resolve, 5000); })]);
      clearTimeout(timer);
      if (policy.bindingEvidence().payer_get_attempted) await observed;
    }
    const visible = await page.getByText(/^(test mode|sandbox)$/iu, { exact: true }).first().isVisible();
    if (exercise !== null) await exercise(page, context);
    result = {
      result: fixtureOrigin === null ? 'blocked' : 'pass',
      reason: fixtureOrigin === null ? 'financial_sandbox_boundary_unverified' : 'local_fixture_only',
      navigation_passed: true, test_mode_visible: visible,
      sandbox_origin_verified: false, card_entry_enabled: false,
      ...(policy.candidateOrigin() === null ? {} : { candidate_origin: policy.candidateOrigin() }),
      ...policy.bindingEvidence(), ...payer,
      browser_version: /^[0-9]+(?:\.[0-9]+){1,3}$/u.test(browser.version()) ? browser.version() : 'unclassified',
      ...counts, proxy_rejections: proxy.counts.rejected,
      proxy_connections: proxy.counts.connected,
      proxy_external_connections: proxy.counts.externalConnected,
    };
  } catch {
    failed = true;
  } finally {
    clearTimeout(watchdog);
    if (context) await track(() => context.close());
    if (browser) await track(() => browser.close());
    await Promise.all([...pending]);
    if (proxy) await track(() => proxy.close());
    await track(() => fs.rm(directory, { recursive: true, force: true }));
  }
  if (failed || result === undefined) throw new Error('Browser preflight failed safely.');
  return { ...result, ...counts, proxy_rejections: proxy.counts.rejected,
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
      || Object.keys(input).sort().join(',') !== 'expected,url' || typeof input.url !== 'string'
      || !input.expected || typeof input.expected !== 'object') {
    throw new Error('Invalid private browser input.');
  }
  // Reject non-provider input before requiring any browser module or launching Chrome.
  new HostedPolicy(input.url, null, input.expected);
  const result = await inspect(input.url, require(process.argv[2]), null, null, input.expected);
  process.stdout.write(JSON.stringify(result) + '\n');
}

if (require.main === module) {
  main().catch(() => {
    process.stdout.write('{"result":"fail","reason":"browser_preflight_failed","card_entry_enabled":false}\n');
    process.exitCode = 1;
  });
}
module.exports = { inspect };
