'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const { installProjection } = require('./instrument-document.cjs');
const { monitoring } = require('./hosted-instrument-workflow.cjs');

function projection() {
  const parent = {},
    window = {},
    origin = 'https://checkout.sandbox.airwallex.com';
  let receiver,
    stops = 0;
  window.stop = () => {
    stops++;
  };
  const options = {
    mode: 'recurring',
    currency: 'EUR',
    client_secret: 'SYNTHETIC_SECRET',
    customer_id: 'cus_synthetic',
    sessionId: 'SYNTHETIC_SESSION'
  };
  const context = vm.createContext({
    location: {
      origin,
      hash: '#/elements/dropin?' + new URLSearchParams({ lang: 'en', options: JSON.stringify(options) })
    },
    URLSearchParams,
    window,
    parent,
    addEventListener: (_name, callback) => {
      receiver = callback;
    }
  });
  vm.runInContext(
    '(' +
      installProjection.toString() +
      ')({marker:"read",origin:location.origin,buyerEmail:"synthetic@example.test",stopBinding:"stop"})',
    context
  );
  const emit = (data, extras = {}) => receiver({ data, source: parent, origin, isTrusted: true, ...extras });
  return { emit, read: () => window.read(), stops: () => stops };
}
test('source startup theme/layout/contact and locale-only updates preserve the original actionable branch', () => {
  const f = projection();
  const appearance = {
    mode: 'light',
    variables: { colorBackground: '#ffffff', colorText: '#000000', colorBrand: '#123456' }
  };
  f.emit({
    code: 'update',
    options: { appearance, layout: { type: 'accordion', alwaysShowMethodLabel: true } }
  });
  f.emit({
    code: 'update',
    options: {
      billing: {
        first_name: 'SDK',
        last_name: 'Sandbox Buyer',
        email: 'synthetic@example.test',
        phone_number: '',
        address: { country_code: 'IT' }
      },
      shopper_name: 'SDK Sandbox Buyer',
      shopper_email: 'synthetic@example.test',
      disablePay: false,
      layout: { type: 'accordion', alwaysShowMethodLabel: true }
    },
    initOptions: { locale: 'en' }
  });
  f.emit({ code: 'update', initOptions: { locale: 'en' } });
  assert.equal(f.read().blocked, false);
  assert.equal(f.read().updates, 3);
  assert.equal(f.stops(), 0);
});
for (const fault of [
  'wrong_sender',
  'synthetic',
  'consent',
  'locale',
  'secret',
  'recurring',
  'theme',
  'contact',
  'selector'
]) {
  test('receiver-shaped ' + fault + ' work latches a monotonic source stop', () => {
    const f = projection();
    let data = { code: 'update', options: { disablePay: false } },
      extras = {};
    if (fault === 'wrong_sender') extras.source = {};
    if (fault === 'synthetic') extras.isTrusted = false;
    if (fault === 'consent') data.options = { payment_consent: {} };
    if (fault === 'locale') data = { code: 'update', initOptions: { locale: 'fr' } };
    if (fault === 'secret') data.options = { client_secret: 'FOREIGN' };
    if (fault === 'recurring') data.options = { recurringOptions: { next_triggered_by: 'merchant' } };
    if (fault === 'theme')
      data.options = {
        appearance: {
          mode: 'light',
          variables: { colorBackground: 'url(https://unknown.invalid)', colorText: '#000' }
        }
      };
    if (fault === 'contact') data.options = { billing: { first_name: 'real', last_name: 'person' } };
    if (fault === 'selector') data.options = { platformConnectedAccount: 'foreign_account' };
    f.emit(data, extras);
    assert.equal(f.read().blocked, true);
    assert.ok(f.stops() >= 1);
    f.emit({ code: 'update', options: { disablePay: false } });
    assert.equal(f.read().blocked, true);
  });
}
test('only exact source one-way monitoring paths can be withheld without an upstream request', () => {
  const meta = {
    sourceRole: 'main',
    method: 'GET',
    type: 'script',
    body: Buffer.alloc(0),
    url: 'https://www.googletagmanager.com/gtag/js?id=G-QGE5H07VBD'
  };
  assert.equal(monitoring(meta), true);
  for (const changed of [{ sourceRole: 'risk' }, { url: meta.url + '&extra=1' }, { method: 'POST' }])
    assert.equal(monitoring({ ...meta, ...changed }), false);
  assert.equal(
    monitoring({
      ...meta,
      method: 'POST',
      type: 'ping',
      url: 'https://o11y.sandbox.airwallex.com/airtracker/logs',
      body: Buffer.from('synthetic')
    }),
    true
  );
  assert.equal(
    monitoring({ ...meta, method: 'POST', type: 'fetch', url: 'https://o11y.sandbox.airwallex.com/unknown' }),
    false
  );
});
