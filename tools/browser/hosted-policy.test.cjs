'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const net = require('node:net');
const util = require('node:util');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const os = require('node:os');
const { HostedPolicy, egressProxy, staticAssets, renderAssets, controllerAsset, renderCheckNames, assetMatches } = require('./hosted-policy.cjs');
const { createHash } = require('node:crypto');
const initial = 'https://checkout.airwallex.com/pay?s=SYNTHETIC_SECRET';

test('candidate document is readable but grants no financial authority', () => {
  const policy = new HostedPolicy(initial);
  assert.equal(policy.decide(initial, 'GET', 'document'), 'allow');
  for (const method of ['POST', 'PUT', 'PATCH', 'DELETE']) {
    assert.equal(policy.decide(initial, method, 'document'), 'block');
    assert.equal(policy.decide('https://api.sandbox.airwallex.com/api/v1/pa/payment_consents/verify', method, 'fetch'), 'block');
  }
  assert.equal(policy.decide(initial + 'changed', 'GET', 'document'), 'block');
  assert.equal(policy.decide(initial, 'GET', 'fetch'), 'block');
});

test('production and unknown targets fail closed for every resource type', () => {
  const policy = new HostedPolicy(initial);
  for (const target of ['https://api.airwallex.com/api/v1/pa/payment_intents/create',
    'https://api-demo.airwallex.com/', 'https://private.example.test/',
    'http://checkout.airwallex.com/pay?s=SYNTHETIC_SECRET', 'file:///private/fixture']) {
    for (const type of ['document', 'fetch', 'script', 'image', 'worker']) {
      assert.equal(policy.decide(target, 'GET', type), 'block');
    }
  }
});

test('only the three pinned scripts on the initial candidate origin are permitted', () => {
  const policy = new HostedPolicy(initial);
  assert.equal(Object.keys(staticAssets).length, 3);
  for (const [pathname, hash] of Object.entries(staticAssets)) {
    const asset = 'https://checkout.airwallex.com' + pathname;
    assert.equal(policy.decide(asset, 'GET', 'script'), 'allow');
    assert.equal(policy.assetHash(asset, 'GET', 'script'), hash);
    for (const type of ['document', 'fetch', 'worker', 'stylesheet']) {
      assert.equal(policy.decide(asset, 'GET', type), 'block');
    }
    for (const method of ['POST', 'PUT', 'PATCH', 'DELETE']) {
      assert.equal(policy.decide(asset, method, 'script'), 'block');
    }
    for (const variant of [asset + '?', asset + '#', asset + '?private=value', asset + '#private',
      asset.replace('v2.286.0', 'v2.287.0'), asset.replace('/js/', '/other/../js/'),
      asset.replace('checkout.airwallex.com', 'checkout.sandbox.airwallex.com'),
      asset.replace('checkout.airwallex.com', 'unknown.invalid')]) {
      assert.equal(policy.decide(variant, 'GET', 'script'), 'block');
    }
    const sandbox = new HostedPolicy('https://checkout.sandbox.airwallex.com/pay?s=SYNTHETIC_SECRET');
    assert.equal(sandbox.decide('https://checkout.sandbox.airwallex.com' + pathname, 'GET', 'script'), 'allow');
  }
  assert.equal(policy.decide('https://static.airwallex.com/components/sdk/v1/index.js', 'GET', 'script'), 'block');
  assert.equal(policy.decide('https://checkout.airwallex.com/pay/static/v2.286.0/js/unknown.js', 'GET', 'script'), 'block');
});

test('script bytes must match the reviewed digest before fulfillment', () => {
  const original = Buffer.from('synthetic script');
  const expected = createHash('sha256').update(original).digest('hex');
  assert.equal(assetMatches(original, expected), true);
  assert.equal(assetMatches(Buffer.from('altered script'), expected), false);
  assert.equal(assetMatches(original, '0'.repeat(64)), false);
  assert.equal(assetMatches('synthetic script', expected), false);
});

test('candidate host category never implies financial sandbox verification', () => {
  assert.equal(new HostedPolicy(initial).candidateOrigin(), 'shared');
  assert.equal(new HostedPolicy('https://checkout.sandbox.airwallex.com/pay?s=x').candidateOrigin(), 'sandbox');
  assert.equal(new HostedPolicy('http://127.0.0.1:12345/page', 'http://127.0.0.1:12345').candidateOrigin(), null);
});

test('payer data without binding and all financial paths remain blocked even on the candidate origin', () => {
  for (const origin of ['https://checkout.airwallex.com', 'https://checkout.sandbox.airwallex.com']) {
    const policy = new HostedPolicy(origin + '/pay?s=x');
    for (const pathname of ['/billing/payer-web-api/checkout', '/billing/payer-web-api/checkout/status',
      '/billing/payer-web-api/checkout/collect', '/billing/payer-web-api/checkout/complete',
      '/api/v1/pa/payment_intents/fixture/confirm', '/assets/elements.bundle.min.js']) {
      for (const method of ['GET', 'POST']) {
        for (const type of ['document', 'script', 'fetch']) {
          assert.equal(policy.decide(origin + pathname, method, type), 'block');
        }
      }
    }
  }
});

test('return redirects are locally fulfilled even with private parameters', () => {
  const policy = new HostedPolicy(initial);
  assert.equal(policy.decide('https://example.com/?secret=SYNTHETIC_SECRET', 'GET', 'document'), 'return');
  assert.equal(policy.decide('https://example.com/', 'POST', 'document'), 'block');
  assert.equal(policy.decide('https://example.com/', 'GET', 'fetch'), 'block');
  assert.equal(policy.decide('https://example.com/unknown', 'GET', 'document'), 'block');
});

test('invalid, credentialed, oversized and lookalike initial targets reject', () => {
  for (const value of [null, {}, '', 'https://checkout.airwallex.com.evil.test/pay?s=x',
    'https://checkout.airwallex.com:444/pay?s=x', 'https://user:pass@checkout.airwallex.com/pay?s=x',
    'https://checkout.airwallex.com/pay', 'https://checkout.airwallex.com/other?s=x',
    'https://checkout.airwallex.com/pay?s=x\n', 'https://checkout.airwallex.com\\evil.test/pay?s=x', 'a'.repeat(16385)]) {
    assert.throws(() => new HostedPolicy(value), /Invalid browser preflight configuration/u);
  }
});

test('debug and serialization cannot expose the private session', () => {
  const policy = new HostedPolicy(initial);
  assert.doesNotMatch(util.inspect(policy, { depth: null }), /SYNTHETIC_SECRET/u);
  assert.throws(() => JSON.stringify(policy), /cannot be serialized/u);
});

test('authority boundary rejects production, alternate ports and encoded lookalikes', () => {
  const policy = new HostedPolicy(initial);
  assert.equal(policy.allowsAuthority('checkout.airwallex.com', '443'), true);
  assert.equal(policy.allowsAuthority('static.airwallex.com', '443'), false);
  for (const host of ['api.airwallex.com', 'api.sandbox.airwallex.com', 'checkout.airwallex.com.evil.test',
    'checkout.airwallex.com@api.airwallex.com', '127.0.0.1', '[::1]']) {
    assert.equal(policy.allowsAuthority(host, '443'), false);
  }
  assert.equal(policy.allowsAuthority('checkout.airwallex.com', '444'), false);
});

test('loopback fixture capability never authorizes another origin or workers', () => {
  const origin = 'http://127.0.0.1:12345';
  const policy = new HostedPolicy(origin + '/page', origin);
  assert.equal(policy.decide(origin + '/page', 'GET', 'document'), 'allow');
  assert.equal(policy.decide(origin + '/asset.js', 'GET', 'script'), 'allow');
  assert.equal(policy.decide(origin + '/worker.js', 'GET', 'worker'), 'block');
  assert.equal(policy.decide('http://127.0.0.1:12346/asset.js', 'GET', 'script'), 'block');
  assert.equal(policy.decide('https://static.airwallex.com/components/sdk/v1/index.js', 'GET', 'script'), 'block');
  assert.equal(policy.allowsAuthority('static.airwallex.com', '443'), false);
  assert.equal(policy.allowsAuthority('127.0.0.1', '12345'), true);
  assert.equal(policy.allowsAuthority('127.0.0.1', '12346'), false);
  assert.throws(() => new HostedPolicy(initial, 'https://private.example.test'), /Invalid local fixture/u);
});

test('proxy rejects disallowed HTTP and CONNECT before opening upstream sockets', async () => {
  const proxy = await egressProxy(new HostedPolicy(initial));
  try {
    const status = await new Promise((resolve, reject) => {
      const request = http.request(proxy.server, { path: 'http://api.airwallex.com/blocked' }, response => {
        response.resume(); response.on('end', () => resolve(response.statusCode));
      });
      request.on('error', reject); request.end();
    });
    assert.equal(status, 403);
    const port = Number(new URL(proxy.server).port);
    const response = await new Promise((resolve, reject) => {
      const client = net.connect(port, '127.0.0.1', () => {
        client.write('CONNECT api.airwallex.com:443 HTTP/1.1\r\nHost: api.airwallex.com:443\r\n\r\n');
      });
      client.on('data', chunk => { resolve(chunk.toString('ascii')); client.destroy(); });
      client.on('error', reject);
    });
    assert.match(response, /^HTTP\/1.1 403/u);
    assert.equal(proxy.counts.connected, 0);
    assert.equal(proxy.counts.rejected, 2);
  } finally { await proxy.close(); }
});

test('CLI rejects invalid private input before loading browser tooling with fixed output only', () => {
  for (const input of ['{}', '{malformed', JSON.stringify({ url: 'https://api.airwallex.com/PRIVATE_SESSION' }),
    JSON.stringify({ url: initial, extra: 'PRIVATE_SESSION' }),
    JSON.stringify({ url: initial, expected: null }), JSON.stringify({ url: initial, expected: [] }), 'x'.repeat(20001)]) {
    const child = spawnSync(process.execPath,
      [path.join(__dirname, 'hosted-browser.cjs'), '/nonexistent-synthetic-playwright-module'],
      { input, encoding: 'utf8', timeout: 3000 });
    assert.equal(child.status, 1);
    assert.equal(child.stderr, '');
    assert.deepEqual(JSON.parse(child.stdout), {
      result: 'fail', reason: 'browser_preflight_failed', card_entry_enabled: false, browser_failure_phase: 'configuration',
    });
  }
});

function fixtureModule(fault) {
  return `
    'use strict';
    const fault = ${JSON.stringify(fault)};
    const fail = () => { throw new Error('SYNTHETIC_PRIVATE_URL'); };
    if (fault.startsWith('controller_')) {
      const timeout = globalThis.setTimeout;
      globalThis.setTimeout = (callback, delay, ...args) => timeout(callback, delay === 5000 ? 1 : delay, ...args);
    }
    const fixture = { created: false, disposed: false, gets: 0 };
    module.exports = { fixture, request: { async newContext(options) {
      if (fault === 'controller_context' || options.ignoreHTTPSErrors !== false || !options.proxy) fail();
      if (fault === 'controller_delayed_construction') await new Promise(resolve => setTimeout(resolve, 30));
      fixture.created = true;
      let calls = 0;
      return { async get(url, options) {
        fixture.gets++;
        if (++calls !== 1 || !url.startsWith('http://127.0.0.1:54321/controller-sdk.js?ts=')
          || JSON.stringify(options.headers) !== JSON.stringify({ accept: '*/*', cookie: '', referer: '' })
          || options.maxRedirects !== 0 || options.maxRetries !== 0 || fault === 'controller_get') fail();
        return { status: () => fault === 'controller_redirect' ? 302 : fault === 'controller_http_rejected' ? 503 : 200,
          async body() { if (fault === 'controller_body') fail();
            if (fault === 'controller_expired_integrity') Date.now = () => 4070908800001;
            return Buffer.from(['controller_integrity', 'controller_expired_integrity'].includes(fault) ? 'SYNTHETIC_PRIVATE_BODY' : 'window.fixtureControllerReady = true;'); },
          headers() { if (fault === 'controller_headers') fail(); return { 'content-type': 'application/javascript',
            'access-control-allow-origin': '*', 'Set-Cookie': 'SYNTHETIC_PRIVATE_COOKIE' }; },
          async dispose() { if (fault === 'controller_response_dispose') fail(); } };
      }, async dispose() { fixture.disposed = true; if (fault === 'controller_context_dispose') fail(); } };
    } }, chromium: { async launch(options) {
      if (!options.headless || !options.proxy || !options.args.includes('--disable-quic')) fail();
      let routeHandler, websocketHandler, pageHandler, dialogHandler;
      const mainFrame = {};
      const page = {
        setDefaultTimeout() {}, setDefaultNavigationTimeout() {}, mainFrame: () => mainFrame,
        on(name, handler) { if (name === 'dialog') dialogHandler = handler; },
        getByText() { return { first() { return { async isVisible() { return false; } }; } }; },
        async goto(url) {
          const payer = fault.startsWith('payer_');
          const target = fault.startsWith('controller_') ? new URL('/controller-sdk.js?ts=1794130000000', url).href : fault.startsWith('render_')
            ? 'https://checkout.airwallex.com/' + (fault === 'render_target' ? '?PRIVATE' : '') : payer ? 'https://checkout.sandbox.airwallex.com/billing/payer-web-api/checkout?ts=1794130000000' : fault === 'abort' ? 'https://unknown.invalid/SYNTHETIC_PRIVATE_URL'
            : fault === 'asset_integrity' ? 'https://checkout.airwallex.com/pay/static/v2.286.0/js/main.ddc4fcf3b752ddbd5f14.js' : url;
          const routed = routeHandler({
            request() { return { url: () => target, method: () => 'GET', frame: () => {
                if (fault === 'render_frame_unavailable') fail();
                if (!fault.startsWith('render_')) return mainFrame;
                return { parentFrame: () => fault === 'render_parent' ? {} : mainFrame, async frameElement() {
                  if (fault === 'render_source_unavailable') fail();
                  return { async getAttribute() { if (fault === 'render_attribute_unavailable') fail(); return 'PRIVATE_SRC'; },
                    async dispose() { if (fault === 'render_dispose') fail(); } };
                } };
              },
              resourceType: () => fault.startsWith('controller_') ? 'script' : payer ? 'fetch' : fault === 'asset_integrity' ? 'script' : 'document',
              headers: () => ({ referer: 'SYNTHETIC_PRIVATE_URL', authorization: 'Bearer ' + new URL(url).searchParams.get('s') }), postData: () => null }; },
            async abort() { if (fault === 'abort' || fault === 'payer_abort') fail(); },
            async fetch(options) {
              if (fault === 'fetch' || fault === 'payer_fetch' || fault.startsWith('render_') || options.maxRedirects !== 0 || options.maxRetries !== 0
                  || 'referer' in options.headers || (payer && options.headers.cookie !== '')) fail();
              return { status: () => fault.startsWith('payer_http_') ? Number(fault.slice(11)) : 200, body: async () => { if (fault === 'payer_body') fail(); return Buffer.from(fault === 'payer_render_fulfill'
                  ? JSON.stringify({ mode: 'SETUP', currency: 'EUR' }) : '<!doctype html>'); },
                async dispose() { if (fault === 'dispose' || fault === 'payer_dispose') fail(); } };
            },
            async fulfill(options) {
              if (fault === 'fulfill' || fault === 'payer_render_fulfill' || fault === 'controller_fulfill') fail();
              if (fault.startsWith('controller_') && (Object.keys(options.headers).some(key => key.toLowerCase() === 'set-cookie')
                  || options.headers['access-control-allow-origin'] !== '*')) fail();
            },
          });
          if (fault === 'controller_delayed_construction') { await Promise.resolve(); fail(); }
          await routed;
          if (fault === 'websocket') await websocketHandler({ async close() { fail(); } });
          if (fault === 'dialog') dialogHandler({ async dismiss() { fail(); } });
        },
      };
      const context = {
        async addInitScript() {}, async route(_pattern, handler) { routeHandler = handler; },
        async routeWebSocket(_pattern, handler) { websocketHandler = handler; },
        on(name, handler) { if (name === 'page') pageHandler = handler; },
        async newPage() { pageHandler(page); return page; },
        async close() { if (fault === 'context_close') fail(); },
      };
      return {
        async newContext(options) {
          if (options.ignoreHTTPSErrors !== false || options.serviceWorkers !== 'block' || options.acceptDownloads) fail();
          return context;
        },
        version: () => '157.0.0.0', async close() { if (fault === 'browser_close') fail(); },
      };
    } } };
  `;
}

for (const fault of ['none', 'abort', 'fetch', 'fulfill', 'dispose', 'websocket', 'dialog', 'context_close',
  'browser_close', 'asset_integrity', 'payer_fetch', 'payer_body', 'payer_dispose', 'payer_abort', 'payer_http_401', 'payer_http_403', 'payer_http_404', 'payer_http_503', 'payer_render_fulfill',
  'render_frame_unavailable', 'render_parent', 'render_target', 'render_source_unavailable',
  'render_attribute_unavailable', 'render_dispose']) {
  test(`async actions and cleanup have a fixed private failure boundary: ${fault}`, () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'airwallex-browser-module-fixture-'));
    try {
      fs.writeFileSync(path.join(directory, 'index.js'), fixtureModule(fault));
      const child = spawnSync(process.execPath, [path.join(__dirname, 'hosted-browser.cjs'), directory],
        { input: JSON.stringify({ url: fault.startsWith('payer_')
          ? 'https://checkout.sandbox.airwallex.com/pay?s=' + sessionToken() : initial,
          render: fault === 'payer_render_fulfill' || fault.startsWith('render_'), expected: { checkout_id: 'SYNTHETIC_CHECKOUT', mode: 'SETUP', currency: 'EUR' } }), encoding: 'utf8', timeout: 4000 });
      assert.equal(child.stderr, '');
      assert.doesNotMatch(child.stdout, /SYNTHETIC_PRIVATE_URL|SYNTHETIC_SECRET/u);
      const output = JSON.parse(child.stdout);
      assert.equal(output.card_entry_enabled, false);
      if (fault === 'none' || fault.startsWith('payer_http_') || (fault.startsWith('render_') && fault !== 'render_dispose')) {
        if (fault.startsWith('render_')) {
          const label = { render_frame_unavailable: 'frame_unavailable', render_parent: 'parent_mismatch',
            render_target: 'request_path_or_query_mismatch', render_source_unavailable: 'source_unavailable',
            render_attribute_unavailable: 'source_unavailable' }[fault];
          assert.equal(output.render_frame_checks.document_candidates, 1);
          assert.equal(output.render_frame_checks[label], 1);
          assert.equal(output.render_frame_checks.qualified, 0);
          assert.equal(output.blocked_requests, 1);
        }
        assert.equal(child.status, 0);
        if (fault.startsWith('payer_http_')) {
          assert.equal(output.payer_http_status, Number(fault.slice(11)));
          assert.equal(output.payer_observation, 'http_rejected');
          assert.equal(output.payer_get_attempted, true);
        }
        assert.equal(output.result, 'blocked');
      } else {
        assert.equal(child.status, 1);
        assert.equal(output.result, 'fail');
        assert.equal(output.reason, 'browser_preflight_failed');
        assert.equal(typeof output.browser_failure_phase, 'string');
        assert.doesNotMatch(JSON.stringify(output), /PRIVATE/u);
      }
    } finally { fs.rmSync(directory, { recursive: true, force: true }); }
  });
}


const expectedCheckout = { checkout_id: 'SYNTHETIC_CHECKOUT', mode: 'SETUP', currency: 'EUR' };
function sessionToken(claim = {}) {
  return Buffer.from('{"alg":"SYNTHETIC"}').toString('base64url') + '.'
    + Buffer.from(JSON.stringify({ resource_id: 'checkout:SYNTHETIC_CHECKOUT', exp: 4070908800, ...claim })).toString('base64url')
    + '.SYNTHETIC_SIGNATURE';
}
function boundPolicy(origin = 'https://checkout.sandbox.airwallex.com', token = sessionToken(), expected = expectedCheckout, render = false) {
  return new HostedPolicy(origin + '/pay?s=' + token, null, expected, render);
}
const payerUrl = 'https://checkout.sandbox.airwallex.com/billing/payer-web-api/checkout?ts=1794130000000';

test('one named Sandbox getter is bound to the SDK-owned ID and private URL token', () => {
  const policy = boundPolicy();
  assert.equal(policy.bindingEvidence().checkout_token_matches, true);
  assert.equal(policy.requestScopeVerified(), true);
  const headers = { authorization: 'Bearer ' + sessionToken() };
  assert.equal(policy.claimPayerRequest(payerUrl, 'GET', 'fetch', headers), true);
  assert.equal(policy.claimPayerRequest(payerUrl, 'GET', 'fetch', headers), false);
  assert.equal(policy.claimPayerRequest(payerUrl.replace('0000', '0001'), 'GET', 'xhr', headers), false);
  assert.deepEqual(policy.bindingEvidence(), { checkout_token_matches: true, payer_request_scope_verified: true,
    payer_get_attempted: true, payer_customer_mapping_verified: false, payer_payload_delivered: false,
    financial_routing_verified: false, render_requested: false, render_frame_binding_available: false });
  assert.doesNotMatch(util.inspect(policy, { depth: null }), /SYNTHETIC_CHECKOUT|SYNTHETIC_SIGNATURE/u);
  assert.throws(() => JSON.stringify(policy), /cannot be serialized/u);
});

test('shared origin, unknown/expired/malformed tokens and wrong owned ID never authorize the getter', () => {
  const tokens = ['opaque', sessionToken({ resource_id: 'checkout:OTHER' }), sessionToken({ resource_id: 'SYNTHETIC_CHECKOUT' }),
    sessionToken({ resource_id: 1 }), sessionToken({ exp: 1 }), sessionToken({ exp: '4070908800' }),
    'a.eyJ.abc', 'a.' + Buffer.from('[]').toString('base64url') + '.abc'];
  for (const token of tokens) {
    const policy = boundPolicy(undefined, token);
    assert.equal(policy.requestScopeVerified(), false);
    assert.equal(policy.claimPayerRequest(payerUrl, 'GET', 'fetch', { authorization: 'Bearer ' + token }), false);
  }
  const shared = boundPolicy('https://checkout.airwallex.com');
  assert.equal(shared.bindingEvidence().checkout_token_matches, true);
  assert.equal(shared.requestScopeVerified(), false);
  const repeated = new HostedPolicy('https://checkout.sandbox.airwallex.com/pay?s=' + sessionToken() + '&s=' + sessionToken(),
    null, expectedCheckout);
  assert.equal(repeated.requestScopeVerified(), false);
  for (const expected of [{ ...expectedCheckout, currency: 'eur' }, { ...expectedCheckout, mode: 'PAYMENT' },
    { ...expectedCheckout, checkout_id: 'PRIVATE_ID\n' }, { ...expectedCheckout, extra: 'PRIVATE' }]) {
    assert.throws(() => boundPolicy(undefined, undefined, expected), /Invalid private browser expectation/u);
  }
});

test('getter rejects every route/query/header/method/body variation and all status or financial paths', () => {
  const headers = { authorization: 'Bearer ' + sessionToken() };
  const variants = [
    [payerUrl.replace('checkout.sandbox', 'checkout'), 'GET', 'fetch', headers],
    [payerUrl.replace('checkout.sandbox.airwallex.com', 'unknown.invalid'), 'GET', 'fetch', headers],
    [payerUrl.replace('/checkout?', '/checkout/status?'), 'GET', 'fetch', headers],
    [payerUrl.replace('/checkout?', '/checkout/collect?'), 'GET', 'fetch', headers],
    [payerUrl.replace('/checkout?', '/checkout/complete?'), 'GET', 'fetch', headers],
    [payerUrl.replace('/billing/payer-web-api/checkout', '/api/v1/pa/payment_consents/create'), 'GET', 'fetch', headers],
    [payerUrl.replace('ts=', 'other='), 'GET', 'fetch', headers],
    [payerUrl + '&extra=PRIVATE', 'GET', 'fetch', headers], [payerUrl + '&ts=1794130000000', 'GET', 'fetch', headers],
    [payerUrl + '#', 'GET', 'fetch', headers], [payerUrl + '#PRIVATE', 'GET', 'fetch', headers],
    [payerUrl.replace('/checkout?', '/other/../checkout?'), 'GET', 'fetch', headers],
    [payerUrl.replace('1794130000000', 'tomorrow'), 'GET', 'fetch', headers],
    [payerUrl, 'POST', 'fetch', headers], [payerUrl, 'GET', 'document', headers],
    [payerUrl, 'GET', 'fetch', {}], [payerUrl, 'GET', 'fetch', { authorization: 'Bearer OTHER' }],
    [payerUrl, 'GET', 'fetch', headers, 'PRIVATE_BODY'],
  ];
  for (const args of variants) {
    const policy = boundPolicy();
    assert.equal(policy.claimPayerRequest(...args), false);
    assert.equal(policy.bindingEvidence().payer_get_attempted, false);
  }
});

test('payer observation validates only known mode/currency, never invents external customer equality', () => {
  const policy = boundPolicy();
  assert.deepEqual(policy.observePayer(Buffer.from(JSON.stringify({ mode: 'SETUP', currency: 'EUR',
    setupModeDetails: { billingCustomerExternalId: 'UNRELATED_EXTERNAL_REFERENCE' },
    legalEntity: { name: 'PRIVATE_NAME' }, sellerId: 'PRIVATE_SELLER' }))),
  { payer_observation: 'matched', payer_mode_matches: true, payer_currency_matches: true });
  for (const body of ['{}', '{"mode":"PAYMENT","currency":"EUR"}', '{"mode":"SETUP","currency":"USD"}']) {
    assert.equal(policy.observePayer(Buffer.from(body)).payer_observation, 'mode_or_currency_mismatch');
  }
  for (const body of ['null', '[]', 'invalid PRIVATE', 'x'.repeat(65537)]) {
    assert.deepEqual(policy.observePayer(Buffer.from(body)),
      { payer_observation: 'invalid_response', payer_mode_matches: false, payer_currency_matches: false });
  }
  assert.equal(policy.observePayer(Buffer.from([0xff])).payer_observation, 'invalid_response');
  assert.equal(policy.bindingEvidence().payer_customer_mapping_verified, false);
  assert.equal(policy.bindingEvidence().payer_payload_delivered, false);
});


const privatePayer = { mode: 'SETUP', currency: 'EUR', setupModeDetails: {
  externalCustomerClientSecret: 'PRIVATE_CLIENT_SECRET', billingCustomerExternalId: 'PRIVATE_EXTERNAL_CUSTOMER',
} };
function renderingPolicy() {
  const policy = boundPolicy(undefined, undefined, undefined, true);
  assert.equal(policy.claimPayerRequest(payerUrl, 'GET', 'fetch', { authorization: 'Bearer ' + sessionToken() }), true);
  assert.equal(policy.observePayer(Buffer.from(JSON.stringify(privatePayer))).payer_observation, 'matched');
  return policy;
}
function privateFrame(options = {}) {
  const query = new URLSearchParams({ options: JSON.stringify({ mode: 'recurring', currency: 'EUR',
    client_secret: 'PRIVATE_CLIENT_SECRET', customer_id: 'PRIVATE_EXTERNAL_CUSTOMER', ...options }),
  lang: 'en', appVersion: 'v2.56.0' });
  return 'https://checkout.sandbox.airwallex.com/#/elements/dropin?' + query;
}

test('explicit rendering delivers only matched own payer data and permits one privately bound Drop-in frame', () => {
  const policy = renderingPolicy();
  assert.equal(policy.mayDeliverPayer(), true);
  assert.equal(policy.claimRenderFrame(privateFrame()), false);
  policy.markPayerDelivered();
  assert.equal(policy.claimRenderFrame(privateFrame()), true);
  assert.equal(policy.claimRenderFrame(privateFrame()), false);
  assert.equal(policy.bindingEvidence().payer_payload_delivered, true);
  assert.equal(policy.bindingEvidence().payer_customer_mapping_verified, false);
  assert.equal(policy.bindingEvidence().financial_routing_verified, false);
  assert.doesNotMatch(util.inspect(policy, { depth: null }), /PRIVATE_/u);
  assert.throws(() => JSON.stringify(policy), /cannot be serialized/u);
});

test('default/shared/mismatched/unreadable data cannot enable delivery or a frame', () => {
  const plain = boundPolicy();
  plain.claimPayerRequest(payerUrl, 'GET', 'fetch', { authorization: 'Bearer ' + sessionToken() });
  plain.observePayer(Buffer.from(JSON.stringify(privatePayer)));
  assert.equal(plain.mayDeliverPayer(), false);
  assert.throws(() => plain.markPayerDelivered(), /not been verified/u);
  for (const body of [Buffer.from('{}'), Buffer.from('{"mode":"PAYMENT","currency":"EUR"}'),
    Buffer.from('{"mode":"SETUP","currency":"USD"}'), Buffer.from('invalid'), Buffer.from('x'.repeat(65537))]) {
    const policy = renderingPolicy();
    policy.observePayer(body);
    assert.equal(policy.mayDeliverPayer(), false);
    assert.equal(policy.claimRenderFrame(privateFrame()), false);
  }
  const shared = boundPolicy('https://checkout.airwallex.com', undefined, undefined, true);
  assert.equal(shared.renderEnabled(), false);
  assert.equal(shared.mayDeliverPayer(), false);
});

test('frame options/route/build/language and private session must match, without SDK Customer-ID inference', () => {
  const variants = [privateFrame({ mode: 'payment' }), privateFrame({ currency: 'USD' }),
    privateFrame({ client_secret: 'PRIVATE_OTHER_SECRET' }), privateFrame({ customer_id: 'PRIVATE_OTHER_CUSTOMER' }),
    privateFrame({ intent_id: 'PRIVATE_OTHER_INTENT' }),
    privateFrame().replace('checkout.sandbox.', 'checkout.'), privateFrame().replace('/#/elements/dropin', '/pay#/elements/dropin'),
    privateFrame().replace('/#/elements/dropin', '/#/elements/3ds-frictionless'),
    privateFrame().replace('v2.56.0', 'v2.57.0'), privateFrame().replace('lang=en', 'lang=PRIVATE'),
    privateFrame() + '&extra=PRIVATE', privateFrame() + '&options=%7B%7D'];
  for (const frame of variants) {
    const policy = renderingPolicy(); policy.markPayerDelivered();
    assert.equal(policy.claimRenderFrame(frame), false);
  }
  const policy = renderingPolicy();
  policy.observePayer(Buffer.from('{"mode":"SETUP","currency":"EUR"}'));
  assert.equal(policy.mayDeliverPayer(), true); // Missing Web API mapping is not an invented data-delivery gate.
  policy.markPayerDelivered();
  assert.equal(policy.claimRenderFrame(privateFrame()), false);
  assert.equal(policy.bindingEvidence().render_frame_binding_available, false);
});

test('render scripts are a closed byte-pinned Sandbox graph with only the source-defined loader timestamp', () => {
  const policy = renderingPolicy();
  assert.equal(Object.keys(renderAssets).length, 43);
  for (const [pathname, hash] of Object.entries(renderAssets)) {
    const target = 'https://checkout.sandbox.airwallex.com' + pathname;
    const suffix = pathname === '/assets/elements.bundle.min.js' ? '?ts=1794130000000' : '';
    assert.equal(policy.assetHash(target + suffix, 'GET', 'script'), hash);
    assert.equal(boundPolicy().assetHash(target + suffix, 'GET', 'script'), null);
    for (const variant of [target + suffix + '#', target + suffix + '&extra=PRIVATE',
      (target + suffix).replace('checkout.sandbox.', 'checkout.'), target + '?other=1794130000000']) {
      assert.equal(policy.assetHash(variant, 'GET', 'script'), null);
    }
    assert.equal(policy.assetHash(target + suffix, 'POST', 'script'), null);
    assert.equal(policy.assetHash(target + suffix, 'GET', 'fetch'), null);
  }
  for (const pathname of ['/billing/payer-web-api/checkout/status', '/billing/payer-web-api/checkout/collect',
    '/billing/payer-web-api/checkout/complete', '/api/v1/pa/payment_intents/PRIVATE/confirm',
    '/static/js/unknown.chunk.js', '/assets/unknown.js']) {
    for (const method of ['GET', 'POST']) assert.equal(policy.decide('https://checkout.sandbox.airwallex.com' + pathname, method, 'fetch'), 'block');
  }
});


test('frame diagnostics identify fixed local rejections without keeping source/options/identity values', () => {
  const cases = [
    [null, 'invalid_source_url'],
    [privateFrame().replace('checkout.sandbox.', 'checkout.'), 'source_origin_mismatch'],
    [privateFrame().replace('/#/elements/dropin', '/pay#/elements/dropin'), 'source_path_or_query_mismatch'],
    [privateFrame().replace('/#/elements/dropin', '/?PRIVATE=SECRET#/elements/dropin'), 'source_path_or_query_mismatch'],
    [privateFrame().replace('/#/elements/dropin', '/#/elements/PRIVATE'), 'fragment_route_mismatch'],
    [privateFrame() + '&PRIVATE=SECRET', 'fragment_keys_mismatch'],
    [privateFrame().replace('v2.56.0', 'PRIVATE_BUILD'), 'build_mismatch'],
    [privateFrame().replace('lang=en', 'lang=PRIVATE_LANG'), 'language_mismatch'],
    [privateFrame().replace(/options=[^&]*/u, 'options=%7BPRIVATE'), 'invalid_options'],
    [privateFrame({ mode: 'PRIVATE_MODE' }), 'mode_mismatch'],
    [privateFrame({ currency: 'PRIVATE_CURRENCY' }), 'currency_mismatch'],
    [privateFrame({ client_secret: 'PRIVATE_OTHER_SECRET' }), 'secret_mismatch'],
    [privateFrame({ customer_id: 'PRIVATE_OTHER_CUSTOMER' }), 'customer_mismatch'],
    [privateFrame({ intent_id: 'PRIVATE_OTHER_INTENT' }), 'intent_mismatch'],
  ];
  for (const [source, reason] of cases) {
    const policy = renderingPolicy(); policy.markPayerDelivered();
    assert.equal(policy.claimRenderFrame(source), false);
    const checks = policy.renderEvidence().render_frame_checks;
    assert.deepEqual(Object.keys(checks), renderCheckNames);
    assert.deepEqual(Object.entries(checks).filter(([, n]) => n !== 0), [[reason, 1]]);
    assert.doesNotMatch(JSON.stringify(checks), /PRIVATE/u);
    assert.equal(policy.decide('https://checkout.sandbox.airwallex.com/billing/payer-web-api/checkout/collect', 'POST', 'fetch'), 'block');
  }
});

test('frame evidence distinguishes unmet prerequisites, unavailable observations and one-time qualification', () => {
  assert.deepEqual(boundPolicy().renderEvidence(), {});
  const policy = renderingPolicy();
  assert.equal(policy.claimRenderFrame(privateFrame()), false);
  assert.equal(policy.renderEvidence().render_frame_checks.payer_not_delivered, 1);
  policy.markPayerDelivered();
  assert.equal(policy.renderRequestTarget('https://unknown.invalid/PRIVATE'), false);
  assert.equal(policy.renderRequestTarget('https://checkout.sandbox.airwallex.com/?PRIVATE'), false);
  assert.equal(policy.renderRequestTarget('https://checkout.sandbox.airwallex.com/'), true);
  policy.noteRenderCheck('frame_unavailable'); policy.noteRenderCheck('parent_mismatch');
  policy.noteRenderCheck('source_unavailable'); policy.noteRenderCheck('PRIVATE_LABEL');
  assert.equal(policy.claimRenderFrame(privateFrame()), true);
  assert.equal(policy.claimRenderFrame(privateFrame()), false);
  const checks = policy.renderEvidence().render_frame_checks;
  for (const key of ['request_origin_mismatch', 'request_path_or_query_mismatch', 'frame_unavailable',
    'parent_mismatch', 'source_unavailable', 'qualified', 'limit_reached']) assert.equal(checks[key], 1);
  assert.equal(Object.hasOwn(checks, 'PRIVATE_LABEL'), false);
  checks.customer_mismatch = 'PRIVATE_VALUE';
  assert.equal(policy.renderEvidence().render_frame_checks.customer_mismatch, 0); // Detached safe snapshot.
  const missing = renderingPolicy();
  missing.observePayer(Buffer.from('{"mode":"SETUP","currency":"EUR"}')); missing.markPayerDelivered();
  assert.equal(missing.claimRenderFrame(privateFrame()), false);
  assert.equal(missing.renderEvidence().render_frame_checks.reference_unavailable, 1);
  const shared = boundPolicy('https://checkout.airwallex.com', undefined, undefined, true);
  assert.equal(shared.claimRenderFrame(privateFrame()), false);
  assert.equal(shared.renderEvidence().render_frame_checks.scope_unverified, 1);
});


test('fixed frame diagnostic counters saturate without accepting arbitrary labels or changing authority', () => {
  const policy = renderingPolicy();
  for (let n = 0; n < 1000005; n++) policy.noteRenderCheck('parent_mismatch');
  assert.equal(policy.renderEvidence().render_frame_checks.parent_mismatch, 1000000);
  assert.equal(policy.allowsAuthority('api.airwallex.com', 443), false);
  assert.equal(policy.decide('https://checkout.sandbox.airwallex.com/api/v1/pa/PRIVATE', 'GET', 'fetch'), 'block');
});


test('public controller bootstrap is pinned and available before private payer delivery only for explicit Sandbox render', () => {
  const target = controllerAsset.origin + controllerAsset.pathname + '?ts=1794130000000';
  const policy = boundPolicy(undefined, undefined, undefined, true);
  assert.equal(policy.bindingEvidence().payer_payload_delivered, false);
  assert.equal(policy.bindingEvidence().payer_get_attempted, false);
  assert.equal(policy.assetHash(target, 'GET', 'script'), controllerAsset.sha256);
  assert.equal(policy.decide(target, 'GET', 'script'), 'allow');
  assert.equal(policy.allowsAuthority('static.sandbox.airwallex.com', 443), true);
  assert.equal(policy.claimControllerRequest(target, 'GET', 'script'), true);
  assert.equal(policy.claimControllerRequest(target.replace('0000000', '0000001'), 'GET', 'script'), false);
  assert.equal(policy.mayDeliverPayer(), false);
  assert.equal(policy.claimRenderFrame(privateFrame()), false);
  assert.equal(policy.bindingEvidence().financial_routing_verified, false);
  for (const denied of [boundPolicy(), boundPolicy('https://checkout.airwallex.com', undefined, undefined, true),
    boundPolicy(undefined, sessionToken({ resource_id: 'checkout:OTHER' }), undefined, true), boundPolicy(undefined, sessionToken({ exp: 1 }), undefined, true)]) {
    assert.equal(denied.assetHash(target, 'GET', 'script'), null);
    assert.equal(denied.claimControllerRequest(target, 'GET', 'script'), false);
    assert.equal(denied.allowsAuthority('static.sandbox.airwallex.com', 443), false);
  }
});

test('controller URI permission never authorizes another widget, origin, query, method or resource kind', () => {
  const policy = boundPolicy(undefined, undefined, undefined, true);
  const target = controllerAsset.origin + controllerAsset.pathname;
  const good = target + '?ts=1794130000000';
  for (const value of [target, good + '#', good + '&ts=1794130000001', good + '&PRIVATE=VALUE',
    target + '?ts=123', good.replace('/sdk/v1/', '/sdk/v2/'), good.replace('sdk-controller', 'checkout'),
    good.replace('static.sandbox.', 'static.'), good.replace('static.sandbox.', 'static-demo.'),
    good.replace('static.sandbox.airwallex.com', 'static.sandbox.airwallex.com.evil.invalid'),
    good.replace('https:', 'http:'), good.replace('.com/', '.com:443/'), good.replace('https://', 'https://PRIVATE@')]) {
    assert.equal(policy.decide(value, 'GET', 'script'), 'block');
    assert.equal(policy.controllerHash(value, 'GET', 'script'), null);
  }
  assert.equal(policy.decide(good, 'POST', 'script'), 'block');
  for (const kind of ['fetch', 'xhr', 'document', 'stylesheet', 'other']) assert.equal(policy.decide(good, 'GET', kind), 'block');
  for (const pathname of ['/api/v1/pa/config', '/widget/auth/exchangeToken', '/billing/payer-web-api/checkout']) {
    for (const method of ['GET', 'POST']) assert.equal(policy.decide(controllerAsset.origin + pathname, method, 'fetch'), 'block');
  }
  for (const host of ['static.airwallex.com', 'static-demo.airwallex.com', 'o11y.sandbox.airwallex.com', 'api.sandbox.airwallex.com']) {
    assert.equal(policy.allowsAuthority(host, 443), false);
  }
  assert.equal(policy.allowsAuthority('static.sandbox.airwallex.com', 444), false);
});


for (const fault of ['controller_success', 'controller_redirect', 'controller_http_rejected', 'controller_context', 'controller_get',
  'controller_body', 'controller_headers', 'controller_integrity', 'controller_fulfill',
  'controller_response_dispose', 'controller_context_dispose', 'controller_delayed_construction', 'controller_expired_integrity']) {
  test(`isolated public controller headers/hash/cleanup retain the private failure boundary: ${fault}`, () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'airwallex-controller-module-fixture-'));
    try {
      const modulePath = path.join(directory, 'index.js');
      fs.writeFileSync(modulePath, fixtureModule(fault));
      const browserPath = path.join(__dirname, 'hosted-browser.cjs');
      const driver = `const { inspect } = require(${JSON.stringify(browserPath)});
        inspect('http://127.0.0.1:54321/pay?s=${sessionToken()}', require(${JSON.stringify(modulePath)}),
          'http://127.0.0.1:54321', null, ${JSON.stringify(expectedCheckout)}, true)
          .then(result => process.stdout.write(JSON.stringify(result)))
          .catch(() => {
            const state = require(${JSON.stringify(modulePath)}).fixture;
            const cleanupFailed = ${JSON.stringify(fault)} === 'controller_delayed_construction'
              && (!state.created || !state.disposed || state.gets !== 0);
            process.stdout.write(JSON.stringify({ result: cleanupFailed ? 'fixture_cleanup_failed' : 'fail',
              reason: 'browser_preflight_failed', card_entry_enabled: false })); process.exitCode = 1; });`;
      const child = spawnSync(process.execPath, ['-e', driver], { encoding: 'utf8', timeout: 4000 });
      assert.equal(child.stderr, '');
      assert.doesNotMatch(child.stdout, /SYNTHETIC_PRIVATE/u);
      const result = JSON.parse(child.stdout);
      if (['controller_success', 'controller_redirect', 'controller_http_rejected'].includes(fault)) {
        assert.equal(child.status, 0);
        assert.equal(result.controller_asset_requests, 1);
        assert.equal(result.controller_http_status, fault === 'controller_success' ? 200 : fault === 'controller_redirect' ? 302 : 503);
        assert.equal(result.verified_controller_assets, fault === 'controller_success' ? 1 : 0);
        assert.equal(result.payer_payload_delivered, false);
        assert.equal(result.bound_render_frames, 0);
        assert.equal(result.proxy_external_connections, 0);
      } else {
        assert.equal(child.status, 1);
        assert.deepEqual(result, { result: 'fail', reason: 'browser_preflight_failed', card_entry_enabled: false });
      }
    } finally { fs.rmSync(directory, { recursive: true, force: true }); }
  });
}

const methodUrl = 'https://checkout.sandbox.airwallex.com/api/v1/pa/config/payment_method_types?'
  + 'active=true&page_num=0&page_size=200&transaction_currency=EUR&__verification_methods=true';
const methodHeaders = { 'client-secret': 'PRIVATE_CLIENT_SECRET', 'content-type': 'application/json',
  'x-api-version': '2024-06-14', 'x-client-ip-source': 'awx-checkout', 'awx-tracker': 'SYNTHETIC_TRACKER',
  'x-cloud-trace-context': '0123456789abcdef0123456789abcdef' };
function methodPolicy(options = {}) {
  const origin = options.origin ?? 'https://checkout.sandbox.airwallex.com';
  const policy = new HostedPolicy(origin + '/pay?s=' + sessionToken(), null, expectedCheckout,
    options.render ?? true, options.observe ?? true);
  policy.claimPayerRequest(origin + '/billing/payer-web-api/checkout?ts=1794130000000', 'GET', 'fetch',
    { authorization: 'Bearer ' + sessionToken() });
  policy.observePayer(Buffer.from(JSON.stringify(privatePayer)));
  if (policy.mayDeliverPayer()) policy.markPayerDelivered();
  if (options.frame !== false && policy.claimRenderFrame(privateFrame())) policy.markRenderFrameDelivered();
  return policy;
}

test('one method-config read requires explicit opt-in, matched payer and delivered bound frame', () => {
  assert.throws(() => new HostedPolicy(initial, null, expectedCheckout, false, true));
  for (const options of [{ observe: false }, { observe: false, render: false }, { frame: false },
    { origin: 'https://checkout.airwallex.com' }]) {
    assert.equal(methodPolicy(options).claimMethodConfig(methodUrl, 'GET', 'fetch', methodHeaders), null);
  }
  const policy = methodPolicy();
  const headers = policy.claimMethodConfig(methodUrl, 'GET', 'fetch', { ...methodHeaders,
    cookie: 'PRIVATE_COOKIE', referer: 'PRIVATE_URL', baggage: 'PRIVATE_TRACE' });
  assert.deepEqual(headers, { accept: 'application/json, text/plain, */*', cookie: '', referer: '', ...methodHeaders });
  assert.equal(policy.claimMethodConfig(methodUrl, 'GET', 'xhr', methodHeaders), null);
  assert.equal(policy.methodEvidence().method_config_get_attempted, true);
  assert.equal(policy.bindingEvidence().financial_routing_verified, false);
  assert.doesNotMatch(util.inspect(policy, { depth: null }), /PRIVATE/u);
});

test('method getter rejects unknown routes, query variants, auth contexts, versions and secrets before attempt', () => {
  for (const args of [
    [methodUrl.replace('checkout.sandbox', 'checkout'), 'GET', 'fetch', methodHeaders],
    [methodUrl.replace('payment_method_types', 'banks'), 'GET', 'fetch', methodHeaders],
    [methodUrl.replace('page_num=0', 'page_num=1'), 'GET', 'fetch', methodHeaders],
    [methodUrl.replace('page_size=200', 'page_size=100'), 'GET', 'fetch', methodHeaders],
    [methodUrl.replace('EUR', 'USD'), 'GET', 'fetch', methodHeaders],
    [methodUrl + '&page_num=0', 'GET', 'fetch', methodHeaders],
    [methodUrl + '&unknown=PRIVATE', 'GET', 'fetch', methodHeaders],
    [methodUrl + '&country_code=unknown', 'GET', 'fetch', methodHeaders],
    [methodUrl + '&payment_intent_id=PRIVATE', 'GET', 'fetch', methodHeaders],
    [methodUrl + '#', 'GET', 'fetch', methodHeaders],
    [methodUrl, 'POST', 'fetch', methodHeaders], [methodUrl, 'GET', 'document', methodHeaders],
    [methodUrl, 'GET', 'fetch', methodHeaders, 'PRIVATE_BODY'],
    ...['client-secret', 'x-api-version', 'content-type', 'x-client-ip-source', 'x-device-type', 'awx-tracker', 'x-cloud-trace-context']
      .map(key => [methodUrl, 'GET', 'fetch', { ...methodHeaders, [key]: 'PRIVATE_INVALID\n' }]),
    ...['authorization', 'x-on-behalf-of', 'x-auth-token', 'x-tax-region', 'x-tax-calculation-identifier', 'x-pan', 'x-unreviewed-context']
      .map(key => [methodUrl, 'GET', 'fetch', { ...methodHeaders, [key]: 'PRIVATE_CONTEXT' }]),
  ]) {
    const policy = methodPolicy();
    assert.equal(policy.claimMethodConfig(...args), null);
    assert.equal(policy.methodEvidence().method_config_get_attempted, false);
  }
  for (const trace of [undefined, '', 'ABCDEF0123456789abcdef0123456789ab', '0'.repeat(31), '0'.repeat(33)]) {
    const policy = methodPolicy();
    assert.equal(policy.claimMethodConfig(methodUrl, 'GET', 'fetch', { ...methodHeaders,
      'x-cloud-trace-context': trace }), null);
    assert.equal(policy.methodEvidence().method_config_get_attempted, false);
  }
  for (const country of ['', '&country_code=DE']) {
    const policy = methodPolicy();
    assert.notEqual(policy.claimMethodConfig(methodUrl + country, 'GET', 'xhr',
      { ...methodHeaders, 'x-device-type': 'desktop' }), null);
  }
});

test('optional Intent query correlates only with the privately observed frame, without invented country echo', () => {
  const policy = new HostedPolicy('https://checkout.sandbox.airwallex.com/pay?s=' + sessionToken(),
    null, expectedCheckout, true, true);
  policy.claimPayerRequest(payerUrl, 'GET', 'fetch', { authorization: 'Bearer ' + sessionToken() });
  policy.observePayer(Buffer.from(JSON.stringify({ ...privatePayer, setupModeDetails: {
    ...privatePayer.setupModeDetails, billingTransactionExternalId: 'PRIVATE_OWN_INTENT' } })));
  policy.markPayerDelivered();
  assert.equal(policy.claimRenderFrame(privateFrame({ intent_id: 'PRIVATE_OWN_INTENT' })), true);
  policy.markRenderFrameDelivered();
  assert.equal(policy.claimMethodConfig(methodUrl + '&payment_intent_id=PRIVATE_OTHER', 'GET', 'fetch', methodHeaders), null);
  assert.notEqual(policy.claimMethodConfig(methodUrl + '&payment_intent_id=PRIVATE_OWN_INTENT', 'GET', 'fetch', methodHeaders), null);
});

test('bounded first method page preserves missing pagination uncertainty and never infers activation', () => {
  for (const data of [{ items: [] }, { items: [{ name: 'card' }] }, { items: [], has_more: true },
    { items: [{ name: 'PRIVATE_OTHER', metadata: { PRIVATE_KEY: 'PRIVATE_VALUE' } }], has_more: false }]) {
    const policy = methodPolicy();
    policy.claimMethodConfig(methodUrl, 'GET', 'fetch', methodHeaders);
    const observed = policy.observeMethodConfig(Buffer.from(JSON.stringify(data)));
    assert.equal(observed.method_config_observation, 'matched');
    assert.equal(observed.method_config_pagination_known, Object.hasOwn(data, 'has_more'));
    assert.equal(observed.method_config_has_more, data.has_more === true);
    assert.equal(observed.method_config_card_advertised, data.items.some(item => item.name === 'card'));
    assert.equal(policy.mayDeliverMethodConfig(), true);
    assert.doesNotMatch(JSON.stringify(observed), /PRIVATE/u);
  }
  for (const body of ['null', '[]', '{}', '{"items":[null]}', '{"items":[],"has_more":"false"}',
    JSON.stringify({ items: Array(201).fill({}) }), 'x'.repeat(262145),
    '{"items":[],"nested":' + '['.repeat(33) + '0' + ']'.repeat(33) + '}', Buffer.from([0xff])]) {
    const policy = methodPolicy(); policy.claimMethodConfig(methodUrl, 'GET', 'fetch', methodHeaders);
    assert.equal(policy.observeMethodConfig(Buffer.from(body)).method_config_observation, 'invalid_response');
    assert.equal(policy.mayDeliverMethodConfig(), false);
  }
});

test('closed denied-read families expose only detached bounded counters under the observation opt-in', () => {
  const policy = methodPolicy();
  for (const path of ['/api/v1/pa/config/payment_method_types',
    '/api/v1/pa/customers/PRIVATE', '/api/v1/pa/payment_consents',
    '/billing/payer-web-api/checkout/status', '/api/v1/pa/PRIVATE']) {
    policy.noteDeniedRead('https://checkout.sandbox.airwallex.com' + path + '?PRIVATE_VALUE', 'GET', 'fetch');
    assert.equal(policy.decide('https://checkout.sandbox.airwallex.com' + path, 'GET', 'fetch'), 'block');
  }
  policy.noteDeniedRead('https://www.sandbox.airwallex.com/country', 'GET', 'fetch');
  assert.equal(policy.decide('https://www.sandbox.airwallex.com/country', 'GET', 'fetch'), 'block');
  assert.equal(policy.allowsAuthority('www.sandbox.airwallex.com', 443), false);
  for (const target of ['https://www.airwallex.com/country', 'https://www.sandbox.airwallex.com/country?PRIVATE',
    'https://www.sandbox.airwallex.com/country#PRIVATE', 'https://unknown.invalid/country']) {
    policy.noteDeniedRead(target, 'GET', 'fetch');
  }
  assert.deepEqual(policy.methodEvidence().denied_read_families,
    { method_config: 1, country: 1, customer: 1, consent_list: 1, checkout_status: 1, other_pa: 1 });
  const copy = policy.methodEvidence(); copy.denied_read_families.country = 999;
  assert.equal(policy.methodEvidence().denied_read_families.country, 1);
  assert.deepEqual(methodPolicy({ observe: false }).methodEvidence(), {});
});

function methodFixtureModule(fault) {
  return `
    'use strict';
    const fault = ${JSON.stringify(fault)};
    const fail = () => { throw new Error('PRIVATE_METHOD_URL_SECRET'); };
    const timeout = globalThis.setTimeout;
    globalThis.setTimeout = (callback, delay, ...args) => timeout(callback, [5000, 10000].includes(delay) ? 1 : delay, ...args);
    const fixture = { created: false, disposed: false, gets: 0, detached: false };
    module.exports = { fixture, request: { async newContext(options) {
      if (fault === 'context' || options.ignoreHTTPSErrors !== false || !options.proxy) fail();
      if (fault === 'delayed') await new Promise(resolve => setTimeout(resolve, 30));
      fixture.created = true;
      return { async get(url, options) {
        fixture.gets++;
        if (fault === 'get' || fixture.gets !== 1 || !url.startsWith('http://127.0.0.1:54321/api/v1/pa/config/payment_method_types?')
            || options.headers.cookie !== '' || options.headers.referer !== '' || options.headers.authorization
            || options.headers['client-secret'] !== 'PRIVATE_CLIENT_SECRET'
            || options.headers['x-api-version'] !== '2024-06-14'
            || options.headers['x-cloud-trace-context'] !== '0123456789abcdef0123456789abcdef' || options.maxRedirects !== 0 || options.maxRetries !== 0) fail();
        return { status: () => fault.startsWith('http_') ? Number(fault.slice(5)) : fault === 'redirect' ? 302 : 200,
          headers() { if (fault === 'headers') fail(); return { 'set-cookie': 'PRIVATE_COOKIE',
            'content-length': fault === 'declared_oversized' ? '262145' : '100', 'content-type': 'application/json' }; },
          async body() { if (fault === 'body') fail();
            return Buffer.from(['invalid_json', 'abort'].includes(fault) ? 'PRIVATE_INVALID' : fault === 'oversized' ? 'x'.repeat(262145)
              : JSON.stringify({ items: [{ name: 'card' }] })); },
          async dispose() { if (fault === 'response_dispose') fail(); } };
      }, async dispose() { fixture.disposed = true; if (fault === 'context_dispose') fail(); } };
    } }, chromium: { async launch() {
      let handler, opened;
      const mainFrame = {};
      let token;
      const frame = { parentFrame: () => mainFrame, isDetached() {
        if (fault === 'attachment_error') fail();
        if (fault === 'attachment_invalid') return 'PRIVATE_INVALID';
        return fixture.detached;
      },
        async frameElement() { return { async getAttribute() { return 'http://127.0.0.1:54321/render-frame#/elements/dropin?'
          + new URLSearchParams({ appVersion: 'v2.56.0', lang: 'en', options: JSON.stringify({ mode: 'recurring', currency: 'EUR',
            client_secret: 'PRIVATE_CLIENT_SECRET', customer_id: 'PRIVATE_EXTERNAL_CUSTOMER' }) }); }, async dispose() {} }; },
        locator(selector) {
          const match = /^input\\[name="(cardnumber|expiry|cvc)"\\]$/.exec(selector);
          if (!match || fault === 'locator') fail();
          return { async count() {
              if (fault === 'detached_mid_count' || (fault === 'detached_late_field' && match[1] === 'expiry')) {
                fixture.detached = true; fail();
              }
              if (fault === 'detached_after_count') fixture.detached = true;
              if (fault === 'readiness_count') fail(); return 1;
            },
            async isVisible() {
              if (fault === 'detached_visible') { fixture.detached = true; fail(); }
              if (fault === 'readiness_visible') fail(); return true;
            },
            async isEnabled() { if (fault === 'readiness_enabled') fail(); return match[1] !== 'cvc'; } };
        } };
      const routed = (kind, sourceFrame) => handler({ request() { return {
        url: () => 'http://127.0.0.1:54321' + (kind === 'payer' ? '/billing/payer-web-api/checkout?ts=1794130000000'
          : kind === 'frame' ? '/render-frame' : '/api/v1/pa/config/payment_method_types?active=true&page_num=0&page_size=200&transaction_currency=EUR&__verification_methods=true'),
        method: () => 'GET', resourceType: () => kind === 'frame' ? 'document' : 'fetch', frame: () => sourceFrame,
        postData: () => null, headers: () => kind === 'payer' ? { authorization: 'Bearer ' + token }
          : kind === 'frame' ? {} : { 'client-secret': 'PRIVATE_CLIENT_SECRET', 'content-type': 'application/json',
            'x-api-version': '2024-06-14', 'x-client-ip-source': 'awx-checkout', 'x-cloud-trace-context': '0123456789abcdef0123456789abcdef',
            cookie: 'PRIVATE_COOKIE', referer: 'PRIVATE_URL' } };
      }, async abort() {
          if (kind === 'method' && fault === 'detached_route_error') { fixture.detached = true; fail(); }
          if (kind === 'method' && fault === 'abort') fail();
        },
        async fetch() { if (kind === 'method') fail(); return { status: () => 200,
          async body() { return Buffer.from(kind === 'frame' ? '<!doctype html>' : JSON.stringify({ mode: 'SETUP', currency: 'EUR',
            setupModeDetails: { externalCustomerClientSecret: 'PRIVATE_CLIENT_SECRET', billingCustomerExternalId: 'PRIVATE_EXTERNAL_CUSTOMER' } })); },
          async dispose() {} }; },
        async fulfill(options) { if (kind === 'method' && (fault === 'fulfill' || options.headers['set-cookie'])) fail(); },
      });
      const page = { mainFrame: () => mainFrame, setDefaultTimeout() {}, setDefaultNavigationTimeout() {}, on() {},
        getByText() { return { first() { return { async isVisible() { return false; } }; } }; },
        async goto(url) { token = new URL(url).searchParams.get('s');
          await routed('payer', mainFrame); await routed('frame', frame);
          const pending = routed('method', fault === 'wrong_frame' ? mainFrame : frame);
          if (fault === 'delayed') { await Promise.resolve(); fail(); }
          await pending;
          await routed('method', frame); // Replay remains denied, including rejected or malformed outcomes.
          if (fault === 'detached_before') fixture.detached = true;
        } };
      return { async newContext() { return { async addInitScript() {}, async route(_pattern, callback) { handler = callback; },
        async routeWebSocket() {}, on(_event, callback) { opened = callback; }, async newPage() { opened(page); return page; },
        async close() {} }; }, async close() {}, version: () => '157.0.0.0' };
    } } };
  `;
}

for (const fault of ['success', 'redirect', 'http_401', 'http_403', 'http_404', 'http_503', 'invalid_json', 'oversized',
  'declared_oversized', 'context', 'get', 'headers', 'body', 'fulfill', 'response_dispose', 'context_dispose',
  'abort', 'locator', 'readiness_count', 'readiness_visible', 'readiness_enabled', 'delayed', 'wrong_frame',
  'attachment_error', 'attachment_invalid', 'detached_before', 'detached_mid_count', 'detached_after_count',
  'detached_visible', 'detached_late_field', 'detached_route_error']) {
  test(`bound configuration worker keeps once-only headers, response and private failure boundaries: ${fault}`, () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'airwallex-method-module-fixture-'));
    try {
      const modulePath = path.join(directory, 'index.js');
      fs.writeFileSync(modulePath, methodFixtureModule(fault));
      const program = `const fixture = require(${JSON.stringify(modulePath)});
        const { inspect } = require(${JSON.stringify(path.join(__dirname, 'hosted-browser.cjs'))});
        inspect('http://127.0.0.1:54321/pay?s=${sessionToken()}', fixture, 'http://127.0.0.1:54321', null,
          ${JSON.stringify(expectedCheckout)}, true, true).then(result => process.stdout.write(JSON.stringify(result)))
          .catch(error => { process.stdout.write(JSON.stringify({ ...error.evidence(),
            late_context_disposed: fixture.fixture.created && fixture.fixture.disposed,
            no_late_get: fixture.fixture.gets === 0 })); process.exitCode = 1; });`;
      const child = spawnSync(process.execPath, ['-e', program], { encoding: 'utf8', timeout: 4000 });
      assert.equal(child.stderr, '');
      assert.doesNotMatch(child.stdout, /PRIVATE_METHOD|PRIVATE_CLIENT|PRIVATE_EXTERNAL|PRIVATE_COOKIE/u);
      const output = JSON.parse(child.stdout);
      if (['detached_before', 'detached_mid_count', 'detached_after_count', 'detached_visible', 'detached_late_field'].includes(fault)) {
        assert.equal(child.status, 0);
        assert.equal(output.method_config_observation, 'matched');
        assert.equal(output.method_config_http_status, 200);
        assert.equal(output.method_config_payload_delivered, true);
        assert.equal(output.method_config_card_advertised, true);
        assert.equal(output.card_readiness_observed, false);
        assert.equal(output.readiness_frame_detached, true);
        assert.equal(output.readiness_attachment_field, fault === 'detached_late_field' ? 'expiry' : 'cardnumber');
        assert.equal(output.readiness_frame_detached_before_count, fault === 'detached_before');
        if (fault === 'detached_before') assert.equal(Object.hasOwn(output, 'readiness_frame_detached_after_count'), false);
        else assert.equal(output.readiness_frame_detached_after_count, true);

        assert.equal(output.card_readiness_unavailable_reason, 'frame_detached');
        assert.equal(output.readiness_detach_stage, fault === 'detached_before' ? 'before_count' : 'after_count');
        for (const field of ['cardnumber', 'expiry', 'cvc']) for (const suffix of ['unique', 'visible', 'enabled']) {
          assert.equal(Object.hasOwn(output, field + '_' + suffix), false);
        }
      } else if (['success', 'redirect', 'invalid_json', 'oversized', 'declared_oversized', 'wrong_frame'].includes(fault) || fault.startsWith('http_')) {
        assert.equal(child.status, 0);
        assert.equal(output.method_config_get_attempted, true);
        assert.equal(output.method_config_http_status, fault === 'redirect' ? 302 : fault.startsWith('http_') ? Number(fault.slice(5)) : 200);
        assert.equal(output.method_config_payload_delivered, ['success', 'wrong_frame'].includes(fault));
        assert.equal(output.method_config_pagination_known ?? false, false);
        assert.equal(output.card_readiness_observed, true);
        assert.equal(output.cardnumber_visible, true); assert.equal(output.cvc_enabled, false);
        assert.equal(output.denied_read_families.method_config, 1);
      } else {
        assert.equal(child.status, 1); assert.equal(output.result, 'fail');
        const phase = { context: 'method_context', get: 'method_get', headers: 'method_headers', body: 'method_body',
          fulfill: 'method_fulfill', response_dispose: 'method_response_dispose', context_dispose: 'shutdown_request_context',
          abort: 'method_abort', locator: 'readiness_cardnumber_locator', readiness_count: 'readiness_cardnumber_count',
          readiness_visible: 'readiness_cardnumber_visible', readiness_enabled: 'readiness_cardnumber_enabled', delayed: 'navigation',
          attachment_error: 'readiness_attachment', attachment_invalid: 'readiness_attachment', detached_route_error: 'route_action' }[fault];
        assert.equal(output.browser_failure_phase, phase);
        assert.equal(output.method_config_get_attempted, true);
        assert.equal(output.method_config_send_started, !['context', 'delayed'].includes(fault));
        if (['readiness_count', 'readiness_visible', 'readiness_enabled', 'attachment_error', 'attachment_invalid', 'detached_route_error'].includes(fault)) {
          assert.equal(output.method_config_observation, 'matched');
          assert.equal(output.method_config_card_advertised, true);
          assert.equal(output.method_config_http_status, 200);
          if (fault === 'readiness_count') {
            assert.equal(output.readiness_frame_detached_before_count, false);
            assert.equal(output.readiness_frame_detached_after_count, false);
          }
        }

        if (fault === 'delayed') {
          assert.equal(output.late_context_disposed, true); assert.equal(output.no_late_get, true);
        }
      }
    } finally { fs.rmSync(directory, { recursive: true, force: true }); }
  });
}

const normalOrigin = 'https://checkout.sandbox.airwallex.com';
const normalTargets = { customer: normalOrigin + '/api/v1/pa/customers/PRIVATE_EXTERNAL_CUSTOMER',
  consent_list: normalOrigin + '/api/v1/pa/payment_consents?customer_id=PRIVATE_EXTERNAL_CUSTOMER&status=VERIFIED&page_size=100',
  country: 'https://www.sandbox.airwallex.com/country',
  checkout_status: normalOrigin + '/billing/payer-web-api/checkout/status' };
function normalPolicy(options = {}) {
  let now = 0;
  const origin = options.origin ?? normalOrigin;
  const p = new HostedPolicy(origin + '/pay?s=' + sessionToken(), null, expectedCheckout, true, true,
    options.normal ?? true, () => now);
  p.claimPayerRequest(origin + '/billing/payer-web-api/checkout?ts=1794130000000', 'GET', 'fetch',
    { authorization: 'Bearer ' + sessionToken() });
  p.observePayer(Buffer.from(JSON.stringify(privatePayer)));
  p.startNormalWindow();
  if (p.mayDeliverPayer()) p.markPayerDelivered();
  if (p.claimRenderFrame(privateFrame())) p.markRenderFrameDelivered();
  return { p, advance: value => { now = value; } };
}
function normalClaim(p, role, change = {}) {
  return p.claimNormalRead(change.url ?? normalTargets[role], change.method ?? 'GET', change.type ?? 'fetch',
    change.headers ?? (role === 'checkout_status' ? { authorization: 'Bearer ' + sessionToken() }
      : role === 'country' ? {} : methodHeaders), change.body ?? null,
    change.main ?? role === 'checkout_status', change.bound ?? role !== 'checkout_status');
}

test('native Card scope requires delivered REQUESTED plus explicit empty terminal Consents, not invented config delivery', () => {
  const { p, advance } = normalPolicy();
  assert.equal(p.nativeCardChoiceAllowed(privateFrame()), false);
  assert.equal(normalClaim(p, 'consent_list').role, 'consent_list');
  assert.equal(p.observeNormalRead('consent_list', Buffer.from('{"items":[],"has_more":false}')).matched, true);
  p.markNormalReadDelivered('consent_list');
  assert.equal(normalClaim(p, 'checkout_status').role, 'checkout_status');
  p.observeNormalRead('checkout_status', Buffer.from('{"status":"REQUESTED"}'));
  assert.equal(p.nativeCardChoiceAllowed(privateFrame()), false);
  p.markNormalReadDelivered('checkout_status');
  assert.equal(p.nativeCardChoiceAllowed(privateFrame()), true);
  assert.equal(p.methodEvidence().method_config_get_attempted, false);
  assert.equal(p.nativeCardChoiceAllowed(privateFrame() + 'changed'), false);
  assert.equal(p.decide(normalOrigin + '/billing/payer-web-api/checkout/complete', 'POST', 'fetch'), 'block');
  assert.equal(p.decide(normalOrigin + '/api/v1/pa/payment_consents/create', 'POST', 'fetch'), 'block');
  advance(5500); assert.equal(p.nativeCardChoiceAllowed(privateFrame()), false);
});

for (const body of ['{"items":[]}', '{"items":[],"has_more":true}',
  '{"items":[{"id":"fixture","customer_id":"PRIVATE_EXTERNAL_CUSTOMER","status":"VERIFIED"}],"has_more":false}']) {
  test('native Card choice withholds nonterminal or nonempty Consent inventory: ' + body, () => {
    const { p } = normalPolicy(); normalClaim(p, 'consent_list'); p.observeNormalRead('consent_list', Buffer.from(body));
    p.markNormalReadDelivered('consent_list'); normalClaim(p, 'checkout_status');
    p.observeNormalRead('checkout_status', Buffer.from('{"status":"REQUESTED"}')); p.markNormalReadDelivered('checkout_status');
    assert.equal(p.nativeCardChoiceAllowed(privateFrame()), false);
  });
}

test('normal reads are explicit and scoped, each once except three serialized status slots', () => {
  assert.throws(() => new HostedPolicy(initial, null, expectedCheckout, true, false, true));
  for (const options of [{ normal: false }, { origin: 'https://checkout.airwallex.com' }]) {
    const { p } = normalPolicy(options);
    for (const role of Object.keys(normalTargets)) assert.equal(normalClaim(p, role), null);
    assert.equal(p.allowsAuthority('www.sandbox.airwallex.com', 443), false);
  }
  const { p } = normalPolicy();
  for (const role of ['customer', 'consent_list', 'country']) {
    const claim = normalClaim(p, role);
    assert.equal(claim.role, role); assert.equal(claim.headers.cookie, ''); assert.equal(claim.headers.referer, '');
    assert.equal(claim.headers.authorization, undefined);
    assert.equal(normalClaim(p, role), null);
  }
  for (let index = 0; index < 3; index++) {
    assert.equal(normalClaim(p, 'checkout_status').role, 'checkout_status');
    assert.equal(normalClaim(p, 'checkout_status'), null); // In flight is not another poll.
    assert.deepEqual(p.observeNormalRead('checkout_status', Buffer.from('{"status":"REQUESTED"}')), { matched: true, state: 'REQUESTED' });
    assert.equal(normalClaim(p, 'checkout_status'), null); // Parsing alone is not delivered.
    p.markNormalReadDelivered('checkout_status');
  }
  assert.equal(normalClaim(p, 'checkout_status'), null);
  assert.equal(p.normalEvidence().normal_checkout_status_attempts, 3);
  assert.equal(p.allowsAuthority('www.sandbox.airwallex.com', 443), true);
  assert.equal(p.decide('https://www.sandbox.airwallex.com/other', 'GET', 'fetch'), 'block');
  p.closeNormalWindow(); assert.equal(p.allowsAuthority('www.sandbox.airwallex.com', 443), false);
  assert.doesNotMatch(JSON.stringify(p.normalEvidence()), /PRIVATE/u);
});

for (const role of Object.keys(normalTargets)) {
  test('normal read exact target, role, header and namespace boundary: ' + role, () => {
    const { p } = normalPolicy();
    for (const change of [{ method: 'POST' }, { type: 'document' }, { body: 'PRIVATE' },
      { url: normalTargets[role] + '#' }, { url: normalTargets[role] + '&PRIVATE=value' },
      { url: normalTargets[role].replace('sandbox.airwallex.com', 'airwallex.com') },
      { main: false, bound: false }, { headers: { ...methodHeaders, authorization: 'PRIVATE' } },
      { headers: { ...methodHeaders, 'x-on-behalf-of': 'PRIVATE' } },
      { headers: { ...methodHeaders, baggage: 'PRIVATE' } }]) {
      assert.equal(normalClaim(p, role, change), null);
    }
    if (role !== 'checkout_status') assert.equal(normalClaim(p, role, { main: true, bound: false }), null);
    else assert.equal(normalClaim(p, role, { main: false, bound: true }), null);
    assert.equal(p.normalEvidence()['normal_' + role + '_attempts'], 0);
    assert.notEqual(normalClaim(p, role), null);
  });
}

test('Customer and list bind exact Payments namespace, query literals and required source headers', () => {
  for (const url of [normalTargets.customer.replace('PRIVATE_EXTERNAL_CUSTOMER', 'BILLING_CUSTOMER'),
    normalTargets.customer + '/extra', normalTargets.customer.replace('PRIVATE_EXTERNAL_CUSTOMER', '%50RIVATE_EXTERNAL_CUSTOMER'),
    normalTargets.consent_list + '&page_num=1', normalTargets.consent_list + '&status=VERIFIED',
    normalTargets.consent_list.replace('status=VERIFIED', 'status=DISABLED'),
    normalTargets.consent_list.replace('page_size=100', 'page_size=200'),
    normalTargets.consent_list.replace('customer_id=', '%63ustomer_id='),
    normalTargets.consent_list.replace('PRIVATE_EXTERNAL_CUSTOMER', 'OTHER'),
    normalTargets.consent_list.replace('PRIVATE_EXTERNAL_CUSTOMER', '%50RIVATE_EXTERNAL_CUSTOMER')]) {
    const { p } = normalPolicy();
    assert.equal(normalClaim(p, url.includes('consents') ? 'consent_list' : 'customer', { url }), null);
  }
  for (const key of ['client-secret', 'x-api-version', 'content-type', 'x-client-ip-source', 'x-cloud-trace-context']) {
    for (const value of [undefined, 'PRIVATE_OTHER']) {
      const { p } = normalPolicy();
      assert.equal(normalClaim(p, 'customer', { headers: { ...methodHeaders, [key]: value } }), null);
    }
  }
});

for (const [role, valid, invalid] of [
  ['customer', { id: 'PRIVATE_EXTERNAL_CUSTOMER' }, [{ id: 'OTHER' }, { id: 123 }, {}]],
  ['consent_list', { items: [] }, [{ items: [{ id: 'c', customer_id: 'OTHER', status: 'VERIFIED' }] },
    { items: [{ id: 'c', customer_id: 'PRIVATE_EXTERNAL_CUSTOMER', status: 'DISABLED' }] },
    { items: [null] }, { items: Array(101).fill({}) }, { items: [], has_more: 'false' }]],
  ['country', { countryCode: 'DE' }, [{ countryCode: 'PRIVATE' }, {}, { countryCode: 12 }]],
  ['checkout_status', { status: 'REQUESTED' }, [{ status: 'CANCELLED' }, { status: true }, {}]],
]) {
  test('normal response ownership/shape and bounded unique UTF8 JSON: ' + role, () => {
    const { p } = normalPolicy(); normalClaim(p, role);
    assert.equal(p.observeNormalRead(role, Buffer.from(JSON.stringify(valid))).matched, true);
    for (const value of invalid) assert.equal(p.observeNormalRead(role, Buffer.from(JSON.stringify(value))).matched, false);
    for (const body of ['null', '[]', 'x'.repeat(65537), Buffer.from([0xff]),
      '{"id":"PRIVATE_EXTERNAL_CUSTOMER","\\u0069d":"PRIVATE_EXTERNAL_CUSTOMER"}',
      '{"nested":' + '['.repeat(33) + '0' + ']'.repeat(33) + '}']) {
      assert.equal(p.observeNormalRead(role, Buffer.from(body)).matched, false);
    }
  });
}

for (const [body, state, matched] of [
  ['{"status":"REQUESTED","PRIVATE":"PRIVATE_GATEWAY_VALUE"}', 'REQUESTED', true],
  ['{"status":"PENDING"}', 'PENDING', false],
  ['{"status":"COMPLETED"}', 'COMPLETED', false],
  ['{"status":"EXPIRED"}', 'EXPIRED', false],
  ['{"status":"ACTIVE"}', 'unlisted', false], // Public SDK state is not a browser-gateway state.
  ['{"status":"OPEN"}', 'unlisted', false],
  ['{"status":"PRIVATE_UNLISTED_STATE"}', 'unlisted', false],
  ['{"data":{"status":"REQUESTED"}}', 'unavailable', false],
  ['{"checkout":{"status":"REQUESTED"}}', 'unavailable', false],
  ['{"status":true}', 'unavailable', false],
  ['{"status":null}', 'unavailable', false],
  ['{"status":"REQUESTED","\\u0073tatus":"REQUESTED"}', 'unavailable', false],
  ['[]', 'unavailable', false],
  [Buffer.from([0xff]), 'unavailable', false],
]) {
  test('browser gateway status has its own closed vocabulary: ' + String(body), () => {
    const { p } = normalPolicy(); normalClaim(p, 'checkout_status');
    const observed = p.observeNormalRead('checkout_status', Buffer.from(body));
    assert.deepEqual(observed, { matched, state });
    assert.doesNotMatch(JSON.stringify(observed), /PRIVATE|ACTIVE|OPEN/u);
    p.markNormalReadDelivered('checkout_status');
    assert.equal(p.normalEvidence().normal_checkout_status_poisoned, !matched);
    assert.equal(normalClaim(p, 'checkout_status') !== null, matched);
  });
}

test('Consent pagination uncertainty and unknown fields remain private, without discovery', () => {
  for (const tail of [{}, { has_more: false }, { has_more: true }]) {
    const { p } = normalPolicy(); normalClaim(p, 'consent_list');
    const observed = p.observeNormalRead('consent_list', Buffer.from(JSON.stringify({
      items: [{ id: 'PRIVATE_CONSENT', customer_id: 'PRIVATE_EXTERNAL_CUSTOMER', status: 'VERIFIED',
        payment_method: { PRIVATE: 'PRIVATE_CARD' } }], ...tail })));
    assert.deepEqual(observed, { matched: true, items: 1, pagination_known: Object.hasOwn(tail, 'has_more'),
      has_more: tail.has_more === true });
    assert.doesNotMatch(JSON.stringify(observed), /PRIVATE/u);
  }
});

test('failed/invalid status permanently poisons later slots; absolute window and closure forbid further sends', () => {
  for (const kind of ['failed_send', 'invalid_response']) {
    const { p } = normalPolicy(); normalClaim(p, 'checkout_status');
    if (kind === 'failed_send') p.failNormalRead('checkout_status');
    else p.observeNormalRead('checkout_status', Buffer.from('{"status":"CANCELLED"}'));
    p.markNormalReadDelivered('checkout_status');
    assert.equal(normalClaim(p, 'checkout_status'), null);
    assert.equal(p.normalEvidence().normal_checkout_status_poisoned, true);
  }
  for (const millis of [8000, 8001, 99999]) {
    const { p, advance } = normalPolicy(); advance(millis);
    for (const role of Object.keys(normalTargets)) assert.equal(normalClaim(p, role), null);
    assert.equal(p.allowsAuthority('www.sandbox.airwallex.com', 443), false);
  }
  const { p } = normalPolicy(); p.closeNormalWindow();
  for (const role of Object.keys(normalTargets)) assert.equal(normalClaim(p, role), null);
  for (const path of ['/#/elements/card-number', '/#/elements/expiry', '/#/elements/cvc', '/#/frame/popup']) {
    assert.equal(p.claimRenderFrame(normalOrigin + path), false);
  }
});

function normalFixtureModule(fault, role = 'checkout_status') {
  const source = 'http://127.0.0.1:54321/render-frame#/elements/dropin?' + new URLSearchParams({
    appVersion: 'v2.56.0', lang: 'en', options: JSON.stringify({ mode: 'recurring', currency: 'EUR',
      client_secret: 'PRIVATE_CLIENT_SECRET', customer_id: 'PRIVATE_EXTERNAL_CUSTOMER' }) });
  let module = methodFixtureModule('success')
    .replace('on(_event, callback) { opened = callback; }', "on(event, callback) { if (event === 'close') fixture.browserClosed = callback; else opened = callback; }")
    .replace('async close() {} }; }, async close() {}', 'async close() { fixture.browserClosed(); } }; }, async close() {}')
    .replace('const mainFrame = {};', 'const mainFrame = { url: () => fixture.mainUrl, isDetached: () => false };')
    .replace('const frame = { parentFrame:', 'const frame = { url: () => fixture.frameUrl, parentFrame:')
    .replace('const fault = "success";', `const fault = ${JSON.stringify('normal_' + fault)}; let constructions = 0;`)
    .replace('[5000, 10000].includes(delay)', '[5000, 5500, 10000].includes(delay)')
    .replace('module.exports = { fixture, request:', `fixture.now = 0; fixture.frameUrl = ${JSON.stringify(source)}; fixture.normalGets = 0; fixture.normalDisposed = false;
      Object.defineProperty(performance, 'now', { value: () => fixture.now });
      module.exports = { fixture, request:`)
    .replace('async newContext(options) {', `async newContext(options) {
      const normal = ++constructions > 1;
      if (normal) {
        fixture.normalCreated = true;
        if (fault === 'normal_changed_before_get') fixture.mainUrl = 'about:blank';
        if (fault === 'normal_context') fail();
        if (fault === 'normal_delayed') await new Promise(resolve => setTimeout(resolve, 30));
        if (fault === 'normal_window_edge') fixture.now = 9000;
      }`)
    .replace('fixture.gets++;', `fixture.gets++;
        if (normal) {
          fixture.normalGets++;
          if (fault === 'normal_get' || options.headers.authorization !== 'Bearer ' + fixture.token
              || options.headers['client-secret'] || options.headers.cookie !== '' || options.headers.referer !== ''
              || options.maxRedirects !== 0 || options.maxRetries !== 0) fail();
        }`)
    .replace("if (fault === 'get' || fixture.gets !== 1", "if (!normal && (fault === 'get' || fixture.gets !== 1")
    .replace('|| options.maxRedirects !== 0 || options.maxRetries !== 0) fail();\n        return',
      '|| options.maxRedirects !== 0 || options.maxRetries !== 0)) fail();\n        return')
    .replace("return { status: () => fault.startsWith('http_')", `return { headersArray() {
          if (normal && fault === 'normal_headers') fail();
          return [{ name: 'Content-Type', value: 'application/json' }, { name: 'Set-Cookie', value: 'PRIVATE_COOKIE' }];
        }, status: () => normal && fault === 'normal_redirect' ? 302 : fault.startsWith('http_')`)
    .replace("async body() { if (fault === 'body') fail();", `async body() { if (normal && fault === 'normal_changed_during_fetch') fixture.mainUrl += '#PRIVATE_WRONG_FRAGMENT';
          if (fault === 'body' || (normal && fault === 'normal_body')) fail();`)
    .replace("JSON.stringify({ items: [{ name: 'card' }] })", "JSON.stringify(normal ? { status: fault === 'normal_invalid' ? 'CANCELLED' : 'REQUESTED' } : { items: [{ name: 'card' }] })")
    .replace("async dispose() { if (fault === 'response_dispose')", "async dispose() { if (fault === 'response_dispose' || (normal && fault === 'normal_response_dispose'))")
    .replace("fixture.disposed = true; if (fault === 'context_dispose')", "fixture.disposed = true; if (normal) fixture.normalDisposed = true; if (fault === 'context_dispose' || (normal && fault === 'normal_context_dispose'))")
    .replace(": kind === 'frame' ? '/render-frame' : '/api/v1/pa/config", ": kind === 'frame' ? '/render-frame' : kind === 'normal' ? '/billing/payer-web-api/checkout/status' : '/api/v1/pa/config")
    .replace("headers: () => kind === 'payer' ?", "headers: () => ['payer', 'normal'].includes(kind) ?")
    .replace("async fulfill(options) { if (kind === 'method'", `async fulfill(options) {
          if (kind === 'normal' && (fault === 'normal_fulfill' || options.headers['Set-Cookie'])) fail();
          if (kind === 'method'`)
    .replace("token = new URL(url).searchParams.get('s');", "token = new URL(url).searchParams.get('s'); fixture.token = token; fixture.mainUrl = url;")
    .replace("          await pending;\n          await routed('method', frame);", `          await pending;
          const normalPending = routed('normal', mainFrame);
          if (fault === 'normal_delayed') { await Promise.resolve(); fail(); }
          await normalPending;
          if (!['normal_success', 'normal_context_dispose'].includes(fault)) await routed('normal', mainFrame);
          await routed('method', frame);`);
  module = module
    .replace("async dispose() { if (fault === 'response_dispose'", `async dispose() {
      if (normal && ['normal_dispose_pending_resolve', 'normal_dispose_pending_reject'].includes(fault)) {
        fixture.disposeEntered = true;
        await new Promise((resolve, reject) => { fixture.releaseDispose = () => fault.endsWith('reject')
          ? reject(new Error('PRIVATE_DISPOSE_FAILURE')) : resolve(); });
      }
      if (fault === 'response_dispose'`)
    .replace('          await normalPending;', `          if (['normal_dispose_pending_resolve', 'normal_dispose_pending_reject'].includes(fault)) {
            while (!fixture.disposeEntered) await new Promise(resolve => setImmediate(resolve));
            await routed('normal', mainFrame);
            fixture.beforeReleaseGets = fixture.normalGets;
            fixture.releaseDispose();
          }
          await normalPending;`)
    .replace("['normal_success', 'normal_context_dispose'].includes(fault)",
      "['normal_success', 'normal_context_dispose', 'normal_dispose_pending_resolve'].includes(fault)");
  if (role === 'customer') module = module
    .replace("fixture.mainUrl = 'about:blank'", "fixture.frameUrl = 'about:blank'")
    .replace("fixture.mainUrl += '#PRIVATE_WRONG_FRAGMENT'", "fixture.frameUrl += '#PRIVATE_WRONG_FRAGMENT'")
    .replace("options.headers.authorization !== 'Bearer ' + fixture.token", "options.headers['client-secret'] !== 'PRIVATE_CLIENT_SECRET'")
    .replace("|| options.headers['client-secret'] || options.headers.cookie !== ''", "|| options.headers.authorization || options.headers.cookie !== ''")
    .replace("kind === 'normal' ? '/billing/payer-web-api/checkout/status'", "kind === 'normal' ? '/api/v1/pa/customers/PRIVATE_EXTERNAL_CUSTOMER'")
    .replace("['payer', 'normal'].includes(kind)", "kind === 'payer'")
    .replace("routed('normal', mainFrame)", "routed('normal', frame)")
    .replace("if (!['normal_success', 'normal_context_dispose'].includes(fault)) await routed('normal', mainFrame)",
      "if (!['normal_success', 'normal_context_dispose'].includes(fault)) await routed('normal', frame)")
    .replace("normal ? { status: fault === 'normal_invalid' ? 'CANCELLED' : 'REQUESTED' }", "normal ? { id: 'PRIVATE_EXTERNAL_CUSTOMER' }");
  return module;
}

for (const fault of ['success', 'redirect', 'invalid', 'context', 'get', 'headers', 'body', 'fulfill',
  'response_dispose', 'context_dispose', 'delayed', 'window_edge', 'changed_before_get', 'changed_during_fetch']) {
  test('normal read worker isolates contexts, poisons failures and disposes across window/shutdown races: ' + fault, () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'airwallex-normal-module-fixture-'));
    try {
      const modulePath = path.join(directory, 'index.js');
      fs.writeFileSync(modulePath, normalFixtureModule(fault));
      const program = `const fixture = require(${JSON.stringify(modulePath)});
        const { inspect } = require(${JSON.stringify(path.join(__dirname, 'hosted-browser.cjs'))});
        const finish = result => process.stdout.write(JSON.stringify({ ...result,
          normal_context_disposed: fixture.fixture.normalDisposed, normal_fixture_gets: fixture.fixture.normalGets }));
        inspect('http://127.0.0.1:54321/pay?s=${sessionToken()}', fixture, 'http://127.0.0.1:54321', null,
          ${JSON.stringify(expectedCheckout)}, true, true, true).then(finish)
          .catch(error => { finish(error.evidence()); process.exitCode = 1; });`;
      const child = spawnSync(process.execPath, ['-e', program], { encoding: 'utf8', timeout: 4000 });
      assert.equal(child.stderr, '');
      assert.doesNotMatch(child.stdout, /PRIVATE_CLIENT|PRIVATE_EXTERNAL|PRIVATE_COOKIE|PRIVATE_METHOD/u);
      const output = JSON.parse(child.stdout);
      assert.equal(output.normal_checkout_status_attempts, 1);
      assert.equal(output.normal_fixture_gets, ['context', 'delayed', 'window_edge', 'changed_before_get'].includes(fault) ? 0 : 1);
      if (fault !== 'context') assert.equal(output.normal_context_disposed, true);
      assert.equal(output.normal_read_window_closed, true);
      if (['success', 'redirect', 'invalid', 'changed_during_fetch'].includes(fault)) {
        assert.equal(child.status, 0);
        assert.equal(output.normal_checkout_status_delivered, fault === 'success' ? 1 : 0);
        assert.equal(output.normal_checkout_status_poisoned, fault !== 'success');
        assert.equal(output.normal_read_inspection_completed, true);
      } else {
        assert.equal(child.status, 1);
        if (fault !== 'context_dispose') assert.equal(output.normal_checkout_status_poisoned, true);
        if (fault === 'window_edge') {
          assert.equal(output.card_readiness_observed, false);
          assert.equal(Object.hasOwn(output, 'cardnumber_unique'), false);
        }
      }
    } finally { fs.rmSync(directory, { recursive: true, force: true }); }
  });
}


for (const [statusBody, state, matched] of [
  [{ status: 'REQUESTED' }, 'REQUESTED', true], [{ status: 'PENDING' }, 'PENDING', false],
  [{ status: 'COMPLETED' }, 'COMPLETED', false], [{ status: 'EXPIRED' }, 'EXPIRED', false],
  [{ status: 'ACTIVE' }, 'unlisted', false], [{ status: 'OPEN' }, 'unlisted', false],
  [{ status: 'PRIVATE_UNLISTED_STATE' }, 'unlisted', false], [{ data: { status: 'REQUESTED' } }, 'unavailable', false],
]) {
  test('status worker and PHP bridge retain only the web classification: ' + state + '/' + JSON.stringify(statusBody), () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'airwallex-web-status-fixture-'));
    try {
      const modulePath = path.join(directory, 'index.js');
      fs.writeFileSync(modulePath, normalFixtureModule('success').replace(
        "normal ? { status: fault === 'normal_invalid' ? 'CANCELLED' : 'REQUESTED' }",
        'normal ? ' + JSON.stringify(statusBody)));
      const program = `const fixture = require(${JSON.stringify(modulePath)});
        const { inspect } = require(${JSON.stringify(path.join(__dirname, 'hosted-browser.cjs'))});
        inspect('http://127.0.0.1:54321/pay?s=${sessionToken()}', fixture, 'http://127.0.0.1:54321', null,
          ${JSON.stringify(expectedCheckout)}, true, true, true).then(result => process.stdout.write(JSON.stringify(result)));`;
      const child = spawnSync(process.execPath, ['-e', program], { encoding: 'utf8', timeout: 4000 });
      assert.equal(child.status, 0); assert.equal(child.stderr, '');
      assert.doesNotMatch(child.stdout, /PRIVATE|https?:|ACTIVE|OPEN/u);
      const output = JSON.parse(child.stdout);
      assert.equal(output.normal_checkout_status_state, state);
      assert.equal(output.normal_checkout_status_delivered, matched ? 1 : 0);
      assert.equal(output.normal_checkout_status_observation, matched ? 'matched' : 'invalid_response');
      assert.equal(output.normal_checkout_status_poisoned, !matched);
      assert.equal(output.normal_checkout_status_sends, 1);
      const php = String.raw`require getcwd().'/vendor/autoload.php';
        $data=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
        echo json_encode(\Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedBrowser::sanitize($data,true,true,true),JSON_THROW_ON_ERROR);`;
      const bridge = spawnSync('php', ['-r', php], { encoding: 'utf8', cwd: path.join(__dirname, '../..'),
        input: JSON.stringify({ ...output, candidate_origin: 'sandbox', result: 'blocked',
          reason: 'financial_sandbox_boundary_unverified' }) });
      assert.equal(bridge.status, 0, bridge.stderr); assert.equal(bridge.stderr, '');
      const safe = JSON.parse(bridge.stdout);
      assert.equal(safe.normal_checkout_status_state, state);
      assert.equal(safe.normal_checkout_status_delivered, matched ? 1 : 0);
      assert.doesNotMatch(bridge.stdout, /PRIVATE|https?:|ACTIVE|OPEN/u);
    } finally { fs.rmSync(directory, { recursive: true, force: true }); }
  });
}

test('an unparsed later status response cannot inherit a prior parsed gateway classification', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'airwallex-web-status-polls-fixture-'));
  try {
    const modulePath = path.join(directory, 'index.js');
    fs.writeFileSync(modulePath, normalFixtureModule('success')
      .replace("normal && fault === 'normal_redirect'", 'normal && fixture.normalGets > 1')
      .replace("if (!['normal_success', 'normal_context_dispose', 'normal_dispose_pending_resolve'].includes(fault))", 'if (true)'));
    const program = `const fixture = require(${JSON.stringify(modulePath)});
      const { inspect } = require(${JSON.stringify(path.join(__dirname, 'hosted-browser.cjs'))});
      inspect('http://127.0.0.1:54321/pay?s=${sessionToken()}', fixture, 'http://127.0.0.1:54321', null,
        ${JSON.stringify(expectedCheckout)}, true, true, true).then(result => process.stdout.write(JSON.stringify(result)));`;
    const child = spawnSync(process.execPath, ['-e', program], { encoding: 'utf8', timeout: 4000 });
    assert.equal(child.status, 0); assert.equal(child.stderr, '');
    const output = JSON.parse(child.stdout);
    assert.equal(output.normal_checkout_status_sends, 2);
    assert.equal(output.normal_checkout_status_delivered, 1);
    assert.equal(output.normal_checkout_status_observation, 'redirect_rejected');
    assert.equal(output.normal_checkout_status_poisoned, true);
    assert.equal(Object.hasOwn(output, 'normal_checkout_status_state'), false);
    assert.doesNotMatch(child.stdout, /PRIVATE|https?:/u);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('new read scope binds the exact original private fragment and permanent poison after awaits', () => {
  const { p } = normalPolicy();
  assert.equal(p.normalSourceMatches(privateFrame()), true);
  for (const url of ['about:blank', privateFrame() + '&PRIVATE=OTHER', privateFrame({ customer_id: 'OTHER' }),
    privateFrame().replace('/elements/dropin', '/elements/cvc')]) assert.equal(p.normalSourceMatches(url), false);
  normalClaim(p, 'checkout_status');
  assert.equal(p.normalSendAllowed('checkout_status'), true);
  p.failNormalRead('checkout_status');
  assert.equal(p.normalSendAllowed('checkout_status'), false);
});


for (const fault of ['changed_before_get', 'changed_during_fetch']) {
  test('same bound Drop-in Frame changing document cannot inherit a Customer read: ' + fault, () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'airwallex-normal-document-fixture-'));
    try {
      const modulePath = path.join(directory, 'index.js');
      fs.writeFileSync(modulePath, normalFixtureModule(fault, 'customer'));
      const program = `const fixture = require(${JSON.stringify(modulePath)});
        const { inspect } = require(${JSON.stringify(path.join(__dirname, 'hosted-browser.cjs'))});
        const finish = data => process.stdout.write(JSON.stringify({ ...data,
          fixture_gets: fixture.fixture.normalGets, context_disposed: fixture.fixture.normalDisposed }));
        inspect('http://127.0.0.1:54321/pay?s=${sessionToken()}', fixture, 'http://127.0.0.1:54321', null,
          ${JSON.stringify(expectedCheckout)}, true, true, true).then(finish)
          .catch(error => finish(error.evidence()));`;
      const child = spawnSync(process.execPath, ['-e', program], { encoding: 'utf8', timeout: 4000 });
      assert.equal(child.stderr, '');
      assert.doesNotMatch(child.stdout, /PRIVATE_CLIENT|PRIVATE_EXTERNAL|PRIVATE_COOKIE|PRIVATE_WRONG/u);
      const output = JSON.parse(child.stdout);
      assert.equal(output.normal_customer_attempts, 1);
      assert.equal(output.normal_customer_delivered, 0);
      assert.equal(output.fixture_gets, fault === 'changed_before_get' ? 0 : 1);
      assert.equal(output.context_disposed, true);
    } finally { fs.rmSync(directory, { recursive: true, force: true }); }
  });
}


for (const outcome of ['resolve', 'reject']) {
  test('status slot stays busy across response disposal and poisons rejection before later polls: ' + outcome, () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'airwallex-normal-disposal-fixture-'));
    try {
      const modulePath = path.join(directory, 'index.js');
      fs.writeFileSync(modulePath, normalFixtureModule('dispose_pending_' + outcome));
      const program = `const fixture = require(${JSON.stringify(modulePath)});
        const { inspect } = require(${JSON.stringify(path.join(__dirname, 'hosted-browser.cjs'))});
        const finish = result => process.stdout.write(JSON.stringify({ ...result,
          gets_before_disposal: fixture.fixture.beforeReleaseGets, gets_after_disposal: fixture.fixture.normalGets }));
        inspect('http://127.0.0.1:54321/pay?s=${sessionToken()}', fixture, 'http://127.0.0.1:54321', null,
          ${JSON.stringify(expectedCheckout)}, true, true, true).then(finish)
          .catch(error => finish(error.evidence()));`;
      const child = spawnSync(process.execPath, ['-e', program], { encoding: 'utf8', timeout: 4000 });
      assert.equal(child.stderr, '');
      assert.doesNotMatch(child.stdout, /PRIVATE_DISPOSE|PRIVATE_CLIENT|PRIVATE_EXTERNAL/u);
      const output = JSON.parse(child.stdout);
      assert.equal(output.gets_before_disposal, 1);
      assert.equal(output.gets_after_disposal, 1);
      assert.equal(output.normal_checkout_status_attempts, 1);
      assert.equal(output.normal_checkout_status_poisoned, outcome === 'reject');
      assert.equal(output.normal_checkout_status_delivered, 1); // Fulfilled before disposal, without another send.
      if (outcome === 'reject') assert.equal(output.browser_failure_phase, 'normal_response_dispose');
    } finally { fs.rmSync(directory, { recursive: true, force: true }); }
  });
}

for (const mode of ['request_failure', 'deadline', 'context_closed', 'shutdown', 'scope', 'body', 'body_cleanup', 'status', 'status_cleanup']) {
  test('normal failure records only owned timing and lifecycle facts: ' + mode, () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'airwallex-normal-timing-fixture-'));
    try {
      let source = normalFixtureModule(mode === 'scope' ? 'window_edge' : mode.startsWith('body') ? 'body'
        : mode === 'status' ? 'success' : mode === 'status_cleanup' ? 'response_dispose' : 'get');
      if (mode.startsWith('status')) source = source.replace("normal && fault === 'normal_redirect' ? 302",
        "normal ? 99");
      if (mode === 'body_cleanup') source = source.replace('async dispose() {\n      if (normal',
        'async dispose() {\n      if (normal) { fixture.now = 9000; fixture.browserClosed(); }\n      if (normal');
      if (mode === 'deadline') source = source
        .replace('timeout(callback, [5000, 5500, 10000].includes(delay) ? 1 : delay, ...args)',
          "delay === 8000 ? (fixture.deadline = callback, timeout(() => {}, 0)) : timeout(callback, [5000, 5500, 10000].includes(delay) ? 1 : delay, ...args)")
        .replace("if (fault === 'normal_get' ||", "fixture.now = 8000; fixture.deadline(); if (fault === 'normal_get' ||");
      if (mode === 'request_failure') source = source
        .replace("if (fault === 'normal_get' ||", "fixture.now = 35; if (fault === 'normal_get' ||")
        .replace('[5000, 5500, 10000].includes(delay)', '(delay >= 5000 && delay <= 10000 && delay !== 8000)');
      if (mode === 'context_closed') source = source
        .replace("if (fault === 'normal_get' ||", "fixture.browserClosed(); if (fault === 'normal_get' ||");
      if (mode === 'shutdown') source = source
        .replace('timeout(callback, [5000, 5500, 10000].includes(delay) ? 1 : delay, ...args)',
          "timeout(() => { if (delay === 8000) fixture.now = 8000; callback(...args); }, delay === 8000 ? 10 : [5000, 5500, 10000].includes(delay) ? 1 : delay)")
        .replace("if (fault === 'normal_get' ||", "await new Promise((_, reject) => { fixture.rejectGet = () => reject(new Error('PRIVATE_PENDING_URL')); }); if (fault === 'normal_get' ||")
        .replace('          await normalPending;', '          while (!fixture.normalGets) await new Promise(resolve => setImmediate(resolve)); fail();')
        .replace('fixture.disposed = true;', 'if (normal && fixture.rejectGet) fixture.rejectGet(); fixture.disposed = true;');
      const modulePath = path.join(directory, 'index.js');
      fs.writeFileSync(modulePath, source);
      const program = `const fixture = require(${JSON.stringify(modulePath)});
        const { inspect } = require(${JSON.stringify(path.join(__dirname, 'hosted-browser.cjs'))});
        const finish = result => process.stdout.write(JSON.stringify({ ...result, fixture_gets: fixture.fixture.normalGets }));
        inspect('http://127.0.0.1:54321/pay?s=${sessionToken()}', fixture, 'http://127.0.0.1:54321', null,
          ${JSON.stringify(expectedCheckout)}, true, true, true).then(finish).catch(error => finish(error.evidence()));`;
      const child = spawnSync(process.execPath, ['-e', program], { encoding: 'utf8', timeout: 4000 });
      assert.equal(child.status, 0); assert.equal(child.stderr, '');
      assert.doesNotMatch(child.stdout, /PRIVATE|https?:|Error:/u);
      const output = JSON.parse(child.stdout), failure = output.normal_read_failure;
      assert.equal(output.fixture_gets, mode === 'scope' ? 0 : 1);
      assert.equal(output.normal_checkout_status_poisoned, true);
      assert.equal(failure.role, 'checkout_status');
      assert.equal(failure.phase, mode === 'scope' ? 'normal_scope' : mode.startsWith('body') ? 'normal_body'
        : mode.startsWith('status') ? 'normal_status' : 'normal_get');
      assert.equal(failure.deadline_fired, ['deadline', 'shutdown'].includes(mode));
      assert.equal(failure.shutdown_started, ['deadline', 'shutdown'].includes(mode));
      assert.equal(failure.browser_context_closed, ['deadline', 'shutdown', 'context_closed'].includes(mode));
      assert.equal(failure.browser_context_close_started, ['deadline', 'shutdown'].includes(mode));
      assert.equal(failure.request_context_dispose_started, mode === 'shutdown');
      if (mode === 'scope') assert.equal(Object.hasOwn(failure, 'request_timeout_ms'), false);
      else assert.equal(failure.request_timeout_ms, 8000);
      if (mode === 'request_failure') {
        assert.equal(failure.elapsed_ms, 35); assert.equal(failure.request_elapsed_ms, 35);
        assert.equal(failure.window_remaining_ms, 7965);
      }
      if (mode === 'body_cleanup') {
        assert.equal(failure.window_remaining_ms, 8000); assert.equal(failure.elapsed_ms, 0);
      }
    } finally { fs.rmSync(directory, { recursive: true, force: true }); }
  });
}

test('a delivered Customer response remains a matched fact when later disposal fails', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'airwallex-normal-delivered-fixture-'));
  try {
    const modulePath = path.join(directory, 'index.js');
    fs.writeFileSync(modulePath, normalFixtureModule('response_dispose', 'customer'));
    const program = `const fixture = require(${JSON.stringify(modulePath)});
      const { inspect } = require(${JSON.stringify(path.join(__dirname, 'hosted-browser.cjs'))});
      const finish = result => process.stdout.write(JSON.stringify(result));
      inspect('http://127.0.0.1:54321/pay?s=${sessionToken()}', fixture, 'http://127.0.0.1:54321', null,
        ${JSON.stringify(expectedCheckout)}, true, true, true).then(finish).catch(error => finish(error.evidence()));`;
    const child = spawnSync(process.execPath, ['-e', program], { encoding: 'utf8', timeout: 4000 });
    assert.equal(child.status, 0); assert.equal(child.stderr, '');
    assert.doesNotMatch(child.stdout, /PRIVATE|https?:|Error:/u);
    const output = JSON.parse(child.stdout);
    assert.equal(output.result, 'fail');
    assert.equal(output.normal_customer_sends, 1); assert.equal(output.normal_customer_delivered, 1);
    assert.equal(output.normal_customer_http_status, 200);
    assert.equal(output.normal_customer_observation, 'matched');
    assert.equal(output.normal_read_failure.role, 'customer');
    assert.equal(output.normal_read_failure.phase, 'normal_response_dispose');
    const php = String.raw`require getcwd().'/vendor/autoload.php';
      $data=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
      echo json_encode(\Jojeringnax\Airwallex\Tests\Support\Sandbox\Journeys\HostedBrowser::sanitize($data,true,true,true),JSON_THROW_ON_ERROR);`;
    // The local fixture has no provider origin; supply only that fixed authority proof to the PHP bridge.
    const sanitized = spawnSync('php', ['-r', php], { encoding: 'utf8', cwd: path.join(__dirname, '../..'),
      input: JSON.stringify({ ...output, candidate_origin: 'sandbox' }) });
    assert.equal(sanitized.status, 0); assert.equal(sanitized.stderr, '');
    const safe = JSON.parse(sanitized.stdout);
    assert.equal(safe.result, 'fail'); assert.equal(safe.normal_customer_delivered, 1);
    assert.equal(safe.normal_customer_observation, 'matched');
    assert.equal(safe.normal_read_failure.phase, 'normal_response_dispose');
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

function normalDrainFixture(mode) {
  return normalFixtureModule('success')
    .replace('globalThis.setTimeout = (callback, delay, ...args) => timeout(callback, [5000, 5500, 10000].includes(delay) ? 1 : delay, ...args);',
      `globalThis.setTimeout = (callback, delay, ...args) => {
        if (delay === 8000) fixture.deadline = callback;
        return timeout(callback, delay >= 500 ? delay / 100 : delay, ...args);
      };`)
    .replace('fixture.normalCreated = true;', `fixture.normalCreated = true; fixture.now = 5000;
      if (${JSON.stringify(mode)} === 'context') await new Promise(resolve => timeout(resolve, 15));`)
    .replace('fixture.normalGets++;', `fixture.normalGets++;
      await new Promise((resolve, reject) => {
        fixture.rejectNormal = () => reject(new Error('PRIVATE_PENDING_READ'));
        timeout(() => {
          fixture.now = ${mode === 'deadline' ? 8000 : 6000};
          if (${JSON.stringify(mode)} === 'deadline') fixture.deadline();
          if (${JSON.stringify(mode)} === 'transport') reject(new Error('PRIVATE_TRANSPORT'));
          else if (${JSON.stringify(mode)} !== 'deadline') resolve();
        }, 15);
      });`)
    .replace('          await normalPending;', `          fixture.normalPending = normalPending;
          while (!fixture.${mode === 'context' ? 'normalCreated' : 'normalGets'}) await new Promise(resolve => setImmediate(resolve));`)
    .replace("if (fault === 'readiness_count') fail(); return 1;", "if (fault === 'readiness_count') fail(); return fixture.statusDelivered ? 1 : 0;")
    .replace("kind === 'normal' ? '/billing/payer-web-api/checkout/status'", "kind === 'normal' ? '/billing/payer-web-api/checkout/status' : kind === 'late_customer' ? '/api/v1/pa/customers/PRIVATE_EXTERNAL_CUSTOMER'")
    .replace("if (kind === 'normal' && (fault", `if (kind === 'normal') {
      fixture.statusDelivered = true;
      if (${JSON.stringify(mode)} === 'late_admission') await routed('late_customer', frame);
    } if (kind === 'normal' && (fault`)
    .replace('async dispose() {\n      if (normal', `async dispose() {
      if (normal && ${JSON.stringify(mode)} === 'dispose') {
        await new Promise(resolve => timeout(resolve, 10)); fixture.now = 7000;
      }
      if (normal && ${JSON.stringify(mode)} === 'dispose_failure') fail();
      if (normal`)
    .replace("async body() { if (normal && fault", `async body() {
      if (normal && ${JSON.stringify(mode)} === 'stalled_body') await new Promise((_, reject) => {
        fixture.rejectBody = reject;
        timeout(() => { fixture.now = 8000; fixture.deadline(); }, 10);
      });
      if (normal && ${JSON.stringify(mode)} === 'changed_source') fixture.mainUrl += '#PRIVATE_CHANGED';
      if (normal && fault`)
    .replace('fixture.disposed = true;', `if (normal && fixture.rejectNormal) fixture.rejectNormal();
      if (normal && fixture.rejectBody) fixture.rejectBody(new Error('PRIVATE_BODY')); fixture.disposed = true;`);
}

for (const mode of ['complete', 'transport', 'deadline', 'context', 'dispose', 'dispose_failure', 'stalled_body', 'changed_source', 'late_admission']) {
  test('already sent five-second status drains within its original bound: ' + mode, () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'airwallex-normal-drain-fixture-'));
    try {
      const modulePath = path.join(directory, 'index.js');
      fs.writeFileSync(modulePath, normalDrainFixture(mode));
      const program = `const fixture = require(${JSON.stringify(modulePath)});
        const { inspect } = require(${JSON.stringify(path.join(__dirname, 'hosted-browser.cjs'))});
        const finish = data => process.stdout.write(JSON.stringify({ ...data, fixture_gets: fixture.fixture.normalGets,
          late_delivered: fixture.fixture.statusDelivered === true }));
        inspect('http://127.0.0.1:54321/pay?s=${sessionToken()}', fixture, 'http://127.0.0.1:54321', null,
          ${JSON.stringify(expectedCheckout)}, true, true, true).then(finish).catch(error => finish(error.evidence()));`;
      const child = spawnSync(process.execPath, ['-e', program], { encoding: 'utf8', timeout: 4000 });
      assert.equal(child.status, 0); assert.equal(child.stderr, '');
      const output = JSON.parse(child.stdout);
      const delivered = ['complete', 'dispose', 'dispose_failure', 'late_admission'].includes(mode);
      assert.equal(output.result, ['complete', 'dispose', 'changed_source', 'late_admission'].includes(mode) ? 'pass' : 'fail', JSON.stringify(output.normal_read_failure));
      assert.equal(output.fixture_gets, mode === 'context' ? 0 : 1);
      assert.equal(output.normal_checkout_status_sends, mode === 'context' ? 0 : 1);
      assert.equal(output.normal_checkout_status_delivered, delivered ? 1 : 0);
      assert.equal(output.normal_checkout_status_poisoned, !['complete', 'dispose', 'late_admission'].includes(mode));
      assert.equal(output.late_delivered, delivered);
      if (mode === 'late_admission') assert.equal(output.normal_customer_attempts, 0);
      for (const field of ['cardnumber', 'expiry', 'cvc']) assert.equal(output[field + '_unique'], false);
      if (['complete', 'dispose', 'changed_source', 'late_admission'].includes(mode)) {
        assert.equal(Object.hasOwn(output, 'normal_read_failure'), false);
      } else {
        const failure = output.normal_read_failure;
        assert.equal(failure.phase, mode === 'context' ? 'normal_scope' : mode === 'dispose_failure'
          ? 'normal_response_dispose' : mode === 'stalled_body' ? 'normal_body' : 'normal_get');
        if (mode === 'transport') {
          assert.equal(failure.shutdown_started, false); assert.equal(failure.browser_context_closed, false);
          assert.equal(failure.window_remaining_ms, 2000);
        }
        if (['deadline', 'stalled_body'].includes(mode)) assert.equal(failure.deadline_fired, true);
      }
      assert.doesNotMatch(child.stdout, /PRIVATE|https?:|Error:/u);
    } finally { fs.rmSync(directory, { recursive: true, force: true }); }
  });
}
