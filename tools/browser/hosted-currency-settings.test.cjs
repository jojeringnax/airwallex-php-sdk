'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const util = require('node:util');
const { HostedPolicy } = require('./hosted-policy.cjs');
const { CurrencySettingsObserver } = require('./hosted-currency-settings.cjs');
const origin = 'https://checkout.sandbox.airwallex.com';
const target = origin + '/api/v1/pa/config/currency_settings';
const token = Buffer.from('{"alg":"SYNTHETIC"}').toString('base64url') + '.'
  + Buffer.from(JSON.stringify({ resource_id: 'checkout:SYNTHETIC_CHECKOUT', exp: 4070908800 })).toString('base64url') + '.SYNTHETIC';
const initial = origin + '/pay?s=' + token;
const expected = { checkout_id: 'SYNTHETIC_CHECKOUT', mode: 'SETUP', currency: 'EUR' };
const good = '{"items":[{"type":"currency_switcher","currencies":["EUR","USD"]}],"PRIVATE":"PRIVATE"}';
function setup({ enabled = true, delivered = true, secret = 'PRIVATE_TRANSACTION', parent = false,
  initialUrl = initial, body = null } = {}) {
  let now = 1000;
  const p = new HostedPolicy(initialUrl, null, expected, true, true, true, () => now, parent, enabled);
  p.noteCurrencyMainDocument(initialUrl);
  p.claimPayerRequest(origin + '/billing/payer-web-api/checkout?ts=1794130000000', 'GET', 'xhr',
    { authorization: 'Bearer ' + token });
  p.observePayer(Buffer.from(body ?? JSON.stringify({ mode: 'SETUP', currency: 'EUR', setupModeDetails: {
    ...(secret === null ? {} : { externalTransactionClientSecret: secret }),
    externalCustomerClientSecret: 'PRIVATE_CUSTOMER', billingCustomerExternalId: 'PRIVATE_CUSTOMER_ID',
  } })));
  p.startNormalWindow(); if (delivered && p.mayDeliverPayer()) p.markPayerDelivered();
  return { p, advance: value => { now += value; } };
}
function claim(p, c = {}) {
  return p.claimCurrencySettings(c.url ?? target, c.method ?? 'GET', c.type ?? 'xhr',
    c.headers ?? { accept: 'application/json, text/plain, */*', 'client-secret': 'Bearer PRIVATE_TRANSACTION',
      origin, cookie: 'PRIVATE_COOKIE', referer: initial }, c.body ?? null, c.main ?? true);
}
test('separate explicit slot requires delivered own payer and transaction secret without Customer fallback', () => {
  for (const options of [{ enabled: false }, { delivered: false }, { secret: null },
    { initialUrl: initial.replace('checkout.sandbox', 'checkout') },
    { body: '{"mode":"SETUP","currency":"EUR","setupModeDetails":{}}' }]) {
    const { p } = setup(options); assert.equal(claim(p), null);
  }
  const { p } = setup();
  assert.deepEqual(claim(p), { accept: 'application/json, text/plain, */*',
    'client-secret': 'Bearer PRIVATE_TRANSACTION', cookie: '', referer: '', origin });
  assert.equal(claim(p), null); assert.equal(p.currencySettingsEvidence().currency_settings_attempts, 1);
  assert.equal(p.normalEvidence().normal_country_attempts, 0);
  assert.equal(p.parentCountryEvidence().parent_country_requested, undefined);
});
test('payer ambiguity cannot authorize the new secret-bearing read', () => {
  const { p } = setup({ body: '{"mode":"PAYMENT","mode":"SETUP","currency":"EUR"}' });
  assert.equal(claim(p), null); assert.equal(p.currencySettingsSendAllowed(), false);
});
test('source headers include Bearer transaction secret only, absent API version, and XSRF is consumed/withheld', () => {
  for (const headers of [
    { accept: '*/*', 'client-secret': 'Bearer PRIVATE_TRANSACTION' },
    { accept: 'application/json, text/plain, */*', 'client-secret': 'PRIVATE_TRANSACTION' },
    { accept: 'application/json, text/plain, */*', 'client-secret': 'Bearer PRIVATE_CUSTOMER' },
    ...['authorization', 'x-api-version', 'x-cloud-trace-context', 'x-device-type', 'x-on-behalf-of', 'x-xsrf-token']
      .map(name => ({ accept: 'application/json, text/plain, */*', 'client-secret': 'Bearer PRIVATE_TRANSACTION', [name]: 'PRIVATE' })),
  ]) {
    const { p } = setup(); assert.equal(claim(p, { headers }), false);
    assert.equal(p.currencySettingsSendAllowed(), false); assert.equal(claim(p), null);
    assert.equal(p.currencySettingsEvidence().currency_settings_headers_withheld, true);
    assert.equal(p.currencySettingsEvidence().currency_settings_xsrf_withheld, Object.hasOwn(headers, 'x-xsrf-token'));
    assert.doesNotMatch(JSON.stringify(p.currencySettingsEvidence()), /PRIVATE|Bearer/u);
  }
});
test('original-main role has no query/body/alternate origin/path/version/rebind authority', () => {
  for (const change of [{ main: false }, { method: 'POST' }, { type: 'document' }, { body: 'PRIVATE' },
    { url: target + '?' }, { url: target + '?page_num=0' }, { url: target + '#' },
    { url: target.replace('https://', 'https://PRIVATE@') }, { url: target.replace('/config', '/%63onfig') },
    { url: target.replace('checkout.sandbox', 'api.sandbox') }, { url: target.replace('checkout.sandbox', 'checkout') },
    { url: target + '/extra' }]) {
    const { p } = setup(); assert.equal(claim(p, change), null);
    assert.equal(p.currencySettingsEvidence().currency_settings_attempts, 0);
  }
  for (const next of [initial, 'about:blank']) {
    const { p } = setup(); p.noteCurrencyMainDocument(next); assert.equal(claim(p), null);
  }
  const { p, advance } = setup(); advance(8000); assert.equal(claim(p), null);
});
test('source missing/null fallback stays distinct from known empty items, no pagination or currency values escape', () => {
  for (const [body, outcome, shape] of [['{}', 'source_fallback', 'missing'],
    ['{"items":null}', 'source_fallback', 'null'], ['{"items":[]}', 'matched', 'array'],
    [good, 'matched', 'array'], ['{"items":[{"type":"another_setting","PRIVATE":"PRIVATE"}]}', 'matched', 'array']]) {
    const { p } = setup(); assert.ok(claim(p)); assert.equal(p.observeCurrencySettings(Buffer.from(body)), outcome);
    const data = p.currencySettingsEvidence(); assert.equal(data.currency_settings_items_state, shape);
    if (shape !== 'array') assert.equal(data.currency_settings_items, undefined);
    assert.doesNotMatch(JSON.stringify(data), /PRIVATE|EUR|USD|Bearer|has_more/u);
  }
});
for (const body of ['[]', '{"items":false}', '{"items":[null]}',
  '{"items":[{"type":"currency_switcher"}]}', '{"items":[{"type":"currency_switcher","currencies":[1]}]}',
  '{"items":[{"type":"currency_switcher","currencies":["eur"]}]}',
  '{"items":[],"it\\u0065ms":[]}', Buffer.from([0xff]), 'x'.repeat(65537),
  JSON.stringify({ items: Array.from({ length: 201 }, () => ({})) })]) {
  test('unsupported/ambiguous bounded shape is withheld, not inferred merchant configuration: ' + String(body).slice(0, 40), () => {
    const { p } = setup(); assert.ok(claim(p));
    assert.equal(p.observeCurrencySettings(Buffer.from(body)), 'unsupported_response_shape');
    assert.equal(p.currencySettingsSendAllowed(), false); assert.equal(claim(p), null);
  });
}

function fixture(fault = '') {
  const { p } = setup();
  const caseEnd = performance.now() + (fault === 'late_close' ? 100 : 25);
  if (fault === 'timeout' || fault === 'late_get' || fault.startsWith('stalled_')) p.currencySettingsWindowRemaining = () => 25;
  let current = initial, closing = false, latched = false, admissionChecks = 0, gets = 0, disposed = 0, responseDisposed = 0,
    delivered = 0, aborts = 0, bodyReads = 0, constructed = false, release;
  const frame = { isDetached: () => false, url: () => current };
  const response = { status: () => fault === 'bad_status' ? 99 : fault === 'redirect' ? 302 : fault === 'http' ? 403 : 200,
    headersArray: async () => [{ name: 'Content-Type', value: 'application/json' },
      ...(fault === 'bad_headers' ? [{ name: 'Content-Type', value: 'application/json' }]
        : [{ name: 'Set-Cookie', value: 'PRIVATE_COOKIE' }])],
    body: async () => { bodyReads++;
      if (fault === 'stalled_body') return new Promise(resolve => { release = () => resolve(Buffer.from(good)); });
      return Buffer.from(fault === 'malformed' ? '{"items":false}' : good); },
    dispose: async () => { responseDisposed++;
      if (fault === 'sync_dispose_expiry') { while (performance.now() <= caseEnd + 2) { /* local bounded stall */ } }
      if (fault === 'stalled_dispose') return new Promise(() => {}); } };
  const client = { dispose: async () => { disposed++; }, get: async (url, options) => {
    gets++; assert.equal(url, target); assert.equal(options.maxRedirects, 0); assert.equal(options.maxRetries, 0);
    assert.equal(options.headers.cookie, ''); assert.equal(options.headers.referer, '');
    assert.equal(options.headers.authorization, undefined); assert.equal(options.headers['client-secret'], 'Bearer PRIVATE_TRANSACTION');
    assert.equal(options.headers['x-api-version'], undefined);
    if (fault === 'get') throw new Error('PRIVATE_URL_COOKIE');
    if (fault === 'changed_fetch') current = 'about:blank';
    if (fault === 'timeout' || fault === 'case_deadline') return new Promise(() => {});
    if (fault === 'late_get') return new Promise(resolve => { release = () => resolve(response); });
    return response;
  } };
  const observer = new CurrencySettingsObserver(p, true, { request: { newContext: async () => {
    constructed = true;
    if (fault.startsWith('stalled_context')) return new Promise(resolve => { release = () => resolve(client); });
    if (fault === 'latch') latched = true;
    if (fault === 'changed_context') current = 'about:blank';
    return client;
  } } }, ['case_deadline', 'sync_dispose_expiry', 'late_close'].includes(fault) ? caseEnd : Infinity); observer.start('http://127.0.0.1:1234');
  const request = { frame: () => frame, url: () => target, method: () => 'GET', resourceType: () => 'xhr',
    headers: () => ({ origin, accept: 'application/json, text/plain, */*', 'client-secret': 'Bearer PRIVATE_TRANSACTION',
      ...(fault === 'xsrf' ? { 'x-xsrf-token': 'PRIVATE_XSRF' } : {}) }), postData: () => null };
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
    assert.equal(data.currency_settings_attempts, 1);
    assert.equal(c.gets, ['changed_context', 'latch', 'late_latch'].includes(fault) ? 0 : 1);
    assert.equal(c.delivered, ['', 'stalled_dispose', 'stalled_fulfill'].includes(fault) ? 1 : 0);
    assert.equal(c.disposed, 1);
    if (['redirect', 'http', 'bad_status', 'bad_headers'].includes(fault)) assert.equal(c.bodyReads, 0);
    assert.doesNotMatch(JSON.stringify(data), /PRIVATE|currencyValue|https?:/u);
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

test('source XSRF-bearing read is consumed and aborted without constructing an API context', async () => {
  const f = fixture('xsrf'); assert.equal(await f.start(), true); assert.equal(await f.observer.close(), true);
  assert.equal(f.counters().gets, 0); assert.equal(f.counters().constructed, false);
  const data = f.observer.evidence(); assert.equal(data.currency_settings_attempts, 1);
  assert.equal(data.currency_settings_headers_withheld, true); assert.equal(data.currency_settings_xsrf_withheld, true);
  assert.equal(data.currency_settings_outcome, 'headers_withheld'); assert.equal(data.currency_settings_http_status, undefined);
});
test('late synchronous disposal cannot claim clean completion after the original case deadline', async () => {
  const f = fixture('sync_dispose_expiry'); assert.equal(await f.start(), true);
  assert.equal(await f.observer.close(), false);
  const data = f.observer.evidence(); assert.equal(data.currency_settings_failure_phase, 'dispose');
  assert.equal(data.currency_settings_delivered, 1); assert.equal(data.currency_settings_outcome, 'matched');
  assert.equal(f.counters().gets, 1); assert.equal(f.counters().disposed, 1);
});
test('already completed and disposed work is not re-timed by later sent-read drain or shutdown', async () => {
  const f = fixture('late_close'); assert.equal(await f.start(), true);
  assert.equal(f.counters().disposed, 1); assert.equal(f.counters().responseDisposed, 1);
  assert.equal(f.counters().delivered, 1); assert.equal(f.observer.failed(), false);
  await new Promise(resolve => setTimeout(resolve, 120));
  await f.observer.drainSent(); assert.equal(await f.observer.close(), true);
  assert.equal(f.observer.failed(), false); assert.equal(f.observer.evidence().currency_settings_failure_phase, undefined);
  assert.equal(f.counters().gets, 1); assert.equal(f.counters().disposed, 1); assert.equal(f.counters().responseDisposed, 1);
});
test('an enabled but unavailable optional role has no invented request or failed cleanup', async () => {
  const { p } = setup({ secret: null });
  const observer = new CurrencySettingsObserver(p, true, { request: { newContext() { throw new Error('PRIVATE'); } } });
  assert.equal(await observer.close(), true); assert.equal(observer.evidence().currency_settings_attempts, 0);
  assert.equal(observer.evidence().currency_settings_secret_available, false);
  assert.equal(observer.evidence().currency_settings_http_status, undefined);
});
