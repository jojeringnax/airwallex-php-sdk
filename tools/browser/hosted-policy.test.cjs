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
const { HostedPolicy, egressProxy, staticAssets, assetMatches } = require('./hosted-policy.cjs');
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
      result: 'fail', reason: 'browser_preflight_failed', card_entry_enabled: false,
    });
  }
});

function fixtureModule(fault) {
  return `
    'use strict';
    const fault = ${JSON.stringify(fault)};
    const fail = () => { throw new Error('SYNTHETIC_PRIVATE_URL'); };
    module.exports = { chromium: { async launch(options) {
      if (!options.headless || !options.proxy || !options.args.includes('--disable-quic')) fail();
      let routeHandler, websocketHandler, pageHandler, dialogHandler;
      const page = {
        setDefaultTimeout() {}, setDefaultNavigationTimeout() {},
        on(name, handler) { if (name === 'dialog') dialogHandler = handler; },
        getByText() { return { first() { return { async isVisible() { return false; } }; } }; },
        async goto(url) {
          const payer = fault.startsWith('payer_');
          const target = payer ? 'https://checkout.sandbox.airwallex.com/billing/payer-web-api/checkout?ts=1794130000000' : fault === 'abort' ? 'https://unknown.invalid/SYNTHETIC_PRIVATE_URL'
            : fault === 'asset_integrity' ? 'https://checkout.airwallex.com/pay/static/v2.286.0/js/main.ddc4fcf3b752ddbd5f14.js' : url;
          await routeHandler({
            request() { return { url: () => target, method: () => 'GET',
              resourceType: () => payer ? 'fetch' : fault === 'asset_integrity' ? 'script' : 'document',
              headers: () => ({ referer: 'SYNTHETIC_PRIVATE_URL', authorization: 'Bearer ' + new URL(url).searchParams.get('s') }), postData: () => null }; },
            async abort() { if (fault === 'abort' || fault === 'payer_abort') fail(); },
            async fetch(options) {
              if (fault === 'fetch' || fault === 'payer_fetch' || options.maxRedirects !== 0 || options.maxRetries !== 0
                  || 'referer' in options.headers || (payer && options.headers.cookie !== '')) fail();
              return { status: () => fault.startsWith('payer_http_') ? Number(fault.slice(11)) : 200, body: async () => { if (fault === 'payer_body') fail(); return Buffer.from('<!doctype html>'); },
                async dispose() { if (fault === 'dispose' || fault === 'payer_dispose') fail(); } };
            },
            async fulfill() { if (fault === 'fulfill') fail(); },
          });
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
  'browser_close', 'asset_integrity', 'payer_fetch', 'payer_body', 'payer_dispose', 'payer_abort', 'payer_http_401', 'payer_http_403', 'payer_http_404', 'payer_http_503']) {
  test(`async actions and cleanup have a fixed private failure boundary: ${fault}`, () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'airwallex-browser-module-fixture-'));
    try {
      fs.writeFileSync(path.join(directory, 'index.js'), fixtureModule(fault));
      const child = spawnSync(process.execPath, [path.join(__dirname, 'hosted-browser.cjs'), directory],
        { input: JSON.stringify({ url: fault.startsWith('payer_')
          ? 'https://checkout.sandbox.airwallex.com/pay?s=' + sessionToken() : initial, expected: { checkout_id: 'SYNTHETIC_CHECKOUT', mode: 'SETUP', currency: 'EUR' } }), encoding: 'utf8', timeout: 4000 });
      assert.equal(child.stderr, '');
      assert.doesNotMatch(child.stdout, /SYNTHETIC_PRIVATE_URL|SYNTHETIC_SECRET/u);
      const output = JSON.parse(child.stdout);
      assert.equal(output.card_entry_enabled, false);
      if (fault === 'none' || fault.startsWith('payer_http_')) {
        assert.equal(child.status, 0);
        if (fault.startsWith('payer_http_')) {
          assert.equal(output.payer_http_status, Number(fault.slice(11)));
          assert.equal(output.payer_observation, 'http_rejected');
          assert.equal(output.payer_get_attempted, true);
        }
        assert.equal(output.result, 'blocked');
      } else {
        assert.equal(child.status, 1);
        assert.deepEqual(output, { result: 'fail', reason: 'browser_preflight_failed', card_entry_enabled: false });
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
function boundPolicy(origin = 'https://checkout.sandbox.airwallex.com', token = sessionToken(), expected = expectedCheckout) {
  return new HostedPolicy(origin + '/pay?s=' + token, null, expected);
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
    financial_routing_verified: false });
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
