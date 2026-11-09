'use strict';

// Runs before the pinned Drop-in receiver. It observes messages and never alters their propagation.
function installProjection({ marker, origin, buyerEmail, stopBinding }) {
  if (location.origin !== origin || !location.hash.startsWith('#/elements/dropin?')) return;
  let blocked = false,
    updates = 0,
    config;
  const forbidden = [
    'intent_id',
    'payment_consent',
    'checkout_id',
    'checkoutSessionId',
    'checkout_session_token',
    'occAuthToken',
    'occOptions',
    'morIntentId',
    'taxRegion',
    'taxCalculationIdentifier',
    'platformConnectedAccount',
    'occ',
    'mor'
  ];
  const block = () => {
    blocked = true;
    try {
      void window[stopBinding]();
    } catch {}
  };
  const query = new URLSearchParams(location.hash.slice('#/elements/dropin?'.length));
  try {
    const raw = query.get('options');
    config = JSON.parse(raw);
    if (
      !config ||
      typeof config !== 'object' ||
      Array.isArray(config) ||
      config.mode !== 'recurring' ||
      config.currency !== 'EUR' ||
      config.showConfirmButton === false ||
      forbidden.some((key) => Boolean(config[key]))
    )
      block();
  } catch {
    block();
    config = {};
  }
  const original = {
    mode: config.mode,
    currency: config.currency,
    client_secret: config.client_secret,
    customer_id: config.customer_id,
    sessionId: config.sessionId
  };
  const recurring = JSON.stringify(config.recurringOptions);
  const allowed = new Set([
    ...Object.keys(original),
    ...forbidden,
    'billing',
    'disablePay',
    'showConfirmButton',
    'country_code',
    'currency_switcher',
    'recurringOptions',
    'layout',
    'appearance',
    'shopper_name',
    'shopper_email'
  ]);
  let appearance;
  const originalBilling = JSON.stringify(config.billing);
  const billing = (value) =>
    JSON.stringify(value) === originalBilling ||
    (value &&
      typeof value === 'object' &&
      !Array.isArray(value) &&
      Object.keys(value).every((key) =>
        ['email', 'first_name', 'last_name', 'phone_number', 'address'].includes(key)
      ) &&
      value.first_name === 'SDK' &&
      value.last_name === 'Sandbox Buyer' &&
      ['', undefined, buyerEmail].includes(value.email) &&
      ['', undefined].includes(value.phone_number) &&
      (!value.address ||
        (typeof value.address === 'object' &&
          !Array.isArray(value.address) &&
          Object.keys(value.address).every((key) =>
            ['country_code', 'city', 'street', 'state', 'postcode'].includes(key)
          ) &&
          /^[A-Z]{2}$/u.test(value.address.country_code) &&
          Object.entries(value.address).every(([key, item]) => key === 'country_code' || item === ''))));
  const bounded = (value, depth = 0) =>
    depth <= 8 &&
    (value === null ||
      ['boolean', 'number', 'undefined'].includes(typeof value) ||
      (typeof value === 'string' && value.length <= 8192) ||
      (typeof value === 'object' &&
        Object.keys(value).length <= 64 &&
        Object.values(value).every((child) => bounded(child, depth + 1))));
  addEventListener('message', (event) => {
    try {
      const data = event.data;
      if (!data || data.code !== 'update' || (!data.options && !data.initOptions?.locale)) return;
      updates++;
      const options = data.options;
      // The real receiver has no sender filter. Wrong-sender receiver-shaped work must stop too.
      if (
        event.origin !== origin ||
        event.source !== parent ||
        event.isTrusted !== true ||
        (options &&
          (typeof options !== 'object' ||
            Array.isArray(options) ||
            !bounded(options) ||
            Object.keys(options).some((key) => !allowed.has(key)))) ||
        (data.initOptions !== undefined &&
          (!data.initOptions ||
            typeof data.initOptions !== 'object' ||
            Array.isArray(data.initOptions) ||
            Object.keys(data.initOptions).join(',') !== 'locale' ||
            data.initOptions.locale !== query.get('lang')))
      ) {
        block();
        return;
      }
      if (
        options?.layout &&
        (Object.keys(options.layout).sort().join(',') !== 'alwaysShowMethodLabel,type' ||
          options.layout.type !== 'accordion' ||
          options.layout.alwaysShowMethodLabel !== true)
      ) {
        block();
        return;
      }
      if (options?.appearance) {
        const theme = options.appearance;
        if (
          !theme ||
          typeof theme !== 'object' ||
          Object.keys(theme).sort().join(',') !== 'mode,variables' ||
          !['light', 'dark'].includes(theme.mode) ||
          !theme.variables ||
          typeof theme.variables !== 'object' ||
          Array.isArray(theme.variables) ||
          !['colorBackground', 'colorText'].every((key) => Object.hasOwn(theme.variables, key)) ||
          Object.entries(theme.variables).some(
            ([key, value]) =>
              !['colorBackground', 'colorText', 'colorBrand'].includes(key) ||
              typeof value !== 'string' ||
              !/^#[a-f0-9]{3,8}$/iu.test(value)
          ) ||
          (appearance !== undefined && appearance !== JSON.stringify(theme))
        ) {
          block();
          return;
        }
        appearance = JSON.stringify(theme);
      }
      if (
        (options?.billing !== undefined && !billing(options.billing)) ||
        (options?.shopper_name !== undefined &&
          !['', config.shopper_name, 'SDK Sandbox Buyer'].includes(options.shopper_name)) ||
        (options?.shopper_email !== undefined &&
          !['', config.shopper_email, buyerEmail].includes(options.shopper_email))
      ) {
        block();
        return;
      }
      const next = { ...config, ...options };
      if (typeof next.currency === 'string') next.currency = next.currency.toUpperCase();
      if (
        forbidden.some((key) => Boolean(next[key])) ||
        next.mode !== original.mode ||
        next.currency !== original.currency ||
        next.client_secret !== original.client_secret ||
        next.customer_id !== original.customer_id ||
        next.sessionId !== original.sessionId ||
        next.showConfirmButton === false ||
        JSON.stringify(next.recurringOptions) !== recurring ||
        (next.disablePay !== undefined && typeof next.disablePay !== 'boolean') ||
        (options?.currency_switcher &&
          (typeof options.currency_switcher !== 'object' ||
            Object.keys(options.currency_switcher).length > 0))
      )
        block();
      config = next;
    } catch {
      block();
    }
  });
  Object.defineProperty(window, marker, {
    value: () => ({ blocked, updates, disabled: config.disablePay === true }),
    writable: false,
    configurable: false,
    enumerable: false
  });
}

class InstrumentDocuments {
  #page;
  #deadline;
  #stop;
  #pending;
  #entries = new Map();
  #history = new Map();
  #transitions = new Set();
  #closed = false;
  #paused = 0;
  #cleanupFailed = false;
  constructor(page, deadline, stop, pending) {
    this.#page = page;
    this.#deadline = deadline;
    this.#stop = stop;
    this.#pending = pending;
    page.on('framenavigated', (frame) => {
      this.#history.set(frame, (this.#history.get(frame) ?? 0) + 1);
      const entry = this.#entries.get(frame);
      if (entry) {
        const transition = this.#navigation(entry).catch(() => this.#fail());
        this.#transitions.add(transition);
        void transition.finally(() => this.#transitions.delete(transition));
      }
    });
    page.on('framedetached', (frame) => {
      if (this.#entries.has(frame) && !this.#closed) this.#fail();
    });
  }
  #fail() {
    if (!this.#closed) {
      this.#closed = true;
      this.#stop('document');
    }
  }
  live() {
    return !this.#closed && this.#paused === 0 && performance.now() < this.#deadline;
  }
  pendingFrames() {
    return this.#page.frames().some((frame) => !this.#entries.has(frame));
  }
  entry(frame) {
    return this.#entries.get(frame);
  }
  role(frame) {
    return this.#entries.get(frame)?.role;
  }
  expect(frame, role, source, inheritedSource = null) {
    if (this.#closed || this.#entries.has(frame)) throw new Error('Original frame cannot be rebound.');
    const entry = {
      frame,
      role,
      source,
      inheritedSource,
      document: null,
      capture: null,
      sequence: this.#history.get(frame) ?? 0,
      repeats: 0,
      initial: false
    };
    this.#entries.set(frame, entry);
    // A newly created source-qualified about:blank role has no HTTP commit to await.
    if (source === 'about:blank') {
      if (entry.sequence > 1 || frame.url() !== source)
        throw new Error('Blank source history is unavailable.');
      entry.initial = true;
      entry.capture = this.#capture(entry, entry.sequence);
    }
    return entry;
  }
  async #bounded(action) {
    const remaining = Math.min(500, this.#deadline - performance.now());
    if (this.#closed || remaining <= 0) throw new Error('Original document window ended.');
    let timer;
    try {
      const result = await Promise.race([
        action(),
        new Promise((_, reject) => {
          timer = setTimeout(() => reject(new Error('Original document proof ended.')), remaining);
        })
      ]);
      if (this.#closed || performance.now() >= this.#deadline)
        throw new Error('Original document window ended.');
      return result;
    } finally {
      clearTimeout(timer);
    }
  }
  async #capture(entry, sequence) {
    let handle;
    const acquisition = entry.frame.evaluateHandle(() => document);
    try {
      handle = await this.#bounded(() => acquisition);
      if (
        entry.frame.isDetached() ||
        entry.frame.url() !== entry.source ||
        (this.#history.get(entry.frame) ?? 0) !== sequence
      ) {
        throw new Error('Original document acquisition crossed a navigation.');
      }
      if (
        !(await this.#bounded(() =>
          entry.frame.evaluate(
            (original) => original === document && original.nodeType === 9 && original.defaultView === window,
            handle
          )
        ))
      ) {
        throw new Error('Original Document is not native.');
      }
      entry.document = handle;
      return true;
    } catch {
      this.#cleanupFailed = true;
      if (handle) void handle.dispose().catch(() => {});
      else void acquisition.then((late) => late.dispose()).catch(() => {});
      this.#fail();
      return false;
    }
  }
  async #navigation(entry) {
    if (this.#closed) return;
    if (entry.frame.url() !== entry.source) {
      this.#fail();
      return;
    }
    const sequence = this.#history.get(entry.frame) ?? 0;
    if (!entry.initial) {
      entry.initial = true;
      entry.sequence = sequence;
      entry.capture = this.#capture(entry, sequence);
      await entry.capture;
      return;
    }
    if (
      !entry.document ||
      entry.repeats !== 0 ||
      !['main', 'dropin'].includes(entry.role) ||
      this.#pending() !== 0
    ) {
      this.#fail();
      return;
    }
    this.#paused++;
    try {
      const same = await this.#bounded(() =>
        entry.frame.evaluate((original) => document === original, entry.document)
      );
      if (!same || (this.#history.get(entry.frame) ?? 0) !== sequence || entry.frame.url() !== entry.source)
        this.#fail();
      else {
        entry.repeats++;
        entry.sequence = sequence;
      }
    } catch {
      this.#fail();
    } finally {
      this.#paused--;
    }
  }
  async assert(frame) {
    const entry = this.#entries.get(frame);
    if (this.#transitions.size) await this.#bounded(() => Promise.all([...this.#transitions]));
    if (!entry || !entry.capture || !(await entry.capture) || !entry.document || !this.live()) {
      throw new Error('Original document proof is unavailable.');
    }
    const sequence = this.#history.get(frame) ?? 0;
    const same = await this.#bounded(() =>
      frame.evaluate((original) => document === original, entry.document)
    );
    if (
      !same ||
      frame.isDetached() ||
      frame.url() !== entry.source ||
      sequence !== (this.#history.get(frame) ?? 0) ||
      !this.live()
    ) {
      this.#fail();
      throw new Error('Original document changed.');
    }
    return entry;
  }
  async close() {
    this.#closed = true;
    const entries = [...this.#entries.values()];
    const settled = await Promise.allSettled(
      entries.map(async (entry) => {
        if (entry.capture) await entry.capture;
        if (entry.document) await entry.document.dispose();
      })
    );
    return (
      !this.#cleanupFailed &&
      this.#transitions.size === 0 &&
      settled.every((value) => value.status === 'fulfilled')
    );
  }
  toJSON() {
    throw new Error('Private original documents cannot be serialized.');
  }
}

module.exports = { installProjection, InstrumentDocuments };
