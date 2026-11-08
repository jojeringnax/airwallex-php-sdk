'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const util = require('node:util');
const { HostedPolicy } = require('./hosted-policy.cjs');
const { ParentCountryObserver } = require('./hosted-parent-country.cjs');
const origin = 'https://checkout.sandbox.airwallex.com';
const target = 'https://www.sandbox.airwallex.com/country';
const token = Buffer.from('{"alg":"SYNTHETIC"}').toString('base64url') + '.'
  + Buffer.from(JSON.stringify({ resource_id: 'checkout:SYNTHETIC_CHECKOUT', exp: 4070908800 })).toString('base64url') + '.SYNTHETIC';
const initial = origin + '/pay?s=' + token;
const expected = { checkout_id: 'SYNTHETIC_CHECKOUT', mode: 'SETUP', currency: 'EUR' };
function policy(enabled = true) {
  let now = 1000;
  const p = new HostedPolicy(initial, null, expected, true, true, true, () => now, enabled);
  p.noteParentMainDocument(initial);
  return { p, advance: value => { now += value; } };
}
function claim(p, changes = {}) {
  return p.claimParentCountry(changes.url ?? target, changes.method ?? 'GET', changes.type ?? 'xhr',
    changes.headers ?? { accept: 'application/json, text/plain, */*', origin, cookie: 'PRIVATE', referer: initial },
    changes.body ?? null, changes.main ?? true);
}
test('parent country is explicit, starts only at initial document delivery, and does not start the normal clock', () => {
  const { p } = policy();
  assert.equal(claim(p), null); assert.equal(p.parentCountryWindowRemaining(), 0);
  p.startParentCountryWindow();
  assert.equal(p.parentCountryWindowRemaining(), 8000); assert.equal(p.normalWindowRemaining(), 0);
  assert.equal(p.allowsAuthority('www.sandbox.airwallex.com', 443), false);
  const headers = claim(p);
  assert.deepEqual(headers, { accept: 'application/json, text/plain, */*', cookie: '', referer: '', origin });
  assert.equal(p.allowsAuthority('www.sandbox.airwallex.com', 443), true);
  assert.equal(p.allowsAuthority('www.airwallex.com', 443), false);
  assert.equal(claim(p), null); assert.equal(p.parentCountryEvidence().parent_country_attempts, 1);
  assert.equal(p.normalEvidence().normal_country_attempts, 0);
  const disabled = policy(false).p; disabled.startParentCountryWindow(); assert.equal(claim(disabled), null);
  assert.deepEqual(disabled.parentCountryEvidence(), {});
});
test('a later main navigation cannot restart or inherit the original document role even at the same URL', () => {
  for (const url of [initial, 'https://checkout.sandbox.airwallex.com/other']) {
    const { p } = policy(); p.startParentCountryWindow(); p.noteParentMainDocument(url);
    assert.equal(p.parentCountryWindowRemaining(), 0); p.startParentCountryWindow();
    assert.equal(claim(p), null); assert.equal(p.parentCountryEvidence().parent_country_attempts, 0);
  }
});
test('strict original parent route/header/body context cannot be substituted', () => {
  for (const change of [{ main: false }, { method: 'POST' }, { type: 'document' }, { body: 'PRIVATE' },
    { url: target + '?country=PRIVATE' }, { url: target + '#' }, { url: target + '/extra' },
    { url: target.replace('www.sandbox', 'www') }, { url: target.replace('/country', '/%63ountry') },
    { url: target.replace('https://', 'https://SYNTHETIC@') },
    { headers: { authorization: 'Bearer PRIVATE' } }, { headers: { 'client-secret': 'PRIVATE' } },
    { headers: { 'x-api-version': '2024-06-14' } }, { headers: { origin: 'https://other.test' } },
    { headers: { 'content-type': 'application/json' } }, { headers: { accept: '*/*' } }]) {
    const { p } = policy(); p.startParentCountryWindow();
    assert.equal(claim(p, change), null); assert.equal(p.parentCountryEvidence().parent_country_attempts, 0);
  }
});
test('startup is never reset and post-payer use is clipped without extending its separate normal window', () => {
  const { p, advance } = policy(); p.startParentCountryWindow(); advance(5000); p.startParentCountryWindow();
  assert.equal(p.parentCountryWindowRemaining(), 3000);
  assert.equal(p.claimPayerRequest(origin + '/billing/payer-web-api/checkout?ts=1794130000000', 'GET', 'xhr',
    { authorization: 'Bearer ' + token }), true);
  p.observePayer(Buffer.from('{"mode":"SETUP","currency":"EUR"}')); p.startNormalWindow(); p.markPayerDelivered();
  assert.equal(p.normalWindowRemaining(), 8000); assert.equal(p.parentCountryWindowRemaining(), 3000);
  advance(3000); assert.equal(claim(p), null); assert.equal(p.normalWindowRemaining(), 5000);
});
for (const body of ['{"countryCode":"PRIVATE"}', '{}', '{"countryCode":null}', '[]',
  '{"countryCode":"DE","\\u0063ountryCode":"DE"}', Buffer.from([0xff]), 'x'.repeat(65537)]) {
  test('bounded country shape stays private and a failed admitted response never earns another slot: ' + String(body).slice(0, 50), () => {
    const { p } = policy(); p.startParentCountryWindow(); assert.ok(claim(p));
    assert.equal(p.observeParentCountry(Buffer.from(body)), false); assert.equal(p.parentCountrySendAllowed(), false);
    assert.equal(claim(p), null); assert.doesNotMatch(JSON.stringify(p.parentCountryEvidence()), /PRIVATE|countryCode/u);
  });
}
function fixture(fault = '') {
  const { p } = policy(); p.startParentCountryWindow();
  if (fault === 'timeout' || fault === 'late_get' || fault.startsWith('stalled_')) p.parentCountryWindowRemaining = () => 25;
  let current = initial, closing = false, latched = false, admissionChecks = 0, gets = 0, disposed = 0, responseDisposed = 0,
    delivered = 0, aborts = 0, bodyReads = 0, constructed = false, release;
  const frame = { isDetached: () => false, url: () => current };
  const response = { status: () => fault === 'bad_status' ? 99 : fault === 'redirect' ? 302 : fault === 'http' ? 403 : 200,
    headersArray: async () => [{ name: 'Content-Type', value: 'application/json' },
      ...(fault === 'bad_headers' ? [{ name: 'Content-Type', value: 'application/json' }]
        : [{ name: 'Set-Cookie', value: 'PRIVATE_COOKIE' }])],
    body: async () => { bodyReads++;
      if (fault === 'stalled_body') return new Promise(resolve => { release = () => resolve(Buffer.from('{"countryCode":"DE"}')); });
      return Buffer.from(fault === 'malformed' ? '{"countryCode":12}' : '{"countryCode":"DE","PRIVATE":"PRIVATE"}'); },
    dispose: async () => { responseDisposed++; if (fault === 'stalled_dispose') return new Promise(() => {}); } };
  const client = { dispose: async () => { disposed++; }, get: async (url, options) => {
    gets++; assert.equal(url, target); assert.equal(options.maxRedirects, 0); assert.equal(options.maxRetries, 0);
    assert.equal(options.headers.cookie, ''); assert.equal(options.headers.referer, '');
    assert.equal(options.headers.authorization, undefined); assert.equal(options.headers['client-secret'], undefined);
    if (fault === 'get') throw new Error('PRIVATE_URL_COOKIE');
    if (fault === 'changed_fetch') current = 'about:blank';
    if (fault === 'timeout' || fault === 'case_deadline') return new Promise(() => {});
    if (fault === 'late_get') return new Promise(resolve => { release = () => resolve(response); });
    return response;
  } };
  const observer = new ParentCountryObserver(p, true, { request: { newContext: async () => {
    constructed = true;
    if (fault.startsWith('stalled_context')) return new Promise(resolve => { release = () => resolve(client); });
    if (fault === 'latch') latched = true;
    if (fault === 'changed_context') current = 'about:blank';
    return client;
  } } }, fault === 'case_deadline' ? performance.now() + 25 : Infinity); observer.start('http://127.0.0.1:1234');
  const request = { frame: () => frame, url: () => target, method: () => 'GET', resourceType: () => 'xhr',
    headers: () => ({ origin, accept: 'application/json, text/plain, */*' }), postData: () => null };
  const route = { request: () => request, fulfill: async options => {
    assert.equal(options.headers['Set-Cookie'], undefined); delivered++;
    if (fault === 'stalled_fulfill') return new Promise(() => {});
  }, abort: async () => { aborts++; } };
  return { p, observer, frame, route, start: () => observer.handle(route, frame, () => closing, () => {
      admissionChecks++; return latched || (fault === 'late_latch' && admissionChecks >= 4);
    }),
    counters: () => ({ gets, disposed, responseDisposed, delivered, aborts, bodyReads, constructed }),
    closeScope: () => { closing = true; }, release: () => release() };
}
for (const fault of ['', 'redirect', 'http', 'malformed', 'get', 'changed_context', 'changed_fetch', 'latch', 'late_latch', 'timeout', 'stalled_dispose', 'stalled_body', 'stalled_fulfill',
  'bad_status', 'bad_headers', 'case_deadline']) {
  test('one isolated parent read has bounded source/latch/response/privacy and no replay: ' + (fault || 'matched'), async () => {
    const f = fixture(fault); assert.equal(await f.start(), true);
    assert.equal(await f.start(), false);
    await f.observer.close(); const data = f.observer.evidence(), c = f.counters();
    assert.equal(data.parent_country_attempts, 1);
    assert.equal(c.gets, ['changed_context', 'latch', 'late_latch'].includes(fault) ? 0 : 1);
    assert.equal(c.delivered, ['', 'stalled_dispose', 'stalled_fulfill'].includes(fault) ? 1 : 0);
    assert.equal(c.disposed, 1);
    if (['redirect', 'http', 'bad_status', 'bad_headers'].includes(fault)) assert.equal(c.bodyReads, 0);
    assert.doesNotMatch(JSON.stringify(data), /PRIVATE|countryCode|https?:/u);
    assert.doesNotMatch(util.inspect(f.observer), /PRIVATE|SYNTHETIC/u); assert.throws(() => JSON.stringify(f.observer));
  });
}
test('a response arriving after the original deadline is only disposed and never parsed or delivered', async () => {
  const f = fixture('late_get'); assert.equal(await f.start(), true); await f.observer.close();
  assert.equal(f.counters().gets, 1); assert.equal(f.counters().bodyReads, 0); assert.equal(f.counters().delivered, 0);
  f.release(); for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve));
  assert.equal(f.counters().responseDisposed, 1); assert.equal(f.counters().gets, 1); assert.equal(f.counters().delivered, 0);
});
test('late context construction remains tracked after bounded failure and cannot invoke GET', async () => {
  const f = fixture('stalled_context'); const task = f.start();
  while (!f.counters().constructed) await new Promise(resolve => setImmediate(resolve));
  assert.equal(await f.observer.close(), false); assert.equal(await task, true); assert.equal(f.counters().gets, 0);
  f.release(); for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve));
  assert.equal(f.counters().disposed, 1); assert.equal(f.counters().gets, 0);
});
