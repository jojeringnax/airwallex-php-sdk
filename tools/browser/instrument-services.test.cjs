'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { InstrumentServices, canonical, query, namespace, googleKey } = require('./instrument-services.cjs');

const release = 'SYNTHETIC_RELEASE',
  google = 'https://www.recaptcha.net',
  checkout = 'https://checkout.sandbox.airwallex.com';
function fixture() {
  const reads = { initial: checkout + '/pay?s=SYNTHETIC', base: {} };
  const risk = {
    loaderTarget: (url) =>
      url.startsWith('https://static.sandbox.airwallex.com/webapp/fraud/device-fingerprint/index.js?'),
    claimLoader: () => ({}),
    verifyLoader: (body) => body.toString() === 'SYNTHETIC_LOADER'
  };
  return new InstrumentServices(reads, risk);
}
function meta(url, role = 'dropin', type = 'script', method = 'GET', body = '') {
  return {
    url,
    method,
    type,
    sourceRole: role,
    sourceURL: (role === 'google' ? google : checkout) + '/',
    headers: { accept: '*/*' },
    body: Buffer.from(body)
  };
}
function googleEntry(policy) {
  const claim = policy.claim(meta(google + '/recaptcha/enterprise.js?render=' + googleKey + '&hl=en'));
  policy.validate(claim, {
    status: 200,
    headers: { 'content-type': 'application/javascript' },
    body: Buffer.from('https://www.gstatic.com/recaptcha/releases/' + release + '/recaptcha__en.js')
  });
  return claim;
}

test('both eager captcha entries are admissible before delayed first-party loader proof', () => {
  const policy = fixture();
  assert.equal(googleEntry(policy).role, 'google_entry');
  const tencent = policy.claim(meta('https://turing.captcha.qcloud.com/TCaptcha.js'));
  assert.equal(tencent.role, 'tencent_entry');
  policy.validate(tencent, {
    status: 200,
    headers: { 'content-type': 'text/javascript' },
    body: Buffer.from('synthetic')
  });
  assert.equal(
    policy.claim(meta('https://turing.captcha.gtimg.com/1/tcaptcha-frame.2dfcc096.js')).role,
    'tencent_static'
  );
  assert.equal(
    policy.claim({
      ...meta('https://turing.captcha.gtimg.com/1/template/drag_ele.html', 'dropin', 'document'),
      parentRole: 'dropin',
      elementId: 'tcaptcha_iframe_dy',
      hidden: true
    }).role,
    'tencent_preload'
  );
  assert.equal(policy.loaderVerified, false);
});
test('Google native worker initial route precedes its object, while three source executions have separate budgets', () => {
  const policy = fixture();
  googleEntry(policy);
  const asset = 'https://www.gstatic.com/recaptcha/releases/' + release + '/recaptcha__en.js';
  assert.equal(policy.claim(meta(asset)).role, 'google_main_release');
  assert.equal(policy.claim(meta(asset, 'google')).role, 'google_anchor_release');
  const initial = policy.claim(
    meta(google + '/recaptcha/enterprise/webworker.js?hl=en&v=' + release, 'google', 'other')
  );
  assert.equal(initial.role, 'google_worker_initial');
  assert.equal(policy.claim({ ...meta(asset, 'google'), workerBound: true }).role, 'google_worker_release');
  assert.throws(() => policy.claim({ ...meta(asset, 'google'), workerBound: true }));
});
test('unknown release, visible/foreign anchor, interactive endpoints and unsafe native worker requests are withheld', () => {
  const policy = fixture();
  googleEntry(policy);
  const anchor = google + '/recaptcha/enterprise/anchor?k=' + googleKey + '&v=' + release;
  assert.equal(
    policy.claim({ ...meta(anchor + '&size=normal', 'dropin', 'document'), parentRole: 'dropin' }),
    null
  );
  assert.equal(
    policy.claim({ ...meta(anchor + '&size=invisible', 'dropin', 'document'), parentRole: 'risk' }),
    null
  );
  assert.equal(policy.claim(meta('https://www.gstatic.com/recaptcha/releases/OTHER/recaptcha__en.js')), null);
  assert.throws(() => policy.claim(meta(google + '/recaptcha/enterprise/bframe', 'google', 'document')));
  assert.throws(() => policy.claim(meta(google + '/recaptcha/enterprise/payload', 'google', 'image')));
  assert.equal(
    policy.claim({
      ...meta(google + '/recaptcha/enterprise/reload', 'google', 'fetch', 'POST', 'opaque'),
      worker: true
    }),
    null
  );
  assert.equal(policy.claim(meta('https://unreviewed.example/recaptcha/enterprise.js')), null);
});
test('family namespace trust bounds dynamic data without granting returned hosts or API headers', () => {
  const policy = fixture();
  googleEntry(policy);
  const request = meta(
    google + '/recaptcha/enterprise/reload?opaque=source-value',
    'google',
    'fetch',
    'POST',
    'opaque source payload'
  );
  const claim = policy.claim(request);
  assert.deepEqual(claim.body, request.body);
  assert.equal(claim.responseLimit, 65536);
  assert.equal(policy.claim({ ...request, url: google + '/recaptcha/enterprise/reload?x=one&x=two' }), null);
  for (const name of ['client-secret', 'authorization', 'x-on-behalf-of', 'x-api-key']) {
    assert.throws(() =>
      fixture().claim({
        ...meta('https://turing.captcha.qcloud.com/TCaptcha.js'),
        headers: { [name]: 'SYNTHETIC_PRIVATE_VALUE' }
      })
    );
  }
});
test('Tencent static grant requires its eager entry and never grants execution, arbitrary frames or POST', () => {
  const policy = fixture(),
    asset = 'https://turing.captcha.gtimg.com/1/dy-ele.2bed7b36.js';
  assert.equal(policy.claim(meta(asset)), null);
  const entry = policy.claim(meta('https://turing.captcha.qcloud.com/TCaptcha.js'));
  policy.validate(entry, {
    status: 200,
    headers: { 'content-type': 'application/javascript' },
    body: Buffer.from('synthetic')
  });
  assert.equal(policy.claim(meta(asset)).role, 'tencent_static');
  assert.equal(
    policy.claim(meta('https://turing.captcha.qcloud.com/cap_union_prehandle', 'dropin', 'fetch')),
    null
  );
  assert.equal(policy.claim(meta(asset, 'tencent', 'fetch', 'POST')), null);
  assert.equal(
    policy.claim({
      ...meta('https://turing.captcha.gtimg.com/1/template/drag_ele.html', 'dropin', 'document'),
      parentRole: 'dropin',
      elementId: 'tcaptcha_iframe_dy',
      hidden: false
    }),
    null
  );
});
test('canonical URI and query grammar rejects aliases, malformed escapes, traversal and oversized data', () => {
  for (const value of [
    'http://example.com/a',
    'https://user@example.com/a',
    'https://example.com:444/a',
    'https://EXAMPLE.com/a',
    'https://example.com/a#x',
    'https://example.com/a%2fb'
  ])
    assert.equal(canonical(value), null);
  for (const value of [
    'https://example.com/a?x=one&%78=two',
    'https://example.com/a?x=%GG',
    'https://example.com/a?x=%00',
    'https://example.com/a?x=' + 'a'.repeat(2049)
  ])
    assert.equal(query(new URL(value)), false);
  assert.equal(namespace('../script.js'), false);
  assert.equal(namespace('a/b/c/d/e/f/g'), false);
  assert.equal(namespace('template/drag_ele.html'), true);
});
