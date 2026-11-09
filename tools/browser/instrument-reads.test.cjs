'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { InstrumentReadPolicy } = require('./instrument-reads.cjs');

const expected = { checkout_id: 'checkout_synthetic', mode: 'SETUP', currency: 'EUR' };
function initial(exp = Math.floor(Date.now() / 1000) + 3600, host = 'checkout.sandbox.airwallex.com') {
  const token =
    Buffer.from('{}').toString('base64url') +
    '.' +
    Buffer.from(JSON.stringify({ resource_id: 'billing:checkout_synthetic', exp })).toString('base64url') +
    '.eA';
  return 'https://' + host + '/pay?s=' + token;
}
test('separate instrument clocks remain immutable and inherited closures cannot reopen them', () => {
  for (const [close, window] of [
    ['closeNormalWindow', 'normalWindowRemaining'],
    ['closeParentCountryWindow', 'parentCountryWindowRemaining'],
    ['closeCurrencySettingsWindow', 'currencySettingsWindowRemaining']
  ]) {
    const policy = new InstrumentReadPolicy(initial(), expected, performance.now() + 180000);
    assert.ok(policy[window]() > 170000);
    policy[close]();
    assert.equal(policy[window](), 0);
    policy.startNormalWindow();
    policy.startParentCountryWindow();
    assert.equal(policy[window](), 0);
    policy.stop();
    assert.equal(policy.normalWindowRemaining(), 0);
    assert.equal(policy.parentCountryWindowRemaining(), 0);
    assert.equal(policy.currencySettingsWindowRemaining(), 0);
  }
});
test('ownership expiration closes every separate clock before any new send', () => {
  const now = Date.now,
    exp = Math.floor(now() / 1000) + 60;
  const policy = new InstrumentReadPolicy(initial(exp), expected, performance.now() + 180000);
  try {
    Date.now = () => exp * 1000;
    assert.equal(policy.normalWindowRemaining(), 0);
    assert.equal(policy.parentCountryWindowRemaining(), 0);
    assert.equal(policy.currencySettingsWindowRemaining(), 0);
  } finally {
    Date.now = now;
  }
});
test('shared/production scope and expired deadlines cannot select the instrument profile', () => {
  assert.throws(
    () =>
      new InstrumentReadPolicy(
        initial(undefined, 'checkout.airwallex.com'),
        expected,
        performance.now() + 1000
      )
  );
  assert.throws(() => new InstrumentReadPolicy(initial(), expected, performance.now() - 1));
  assert.throws(() => new InstrumentReadPolicy(initial(1), expected, performance.now() + 1000));
});
