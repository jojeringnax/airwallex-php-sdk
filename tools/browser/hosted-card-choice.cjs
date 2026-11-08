'use strict';

const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const runtimeProofs = new WeakMap();
const selector = '#payment-method-card input#radio-card[type=radio][name=method][value=card]';
const runtimeHash = '9393fa79e1c67c74edc26b610d65a4f7ed73d345a762465cc88340a33a2454ac';

// Reviewed local implementation, including channel cancellation and the no-scroll pointer branch.
async function verifyCardRuntime(modulePath) {
  const entry = require.resolve('playwright-core/package.json', { paths: [modulePath] });
  const root = path.dirname(entry);
  const metadata = JSON.parse(await fs.readFile(entry, 'utf8'));
  const stat = await fs.stat(path.join(root, 'lib/coreBundle.js'));
  if (metadata.version !== '1.62.1' || stat.size !== 3425603) throw new Error('Unsupported Card-choice runtime.');
  const bytes = await fs.readFile(path.join(root, 'lib/coreBundle.js'));
  if (crypto.createHash('sha256').update(bytes).digest('hex') !== runtimeHash) {
    throw new Error('Unsupported Card-choice runtime.');
  }
  if (require(modulePath) !== require(root)) throw new Error('Unsupported Card-choice runtime.');
  const proof = Object.freeze({});
  runtimeProofs.set(proof, null);
  return proof;
}

function fixtureCardRuntime(origin) {
  const url = new URL(origin);
  if (url.origin !== origin || url.protocol !== 'http:' || url.hostname !== '127.0.0.1') throw new Error('Invalid fixture origin.');
  const proof = Object.freeze({}); runtimeProofs.set(proof, origin); return proof;
}

class NativeCardChoice {
  #enabled;
  #clock;
  #remaining;
  #captured = false;
  #claimed = false;
  #controller = new AbortController();
  #facts;
  constructor(enabled, proof, remaining, clock = () => performance.now(), fixtureOrigin = null) {
    if (typeof enabled !== 'boolean' || typeof remaining !== 'function' || typeof clock !== 'function'
        || (enabled && (!runtimeProofs.has(proof) || runtimeProofs.get(proof) !== fixtureOrigin))) {
      throw new Error('Unsupported Card-choice configuration.');
    }
    this.#enabled = enabled;
    this.#remaining = remaining;
    this.#clock = clock;
    this.#facts = enabled ? { native_card_selection_requested: true,
      native_card_selection: 'withheld', native_card_action_attempts: 0, native_card_action_invoked: false } : {};
  }
  capture() { this.#captured = true; this.#controller.abort(); }
  evidence() { return { ...this.#facts }; }
  async consider(frame, scope, qualified) {
    if (!this.#enabled || this.#claimed || this.#captured || !qualified || !scope() || this.#remaining() <= 0) return;
    this.#claimed = true;
    const deadline = this.#clock() + this.#remaining();
    const active = () => !this.#captured && !this.#controller.signal.aborted
      && this.#remaining() > 0 && this.#clock() < deadline && scope();
    const step = async action => {
      if (!active()) throw new Error('Card-choice scope ended.');
      let timer, cancel;
      try {
        const value = await Promise.race([action(), new Promise((_, reject) => {
          timer = setTimeout(() => reject(new Error('Card-choice scope ended.')), Math.max(1, deadline - this.#clock()));
        }), new Promise((_, reject) => {
          cancel = () => reject(new Error('Card-choice scope ended.'));
          this.#controller.signal.addEventListener('abort', cancel, { once: true });
          if (this.#controller.signal.aborted) cancel();
        })]);
        if (!active()) throw new Error('Card-choice scope ended.');
        return value;
      } finally {
        clearTimeout(timer);
        if (cancel) this.#controller.signal.removeEventListener('abort', cancel);
      }
    };
    try {
      const locator = frame.locator(selector);
      const count = await step(() => locator.count());
      if (!Number.isInteger(count) || count < 0) throw new Error('Unavailable native radio count.');
      this.#facts.native_card_radio_count = count === 0 ? 'zero' : count === 1 ? 'one' : 'multiple';
      if (count !== 1) { this.#facts.native_card_selection = count === 0 ? 'missing' : 'ambiguous'; return; }
      // A particular node is retained: subsequent operations never resolve a replacement locator.
      const element = await step(() => locator.elementHandle());
      if (!element) throw new Error('Unavailable native radio.');
      const visible = await step(() => element.isVisible());
      const enabled = await step(() => element.isEnabled());
      const checked = await step(() => element.isChecked());
      const matches = await step(() => element.evaluate(node => node.matches(
        '#payment-method-card input#radio-card[type=radio][name=method][value=card]')));
      if (matches !== true) throw new Error('Native radio identity changed.');
      if ([visible, enabled, checked].some(value => typeof value !== 'boolean')) throw new Error('Unavailable radio state.');
      Object.assign(this.#facts, { native_card_radio_visible: visible, native_card_radio_enabled: enabled,
        native_card_radio_checked: checked });
      if (!visible || !enabled || checked) {
        this.#facts.native_card_selection = checked ? 'already_selected' : !visible ? 'hidden' : 'disabled'; return;
      }
      if (!active()) throw new Error('Card-choice scope ended.');
      this.#facts.native_card_action_attempts = 1;
      this.#facts.native_card_action_invoked = true;
      this.#facts.native_card_action_invoked_before_capture = true;
      this.#facts.native_card_selection = 'invoked';
      const click = element.click({ button: 'left', clickCount: 1, force: false, scroll: 'none',
        modifiers: [], signal: this.#controller.signal, timeout: Math.max(1, Math.floor(deadline - this.#clock())) });
      void click.then(() => {
        this.#facts.native_card_action_completed = true;
        this.#facts.native_card_action_completed_before_capture = !this.#captured
          && this.#remaining() > 0 && this.#clock() < deadline;
        this.#facts.native_card_selection = 'completed';
      }, () => { this.#facts.native_card_selection = 'uncertain'; });
      await step(() => click);
    } catch {
      this.#facts.native_card_selection = this.#facts.native_card_action_invoked ? 'uncertain' : 'unavailable';
    }
  }
}

module.exports = { NativeCardChoice, verifyCardRuntime, fixtureCardRuntime };
