'use strict';
const test = require('node:test'),
  assert = require('node:assert/strict');
const { InstrumentConfig, clientKey } = require('./instrument-config.cjs');
const { gzipSync } = require('node:zlib');
const origin = 'https://checkout.sandbox.airwallex.com',
  sid = '55555555-5555-4555-8555-555555555555';
function fixture(change = () => {}) {
  const body = {
    user: { userID: 'seller_synthetic', statsigEnvironment: { tier: 'staging' }, analyticsOnlyMetadata: {} },
    hash: 'djb2',
    deltasResponseRequested: false,
    full_checksum: null,
    statsigMetadata: {
      sdkVersion: '3.20.3',
      sdkType: 'javascript-client-react',
      stableID: '66666666-6666-4666-8666-666666666666',
      sessionID: sid,
      fallbackUrl: null
    }
  };
  change(body);
  const meta = {
    url:
      'https://featureassets.org/v1/initialize?' +
      new URLSearchParams({
        k: clientKey,
        se: '1',
        sid,
        st: 'javascript-client-react',
        sv: '3.20.3',
        t: String(Date.now())
      }),
    method: 'POST',
    type: 'fetch',
    sourceRole: 'main',
    headers: { origin, referer: origin + '/', 'content-type': 'text/plain;charset=UTF-8' },
    body: Buffer.from(Buffer.from(JSON.stringify(body)).toString('base64').split('').reverse().join(''))
  };
  return {
    policy: new InstrumentConfig({
      initial: origin + '/pay?s=SYNTHETIC',
      payer: { sellerId: 'seller_synthetic' }
    }),
    meta
  };
}
test('source feature initializer preserves original encoded bytes and requires a fresh evaluated response', () => {
  const { policy, meta } = fixture(),
    claim = policy.claim(meta);
  assert.deepEqual(claim.body, meta.body);
  assert.equal(policy.ready(), false);
  policy.validate(claim, {
    status: 200,
    headers: { 'content-type': 'application/json', 'access-control-allow-origin': origin },
    body: Buffer.from('{"has_updates":true,"feature_gates":{}}')
  });
  policy.delivered(claim);
  assert.equal(policy.ready(), true);
  assert.throws(() => policy.claim(meta));
});

function initialized() {
  const f = fixture((body) => {
    body.statsigMetadata.sdkType = 'javascript-client';
  });
  const claim = f.policy.claim(f.meta);
  f.policy.validate(claim, {
    status: 200,
    headers: { 'content-type': 'application/json', 'access-control-allow-origin': origin },
    body: Buffer.from('{"has_updates":true}')
  });
  f.policy.delivered(claim);
  return f;
}
function events(f, gzip = true, change = () => {}) {
  const body = {
    events: [
      { eventName: 'statsig::diagnostics', metadata: { currentPage: origin + '/pay?s=SYNTHETIC_CAPABILITY' } }
    ],
    statsigMetadata: {
      sdkVersion: '3.20.3',
      sdkType: 'javascript-client',
      stableID: '66666666-6666-4666-8666-666666666666',
      sessionID: sid,
      fallbackUrl: null
    }
  };
  change(body);
  const params = new URL(f.meta.url).searchParams;
  params.delete('se');
  params.set('ec', String(body.events.length));
  if (gzip) params.set('gz', '1');
  return {
    ...f.meta,
    url: 'https://prodregistryv2.org/v1/rgstr?' + params,
    headers: {
      origin,
      referer: origin + '/',
      ...(gzip ? {} : { 'content-type': 'text/plain;charset=UTF-8' })
    },
    body: gzip ? gzipSync(JSON.stringify(body)) : Buffer.from(JSON.stringify(body))
  };
}
test('source telemetry preserves gzip/raw bytes and permits the reviewed React binding race', () => {
  const f = initialized();
  for (const gzip of [true, false]) {
    const meta = events(f, gzip),
      claim = f.policy.claim(meta);
    assert.deepEqual(claim.body, meta.body);
    assert.equal(claim.headers['content-type'], gzip ? undefined : 'text/plain;charset=UTF-8');
    f.policy.validate(claim, {
      status: 204,
      headers: { 'access-control-allow-origin': origin },
      body: Buffer.alloc(0)
    });
    f.policy.delivered(claim);
  }
  const reverse = events(f);
  const url = new URL(reverse.url);
  url.searchParams.set('st', 'javascript-client');
  reverse.url = url.href;
  assert.throws(() => f.policy.claim(reverse));
});
test('telemetry rejects foreign identity, private headers, gzip expansion and source-count overflow', () => {
  for (const mutate of [
    (body) => {
      body.statsigMetadata.sessionID = 'foreign';
    },
    (body) => {
      body.statsigMetadata.stableID = 'foreign';
    }
  ]) {
    const f = initialized();
    assert.throws(() => f.policy.claim(events(f, true, mutate)));
  }
  const f = initialized(),
    bomb = events(f);
  bomb.body = gzipSync('x'.repeat(65537));
  assert.throws(() => f.policy.claim(bomb));
  const privateHeader = events(f);
  privateHeader.headers.cookie = 'SYNTHETIC';
  assert.throws(() => f.policy.claim(privateHeader));
  for (let index = 0; index < 20; index++) assert.equal(f.policy.claim(events(f)).ordinal, index);
  assert.throws(() => f.policy.claim(events(f)));
});
for (const [label, mutate] of [
  [
    'seller',
    (body) => {
      body.user.userID = 'foreign';
    }
  ],
  [
    'personal',
    (body) => {
      body.user.email = 'real@example.test';
    }
  ],
  [
    'tier',
    (body) => {
      body.user.statsigEnvironment.tier = 'production';
    }
  ],
  [
    'cached',
    (body) => {
      body.sinceTime = 123;
    }
  ],
  [
    'fallback',
    (body) => {
      body.statsigMetadata.fallbackUrl = 'https://foreign.invalid';
    }
  ]
]) {
  test('feature initializer rejects ' + label + ' before send', () => {
    const { policy, meta } = fixture(mutate);
    assert.throws(() => policy.claim(meta));
  });
}
test('feature families cannot grant API headers, returned hosts, cookies or no-update defaults', () => {
  for (const name of ['authorization', 'client-secret', 'cookie', 'x-api-key']) {
    const { policy, meta } = fixture();
    meta.headers[name] = 'SYNTHETIC';
    assert.throws(() => policy.claim(meta));
  }
  for (const body of ['{"has_updates":false}', '{"has_updates":true,"has_updates":false}']) {
    const { policy, meta } = fixture(),
      claim = policy.claim(meta);
    assert.throws(() =>
      policy.validate(claim, {
        status: 200,
        headers: { 'content-type': 'application/json', 'access-control-allow-origin': origin },
        body: Buffer.from(body)
      })
    );
  }
  const { policy, meta } = fixture();
  assert.equal(policy.claim({ ...meta, url: 'https://cloudflare-dns.com/dns-query' }), null);
});
