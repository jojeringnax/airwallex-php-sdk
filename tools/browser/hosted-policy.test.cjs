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

test('all payer data and financial paths remain blocked even on the candidate origin', () => {
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
    JSON.stringify({ url: initial, extra: 'PRIVATE_SESSION' }), 'x'.repeat(20001)]) {
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
          const target = fault === 'abort' ? 'https://unknown.invalid/SYNTHETIC_PRIVATE_URL'
            : fault === 'asset_integrity' ? 'https://checkout.airwallex.com/pay/static/v2.286.0/js/main.ddc4fcf3b752ddbd5f14.js' : url;
          await routeHandler({
            request() { return { url: () => target, method: () => 'GET',
              resourceType: () => fault === 'asset_integrity' ? 'script' : 'document',
              headers: () => ({ referer: 'SYNTHETIC_PRIVATE_URL' }) }; },
            async abort() { if (fault === 'abort') fail(); },
            async fetch(options) {
              if (fault === 'fetch' || options.maxRedirects !== 0 || options.maxRetries !== 0
                  || 'referer' in options.headers) fail();
              return { status: () => 200, body: async () => Buffer.from('<!doctype html>'),
                async dispose() { if (fault === 'dispose') fail(); } };
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
  'browser_close', 'asset_integrity']) {
  test(`async actions and cleanup have a fixed private failure boundary: ${fault}`, () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'airwallex-browser-module-fixture-'));
    try {
      fs.writeFileSync(path.join(directory, 'index.js'), fixtureModule(fault));
      const child = spawnSync(process.execPath, [path.join(__dirname, 'hosted-browser.cjs'), directory],
        { input: JSON.stringify({ url: initial }), encoding: 'utf8', timeout: 4000 });
      assert.equal(child.stderr, '');
      assert.doesNotMatch(child.stdout, /SYNTHETIC_PRIVATE_URL|SYNTHETIC_SECRET/u);
      const output = JSON.parse(child.stdout);
      assert.equal(output.card_entry_enabled, false);
      if (fault === 'none') {
        assert.equal(child.status, 0);
        assert.equal(output.result, 'blocked');
      } else {
        assert.equal(child.status, 1);
        assert.deepEqual(output, { result: 'fail', reason: 'browser_preflight_failed', card_entry_enabled: false });
      }
    } finally { fs.rmSync(directory, { recursive: true, force: true }); }
  });
}
