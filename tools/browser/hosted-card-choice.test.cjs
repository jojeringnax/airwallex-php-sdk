'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const { NativeCardChoice, fixtureCardRuntime, verifyCardRuntime } = require('./hosted-card-choice.cjs');
const origin = 'http://127.0.0.1:12345';
function fixture(options = {}) {
  let calls = 0, lookups = 0;
  let valid = true;
  const element = { isVisible: async () => options.visible ?? true,
    isEnabled: async () => options.enabled ?? true, isChecked: async () => options.checked ?? false,
    evaluate: async () => options.identity ?? true,
    click: async input => { calls++; assert.equal(input.force, false); assert.equal(input.scroll, 'none');
      assert.equal(input.button, 'left'); assert.equal(input.clickCount, 1); assert.equal(input.signal.aborted, false);
      assert.ok(input.timeout > 0 && input.timeout <= 100); if (options.click) await options.click(input); } };
  const frame = { locator: value => { lookups++; assert.equal(value,
    '#payment-method-card input#radio-card[type=radio][name=method][value=card]');
    return { count: async () => options.count ?? 1, elementHandle: async () => element }; } };
  const choice = new NativeCardChoice(options.selected ?? true, fixtureCardRuntime(origin), () => 100,
    options.clock, origin);
  return { choice, run: () => choice.consider(frame, () => valid, options.qualified ?? true),
    invalidate: () => { valid = false; }, calls: () => calls, lookups: () => lookups, element };
}
test('default and unsupported runtime do not acquire an action capability', async () => {
  const f = fixture({ selected: false }); await f.run(); assert.equal(f.calls(), 0); assert.deepEqual(f.choice.evidence(), {});
  assert.throws(() => new NativeCardChoice(true, {}, () => 100));
  assert.throws(() => new NativeCardChoice(true, fixtureCardRuntime(origin), () => 100));
  assert.throws(() => fixtureCardRuntime('https://example.test'));
  await assert.rejects(verifyCardRuntime('/nonexistent/fixture/module'));
});
test('missing proof performs no lookup and grants no click', async () => {
  const f = fixture({ qualified: false }); await f.run(); assert.equal(f.lookups(), 0); assert.equal(f.calls(), 0);
});
test('a correctly sized but unreviewed runtime cannot produce a production action proof', async () => {
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'airwallex-card-runtime-fixture-'));
  try {
    const root = path.join(directory, 'node_modules/playwright-core');
    await fs.mkdir(path.join(root, 'lib'), { recursive: true });
    await fs.writeFile(path.join(root, 'package.json'), JSON.stringify({ version: '1.62.1', main: 'index.js' }));
    await fs.writeFile(path.join(root, 'index.js'), 'module.exports = {};');
    await fs.writeFile(path.join(root, 'lib/coreBundle.js'), Buffer.alloc(3425603));
    await assert.rejects(verifyCardRuntime(root), /Unsupported Card-choice runtime/u);
  } finally { await fs.rm(directory, { recursive: true, force: true }); }
});
for (const [name, options, outcome] of [
  ['zero', { count: 0 }, 'missing'], ['multiple', { count: 2 }, 'ambiguous'],
  ['hidden', { visible: false }, 'hidden'], ['disabled', { enabled: false }, 'disabled'],
  ['selected', { checked: true }, 'already_selected'], ['changed node', { identity: false }, 'unavailable'],
  ['invalid count', { count: '1' }, 'unavailable'], ['invalid state', { checked: 'PRIVATE' }, 'unavailable'],
]) test('one retained native-node opportunity: ' + name, async () => {
  const f = fixture(options); await f.run(); await f.run(); assert.equal(f.calls(), 0);
  assert.equal(f.choice.evidence().native_card_selection, outcome); assert.equal(f.lookups(), 1);
});
test('one native unchecked visible enabled node invokes once with no force/scroll/retry', async () => {
  const f = fixture(); await Promise.all([f.run(), f.run()]); await f.run(); f.choice.capture();
  assert.equal(f.calls(), 1); assert.equal(f.lookups(), 1);
  assert.equal(f.choice.evidence().native_card_action_completed, true);
  assert.equal(f.choice.evidence().native_card_action_completed_before_capture, true);
  assert.doesNotMatch(JSON.stringify(f.choice.evidence()), /input|payment-method|PRIVATE|http/u);
});
test('capture prevents new actions and aborts an invoked action without waiting or upgrading fields', async () => {
  let release, signal;
  const f = fixture({ click: input => { signal = input.signal; return new Promise(resolve => { release = resolve; }); } });
  const task = f.run();
  while (!release) await new Promise(resolve => setImmediate(resolve));
  f.choice.capture(); assert.equal(signal.aborted, true); await f.run(); assert.equal(f.calls(), 1);
  release(); await task;
  assert.equal(f.choice.evidence().native_card_action_completed, true);
  assert.equal(f.choice.evidence().native_card_action_completed_before_capture, false);
  const untouched = fixture(); untouched.choice.capture(); await untouched.run(); assert.equal(untouched.calls(), 0);
});
test('navigation or replacement while checking a particular node never resolves another one', async () => {
  const f = fixture(); f.element.isVisible = async () => { f.invalidate(); return true; };
  await f.run(); assert.equal(f.calls(), 0); assert.equal(f.lookups(), 1);
});
test('a failed click remains uncertain with no retry and no raw error projection', async () => {
  const f = fixture({ click: async () => { throw new Error('PRIVATE_URL_TOKEN'); } });
  await f.run(); await f.run(); assert.equal(f.calls(), 1);
  assert.equal(f.choice.evidence().native_card_selection, 'uncertain');
  assert.doesNotMatch(JSON.stringify(f.choice.evidence()), /PRIVATE/u);
});
test('a nonprogressing observation is bounded and cannot click after late completion', async () => {
  const f = fixture(); let release; f.element.isVisible = () => new Promise(resolve => { release = resolve; });
  const started = performance.now(); await f.run(); assert.ok(performance.now() - started < 1000);
  release(true); await new Promise(resolve => setImmediate(resolve)); assert.equal(f.calls(), 0);
});
test('capture cancels a stalled node observation promptly and its late result cannot start input', async () => {
  const f = fixture(); let release;
  f.element.isVisible = () => new Promise(resolve => { release = resolve; });
  const task = f.run(); while (!release) await new Promise(resolve => setImmediate(resolve));
  const started = performance.now(); f.choice.capture(); await task;
  assert.ok(performance.now() - started < 50); release(true);
  await new Promise(resolve => setImmediate(resolve)); assert.equal(f.calls(), 0);
});
test('completion observed past its finite cutoff is not labeled before capture merely because a timer is late', async () => {
  let now = 0;
  const f = fixture({ clock: () => now, click: async () => { now = 101; } });
  await f.run(); assert.equal(f.calls(), 1);
  assert.equal(f.choice.evidence().native_card_action_completed, true);
  assert.equal(f.choice.evidence().native_card_action_completed_before_capture, false);
});
