'use strict';

const { hash } = require('./instrument-journal.cjs');
const radioSelector = '#payment-method-card input#radio-card[type=radio][name=method][value=card]';
const fields = Object.freeze({
  name: 'SDK Sandbox Buyer',
  cardnumber: '4035501000000008',
  expiry: '03 / 30',
  cvc: '737'
});

class InstrumentActions {
  #page;
  #frame;
  #documents;
  #marker;
  #financial;
  #journal;
  #guard;
  #deadline;
  #handles = {};
  #fieldCounts = {};
  #form;
  #button;
  #updates;
  #used = new Set();
  #invocations = 0;
  constructor(page, frame, documents, marker, financial, journal, guard, deadline) {
    this.#page = page;
    this.#frame = frame;
    this.#documents = documents;
    this.#marker = marker;
    this.#financial = financial;
    this.#journal = journal;
    this.#guard = guard;
    this.#deadline = deadline;
  }
  async #step(action) {
    if (!this.#guard() || performance.now() >= this.#deadline) throw new Error('Native action window ended.');
    let timer;
    try {
      const value = await Promise.race([
        action(),
        new Promise((_, reject) => {
          timer = setTimeout(
            () => reject(new Error('Native action window ended.')),
            Math.max(1, Math.min(1000, this.#deadline - performance.now()))
          );
        })
      ]);
      if (!this.#guard() || performance.now() >= this.#deadline)
        throw new Error('Native action scope changed.');
      return value;
    } finally {
      clearTimeout(timer);
    }
  }
  async projection() {
    const value = await this.#step(() =>
      this.#frame.evaluate((marker) => {
        const read = window[marker];
        return typeof read === 'function' ? read() : null;
      }, this.#marker)
    );
    if (
      !value ||
      value.blocked !== false ||
      value.disabled !== false ||
      !Number.isSafeInteger(value.updates)
    ) {
      throw new Error('Source configuration is not actionable.');
    }
    return value;
  }
  async #original(element) {
    await this.#step(() => this.#documents.assert(this.#page.mainFrame()));
    const entry = await this.#step(() => this.#documents.assert(this.#frame));
    const same = await this.#step(() =>
      element.evaluate(
        (node, original) => node.isConnected && node.ownerDocument === original && node.matches(':enabled'),
        entry.document
      )
    );
    if (
      !same ||
      !(await this.#step(() => element.isVisible())) ||
      !(await this.#step(() => element.isEnabled()))
    ) {
      throw new Error('Original native control changed.');
    }
    const projection = await this.projection();
    if (this.#updates !== undefined && projection.updates !== this.#updates)
      throw new Error('Input crossed a source update.');
  }
  async #point(element) {
    await this.#original(element);
    const box = await this.#step(() => element.boundingBox()),
      viewport = this.#page.viewportSize();
    if (
      !box ||
      !viewport ||
      box.width <= 0 ||
      box.height <= 0 ||
      box.x < 0 ||
      box.y < 0 ||
      box.x + box.width > viewport.width ||
      box.y + box.height > viewport.height
    ) {
      throw new Error('Native target needs unsupported scrolling.');
    }
    const hit = await this.#step(() =>
      element.evaluate((node) => {
        const rect = node.getBoundingClientRect(),
          target = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);
        return node === target || node.contains(target);
      })
    );
    const iframe = await this.#step(() => this.#frame.frameElement());
    try {
      const parentHit = await this.#step(() =>
        this.#page
          .mainFrame()
          .evaluate(({ node, x, y }) => document.elementFromPoint(x, y) === node, {
            node: iframe,
            x: box.x + box.width / 2,
            y: box.y + box.height / 2
          })
      );
      if (!hit || !parentHit) throw new Error('Native target is covered.');
    } finally {
      await iframe.dispose();
    }
    await this.#original(element);
    return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
  }
  async #record(role, intent, operation) {
    if (this.#used.has(role)) throw new Error('Native opportunity cannot be replayed.');
    this.#used.add(role);
    const claim = {
      role,
      ordinal: 0,
      method: 'UI',
      url: this.#frame.url(),
      headers: {},
      body: Buffer.from(JSON.stringify(intent)),
      scope: hash(this.#documents.entry(this.#frame).source)
    };
    const action = this.#journal.prepare(claim);
    if (!this.#guard() || !this.#journal.check(claim, action))
      throw new Error('Native durability is unavailable.');
    await operation();
    if (!this.#guard() || !this.#journal.check(claim, action))
      throw new Error('Native action outcome is uncertain.');
    this.#journal.observed(claim, action, { status: 0, body: Buffer.alloc(0) });
  }
  async #pointer(element, beforeDown = () => {}) {
    const point = await this.#point(element);
    await this.#step(() => this.#page.mouse.move(point.x, point.y));
    const rechecked = await this.#point(element);
    if (point.x !== rechecked.x || point.y !== rechecked.y) throw new Error('Native target moved.');
    beforeDown();
    this.#invocations++;
    await this.#step(() => this.#page.mouse.down({ button: 'left', clickCount: 1 }));
    await this.#original(element);
    await this.#step(() => this.#page.mouse.up({ button: 'left', clickCount: 1 }));
  }
  async chooseCard() {
    const radios = await this.#step(() => this.#frame.$$(radioSelector));
    try {
      if (radios.length > 1) throw new Error('Native Card choice is ambiguous.');
      if (radios.length === 0 || (await this.#step(() => radios[0].isChecked()))) return;
      await this.#record('card_choice', { selector: radioSelector }, () => this.#pointer(radios[0]));
      if (!(await this.#step(() => radios[0].isChecked())))
        throw new Error('Native Card choice was not established.');
    } finally {
      await Promise.allSettled(radios.map((element) => element.dispose()));
    }
  }
  async capture() {
    this.#updates = (await this.projection()).updates;
    for (const name of Object.keys(fields)) {
      const values = await this.#step(() => this.#frame.$$('input[name="' + name + '"]'));
      this.#fieldCounts[name] = values.length === 0 ? 'zero' : values.length === 1 ? 'one' : 'multiple';
      if (values.length !== 1) {
        await Promise.allSettled(values.map((element) => element.dispose()));
        throw new Error('Native field is unavailable.');
      }
      this.#handles[name] = values[0];
      await this.#original(values[0]);
    }
    const form = await this.#step(() =>
      this.#handles.cardnumber.evaluateHandle((node) => node.closest('form[action="#"]'))
    );
    this.#form = form.asElement();
    if (!this.#form) {
      await form.dispose();
      throw new Error('Original Card form is unavailable.');
    }
    for (const element of Object.values(this.#handles)) {
      const contained = await this.#step(() =>
        element.evaluate((node, form) => node.form === form, this.#form)
      );
      if (!contained) throw new Error('Native fields do not share their original form.');
    }
    const buttons = await this.#step(() => this.#form.$$('button.Button[type="button"]'));
    this.#fieldCounts.submit = buttons.length === 0 ? 'zero' : buttons.length === 1 ? 'one' : 'multiple';
    if (buttons.length !== 1) {
      await Promise.allSettled(buttons.map((element) => element.dispose()));
      throw new Error('Ordinary submit is unavailable.');
    }
    this.#button = buttons[0];
    const selected = await this.#step(() =>
      this.#form.evaluate((form) => {
        const wrappers = document.querySelectorAll('#payment-method-card');
        if (wrappers.length === 0) return document.querySelectorAll('input[name="cardnumber"]').length === 1;
        if (wrappers.length !== 1 || !wrappers[0].contains(form)) return false;
        const radios = wrappers[0].querySelectorAll('input#radio-card[type=radio][name=method][value=card]');
        return radios.length === 1 && radios[0].checked;
      })
    );
    if (!selected) throw new Error('Original Card branch is not selected.');
    await this.#point(this.#button);
  }
  async input() {
    if (!this.#financial.canInput(this.#frame.url())) throw new Error('Synthetic input is not authorized.');
    await this.#record(
      'card_input',
      { fields, selectors: Object.keys(fields).map((name) => 'input[name="' + name + '"]') },
      async () => {
        this.#financial.beginInput(this.#frame.url());
        for (const [name, value] of Object.entries(fields)) {
          const element = this.#handles[name];
          await this.#point(element);
          const writable = await this.#step(() =>
            element.evaluate((node) => node.tagName === 'INPUT' && !node.readOnly)
          );
          if (!writable) throw new Error('Native input is not writable.');
          await this.#step(() =>
            element.fill(value, { timeout: Math.max(1, Math.min(1000, this.#deadline - performance.now())) })
          );
          await this.#original(element);
        }
      }
    );
    this.#financial.inputDone();
  }
  async submit() {
    if (!this.#financial.canSubmit(this.#frame.url())) throw new Error('Ordinary submit is not authorized.');
    await this.#record('card_submit', { selector: 'form[action="#"] button.Button[type="button"]' }, () =>
      this.#pointer(this.#button, () => this.#financial.submitted(this.#frame.url()))
    );
  }
  evidence() {
    return {
      native_pointer_invocations: this.#invocations,
      input_attempted: this.#used.has('card_input'),
      submit_attempted: this.#used.has('card_submit'),
      card_choice_attempted: this.#used.has('card_choice'),
      native_control_counts: { ...this.#fieldCounts }
    };
  }
  async close() {
    const settled = await Promise.allSettled(
      [...Object.values(this.#handles), this.#button, this.#form]
        .filter(Boolean)
        .map((handle) => handle.dispose())
    );
    return settled.every((value) => value.status === 'fulfilled');
  }
  toJSON() {
    throw new Error('Private native actions cannot be serialized.');
  }
}

module.exports = { InstrumentActions, fields, radioSelector };
