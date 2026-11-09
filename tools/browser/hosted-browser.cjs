'use strict';

// Never enable Playwright/browser debug channels or inherit application credentials.
for (const key of ['DEBUG', 'PWDEBUG', 'DEBUG_FILE', 'CHROME_LOG_FILE']) delete process.env[key];
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const { HostedPolicy, egressProxy, assetMatches } = require('./hosted-policy.cjs');
const { NativeCardChoice, verifyCardRuntime, cardWindowRemaining } = require('./hosted-card-choice.cjs');
const { ParentCountryObserver } = require('./hosted-parent-country.cjs');
const { CurrencySettingsObserver } = require('./hosted-currency-settings.cjs');
const { RiskJournalClient } = require('./hosted-risk-journal.cjs');
const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');

// Contains only evidence constructed from fixed local labels/counts, never a caught Error.
class PreflightFailure extends Error {
  #safe;
  constructor(safe) { super('Browser preflight failed safely.'); this.#safe = Object.freeze(safe); }
  evidence() { return { ...this.#safe }; }
}
let startupPhase = 'configuration';

// Passive Instrument-only facts. Nothing in this object grants admission or reopens a scope.
class BoundDocumentObservations {
  #deadline; #generation; #clock; #frame; #original; #previous; #publicBase;
  #bindingGeneration; #baselineGeneration; #handle; #baseline = 'not_captured';
  #started = false; #stopped = false; #closed = false; #sealed = false; #captureSettled = false;
  #comparisonSettled = true;
  #tasks = new Set(); #disposals = new Map(); #safe = {};
  #closedSignal; #closeSignal;
  constructor(deadline, generation, clock = () => performance.now()) {
    if (!Number.isFinite(deadline) || typeof generation !== 'function' || typeof clock !== 'function') {
      throw new Error('Invalid passive document observation.');
    }
    this.#deadline = deadline; this.#generation = generation; this.#clock = clock;
    this.#closedSignal = new Promise(resolve => { this.#closeSignal = resolve; });
  }
  static #url(value) {
    if (typeof value !== 'string' || value.length === 0 || value.length > 20000 || /[\u0000-\u0020\u007f]/u.test(value)) return null;
    try { return new URL(value).href === value ? value : null; } catch { return null; }
  }
  static #relation(current, prior) {
    if (current === null || prior === null) return 'unavailable';
    if (current === prior) return 'exact_repeat';
    return current.split('#', 1)[0] === prior.split('#', 1)[0] ? 'fragment_only_change' : 'nonfragment_change';
  }
  static #usable(handle) {
    try { return handle !== null && typeof handle === 'object' && typeof handle.dispose === 'function'
      && typeof handle.asElement === 'function' && handle.asElement() === handle; }
    catch { return false; }
  }
  bind(frame, source, generation) {
    if (this.#closed || this.#frame !== undefined) return;
    this.#frame = frame; this.#original = BoundDocumentObservations.#url(source); this.#previous = null;
    this.#bindingGeneration = generation;
    if (this.#original !== null) {
      const url = new URL(this.#original); this.#publicBase = url.origin + url.pathname;
    }
  }
  #source(frame) {
    try { return BoundDocumentObservations.#url(frame.url()); } catch { return null; }
  }
  #sameBase(frame) {
    const current = this.#source(frame);
    return current !== null && this.#original !== null && current.split('#', 1)[0] === this.#original.split('#', 1)[0];
  }
  async #within(promise, deadline) {
    const remaining = deadline - this.#clock();
    if (remaining <= 0) { void Promise.resolve(promise).catch(() => {}); return { ok: false }; }
    let timer;
    try {
      const result = await Promise.race([Promise.resolve(promise).then(value => ({ ok: true, value }), () => ({ ok: false })),
        new Promise(resolve => { timer = setTimeout(() => resolve({ ok: false }), remaining); }),
        this.#closedSignal.then(() => ({ ok: false }))]);
      return this.#clock() < deadline ? result : { ok: false };
    } finally { clearTimeout(timer); }
  }
  #track(promise) {
    this.#tasks.add(promise);
    void promise.then(() => this.#tasks.delete(promise), () => this.#tasks.delete(promise));
  }
  #dispose(handle) {
    if (handle === null || handle === undefined) return Promise.resolve(true);
    if (!this.#disposals.has(handle)) {
      const state = { settled: false, ok: false, promise: null };
      state.promise = Promise.resolve().then(() => handle.dispose()).then(() => {
        state.settled = true; state.ok = true; return true;
      }, () => { state.settled = true; return false; });
      this.#disposals.set(handle, state);
    }
    return this.#disposals.get(handle).promise;
  }
  #capture(frame, generation) {
    this.#started = true; this.#baseline = 'pending'; this.#baselineGeneration = generation;
    const deadline = Math.min(this.#deadline, this.#clock() + 500);
    const promise = Promise.resolve().then(() => {
      if (this.#closed || this.#stopped || this.#generation() !== generation || this.#clock() >= deadline
          || this.#source(frame) !== this.#original) return null;
      // Only the already-qualified public origin/path enters the expression, never its private fragment/options.
      return frame.evaluateHandle(expected => {
        try { const url = new URL(document.URL); return url.origin + url.pathname === expected ? document : null; }
        catch { return null; }
      }, this.#publicBase);
    });
    // Late native objects still get one disposal, even after the bounded observer has sealed its output.
    void promise.then(handle => {
      this.#captureSettled = true;
      if (this.#closed || this.#stopped || this.#sealed) void this.#dispose(handle);
    }, () => { this.#captureSettled = true; });
    this.#track((async () => {
      const result = await this.#within(promise, deadline);
      if (!result.ok || !BoundDocumentObservations.#usable(result.value) || this.#closed || this.#stopped
          || this.#generation() !== generation || this.#sealed || this.#clock() >= deadline
          || this.#source(frame) !== this.#original) {
        if (!this.#sealed) this.#baseline = 'unavailable';
        void promise.then(handle => this.#dispose(handle), () => {});
        return;
      }
      this.#handle = result.value; this.#baseline = 'ready';
    })());
  }
  navigated(frame, generation, stoppedBound) {
    if (this.#closed || frame !== this.#frame) return;
    const current = this.#source(frame);
    if (stoppedBound && !this.#stopped) {
      this.#stopped = true;
      this.#safe.security_bound_navigation_original_relation = BoundDocumentObservations.#relation(current, this.#original);
      this.#safe.security_bound_navigation_previous_relation = BoundDocumentObservations.#relation(current, this.#previous);
      const ready = this.#baseline === 'ready' && this.#baselineGeneration + 1 === generation;
      this.#safe.security_bound_document_reference = ready ? 'captured_before_stop'
        : this.#baseline === 'unavailable' ? 'unavailable' : 'not_established';
      this.#safe.security_bound_document_continuity = ready ? 'unavailable' : 'not_captured';
      if (ready) {
        const deadline = Math.min(this.#deadline, this.#clock() + 500);
        this.#comparisonSettled = false;
        const promise = Promise.resolve().then(() => {
          if (this.#closed || this.#generation() !== generation || this.#clock() >= deadline || !this.#sameBase(frame)) return null;
          return frame.evaluate(prior => prior === document, this.#handle);
        });
        void promise.then(() => { this.#comparisonSettled = true; }, () => { this.#comparisonSettled = true; });
        this.#track((async () => {
          try {
            const result = await this.#within(promise, deadline);
            if (!this.#closed && !this.#sealed && this.#generation() === generation && this.#clock() < deadline
                && this.#sameBase(frame) && result.ok
                && typeof result.value === 'boolean') {
              this.#safe.security_bound_document_continuity = result.value ? 'same' : 'different';
            }
          } finally { void this.#dispose(this.#handle); }
        })());
      }
    }
    this.#previous = current;
    if (!this.#started && !this.#stopped && current !== null && current === this.#original
        && generation === this.#bindingGeneration) this.#capture(frame, generation);
  }
  close() {
    if (this.#closed) return;
    this.#closed = true;
    this.#closeSignal();
    if (this.#handle !== undefined) void this.#dispose(this.#handle);
  }
  finish() {
    if (this.#sealed) return;
    this.close();
    if (this.#started) {
      this.#safe.security_bound_document_cleanup = this.#tasks.size === 0 && this.#captureSettled && this.#comparisonSettled
        && [...this.#disposals.values()].every(value => value.settled && value.ok) ? 'complete' : 'unavailable';
    }
    this.#sealed = true;
  }
  evidence() { return { ...this.#safe }; }
  retentionReference(frame, generation) {
    return !this.#closed && !this.#sealed && !this.#stopped && frame === this.#frame
      && this.#baseline === 'ready' && this.#captureSettled && this.#handle !== undefined
      && this.#bindingGeneration === generation && this.#baselineGeneration === generation
      && this.#generation() === generation && this.#clock() < this.#deadline
      && this.#original !== null && this.#source(frame) === this.#original && this.#previous === this.#original;
  }
  toJSON() { throw new Error('Private document observations cannot be serialized.'); }
}

const navigationRuntimeProofs = new WeakSet();
async function verifyNavigationRuntime(modulePath, playwright) {
  try {
    if (typeof modulePath !== 'string' || !path.isAbsolute(modulePath) || require(modulePath) !== playwright) return null;
    await verifyCardRuntime(modulePath); // Same reviewed full bundle, version and loaded-export identity.
    const proof = Object.freeze({}); navigationRuntimeProofs.add(proof); return proof;
  } catch { return null; }
}

// Decision120: only one synchronous native packet can change the original invalidation condition.
class SameDocumentRetention {
  #runtime; #proof; #documents; #generation; #frame; #main; #source; #session; #boundGeneration;
  #emitter; #listener; #remove; #packet; #disabled = false; #used = false; #closed = false;
  #observing = false; #consuming = false;
  #safe = { security_same_document_requested: true, security_same_document_retained: false,
    security_same_document_reason: 'not_observed' };
  constructor(runtime, proof, documents, generation) {
    this.#runtime = navigationRuntimeProofs.has(runtime); this.#proof = proof;
    this.#documents = documents; this.#generation = generation;
    if (!this.#runtime) this.#disable('runtime_unverified');
  }
  #disable(reason) { this.#disabled = true; this.#packet = undefined; this.#safe.security_same_document_reason = reason; }
  static #data(object, key) {
    const value = Object.getOwnPropertyDescriptor(object, key);
    if (!value || !Object.hasOwn(value, 'value')) throw new Error('Unsupported native shape.');
    return value.value;
  }
  bind(frame, main, source) {
    if (this.#closed || this.#disabled || this.#frame !== undefined) return;
    try {
      if (!frame || frame === main || frame.parentFrame() !== main || frame.page().mainFrame() !== main
          || frame.isDetached() !== false || main.isDetached() !== false) {
        this.#disable('binding_unavailable'); return;
      }
      const proto = Object.getPrototypeOf(frame);
      if (SameDocumentRetention.#data(SameDocumentRetention.#data(proto, 'constructor'), 'name') !== '_Frame'
          || typeof Object.getOwnPropertyDescriptor(proto, 'url')?.value !== 'function'
          || typeof Object.getOwnPropertyDescriptor(proto, 'parentFrame')?.value !== 'function') {
        this.#disable('shape_unavailable'); return;
      }
      const emitter = SameDocumentRetention.#data(frame, '_eventEmitter');
      const ep = Object.getPrototypeOf(emitter);
      if (SameDocumentRetention.#data(SameDocumentRetention.#data(ep, 'constructor'), 'name') !== 'EventEmitter2'
          || typeof Object.getOwnPropertyDescriptor(ep, 'emit')?.value !== 'function'
          || typeof SameDocumentRetention.#data(emitter, 'on') !== 'function'
          || typeof SameDocumentRetention.#data(emitter, 'off') !== 'function') {
        this.#disable('shape_unavailable'); return;
      }
      const url = new URL(source), options = JSON.parse(new URLSearchParams(
        url.hash.slice('#/elements/dropin?'.length)).get('options') ?? '');
      if (url.href !== source || !url.hash.startsWith('#/elements/dropin?')
          || typeof options?.sessionId !== 'string' || !/^[A-Za-z0-9_-]{1,128}$/u.test(options.sessionId)) {
        this.#disable('binding_unavailable'); return;
      }
      this.#frame = frame; this.#main = main; this.#source = source; this.#session = options.sessionId;
      this.#boundGeneration = this.#generation(); this.#emitter = emitter;
      this.#remove = SameDocumentRetention.#data(emitter, 'off');
      // Never return a Promise or leak an own listener error into Playwright's synchronous public emit.
      this.#listener = packet => {
        try {
          if (this.#observing || this.#consuming) { this.#disable('packet_unavailable'); return; }
          this.#observing = true; this.#observe(packet);
        } catch { this.#disable('listener_failed'); }
        finally { this.#observing = false; }
      };
      Reflect.apply(SameDocumentRetention.#data(emitter, 'on'), emitter, ['navigated', this.#listener]);
    } catch { this.#disable('listener_failed'); }
  }
  #observe(packet) {
    if (this.#closed || this.#disabled) return;
    if (this.#packet !== undefined) { this.#disable('packet_unavailable'); return; }
    if (packet === null || typeof packet !== 'object' || Object.getPrototypeOf(packet) !== Object.prototype) {
      this.#disable('shape_unavailable'); return;
    }
    const keys = Reflect.ownKeys(packet);
    if (keys.some(key => !['url', 'name', 'newDocument', 'error'].includes(key))) {
      this.#disable('shape_unavailable'); return;
    }
    const url = SameDocumentRetention.#data(packet, 'url'), name = SameDocumentRetention.#data(packet, 'name');
    if (typeof url !== 'string' || url.length > 20000 || typeof name !== 'string' || name.length > 256) {
      this.#disable('shape_unavailable'); return;
    }
    const error = Object.hasOwn(packet, 'error') ? SameDocumentRetention.#data(packet, 'error') : undefined;
    const document = Object.hasOwn(packet, 'newDocument') ? SameDocumentRetention.#data(packet, 'newDocument') : undefined;
    if (error !== undefined || (document !== undefined && (document === null || typeof document !== 'object'))) {
      this.#disable('shape_unavailable'); return;
    }
    if (this.#disabled || this.#closed) return;
    const token = { url, same: document === undefined, generation: this.#generation() };
    this.#packet = token;
    queueMicrotask(() => { if (this.#packet === token) this.#packet = undefined; }); // Expiry only, never permission.
  }
  retain(frame) {
    try {
      if (this.#observing || this.#consuming) { this.#disable('packet_unavailable'); return false; }
      this.#consuming = true;
      const packet = this.#packet; this.#packet = undefined;
      if (this.#closed || this.#disabled) return false;
      const reject = reason => { this.#safe.security_same_document_reason = reason; return false; };
      if (this.#used) return reject('already_used');
      if (!packet || frame !== this.#frame || frame.parentFrame() !== this.#main
          || frame.page().mainFrame() !== this.#main) return reject('packet_unavailable');
      if (!packet.same) return reject('new_document');
      if (packet.url !== this.#source || frame.url() !== this.#source) return reject('url_changed');
      if (packet.generation !== this.#boundGeneration || this.#generation() !== this.#boundGeneration) {
        return reject('generation_changed');
      }
      if (!this.#documents.retentionReference(frame, this.#boundGeneration)) return reject('reference_unavailable');
      if (this.#proof(frame, this.#main, this.#session) !== true || this.#disabled) return reject('scope_unavailable');
      this.#used = true; this.#safe.security_same_document_retained = true;
      this.#safe.security_same_document_reason = 'retained_once';
      return true;
    } catch { this.#disable('listener_failed'); return false; }
    finally { this.#consuming = false; }
  }
  close() {
    if (this.#closed) return;
    this.#closed = true; this.#packet = undefined;
    try { if (this.#listener) Reflect.apply(this.#remove, this.#emitter, ['navigated', this.#listener]); } catch { this.#disable('listener_failed'); }
  }
  evidence() { return { ...this.#safe }; }
  toJSON() { throw new Error('Private native navigation adapter cannot be serialized.'); }
}

async function inspect(initial, playwright, fixtureOrigin = null, exercise = null, expected = null, render = false, observeConfig = false, observeNormal = false, riskJournal = null, riskFixture = null, observeParentCountry = false, observeCurrencySettings = false, selectCard = false, cardRuntime = null, instrumentSecurity = false, retainSameDocument = false, runtimeModule = null) {
  const instrumentCaseDeadline = performance.now() + 40000;
  if (typeof retainSameDocument !== 'boolean' || (retainSameDocument && !instrumentSecurity)) throw new Error('Invalid navigation profile.');
  let navigationRuntime = null;
  if (retainSameDocument) {
    let timer;
    try { navigationRuntime = await Promise.race([verifyNavigationRuntime(runtimeModule, playwright),
      new Promise(resolve => { timer = setTimeout(() => resolve(null), Math.max(1, instrumentCaseDeadline - performance.now())); })]);
    } finally { clearTimeout(timer); }
    if (performance.now() >= instrumentCaseDeadline) {
      // No operation was admitted: close the fresh FD client synchronously, without a new teardown wait.
      try { void Promise.resolve(riskJournal?.close()).catch(() => {}); } catch { /* Fixed startup failure below. */ }
      throw new Error('Security case ended during runtime proof.');
    }
  }
  if (typeof instrumentSecurity !== 'boolean' || (instrumentSecurity
      && (!render || !observeConfig || !observeNormal || riskJournal === null || selectCard))) {
    throw new Error('Invalid fixed security profile.');
  }
  const policy = new HostedPolicy(initial, fixtureOrigin, expected, render, observeConfig, observeNormal, undefined, observeParentCountry, observeCurrencySettings);
  if (typeof selectCard !== 'boolean' || (selectCard && !observeNormal)) throw new Error('Card choice requires explicit normal reads.');
  if (riskJournal !== null && !observeNormal) throw new Error('Risk observation requires explicit normal reads.');
  let cardCaseDeadline = 0;
  const cardChoice = new NativeCardChoice(selectCard, cardRuntime,
    () => cardWindowRemaining(policy.normalWindowRemaining(), cardCaseDeadline), undefined, fixtureOrigin);
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'airwallex-browser-preflight-'));
  await fs.chmod(directory, 0o700);
  let proxy;
  let browser;
  let context;
  let mainPage;
  let publicClientPromise;
  let methodClientPromise;
  const normalClientPromises = new Set();
  const normalClientStates = new Map();
  const normalInFlight = new Set();
  let normalAdmissionClosed = false;
  let normalWindowTimer;
  let normalDeadlineFired = false;
  let browserContextCloseStarted = false;
  let browserContextClosed = false;
  const normal = {};
  for (const role of ['customer', 'consent_list', 'country', 'checkout_status']) {
    normal['normal_' + role + '_sends'] = 0;
    normal['normal_' + role + '_delivered'] = 0;
    normal['normal_' + role + '_observation'] = 'not_observed';
  }
  const normalStep = async action => {
    if (!observeNormal) return action();
    const remaining = policy.normalWindowRemaining() - 500;
    if (shuttingDown || remaining <= 0) throw new Error('The read-only observation window ended.');
    let timer;
    try {
      return await Promise.race([action(), new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error('The read-only observation window ended.')), remaining);
      })]);
    } finally { clearTimeout(timer); }
  };
  let boundFrame;
  let shuttingDown = false;
  let result;
  let readiness = {};
  let readinessAttachment = {};
  let failed = false;
  let firstFailure;
  let methodSendStarted = false;
  const markFailure = phase => { failed = true; firstFailure ??= phase; };
  const step = async (phase, action) => {
    try { return await action(); }
    catch { markFailure(phase); throw new Error('Browser preflight failed safely.'); }
  };
  const profileStep = async action => {
    if (!instrumentSecurity) return action();
    const remaining = security.normalWindowRemaining();
    if (shuttingDown || remaining <= 0) throw new Error('Security response release is closed.');
    const deadline = performance.now() + remaining;
    let timer;
    try {
      const result = await Promise.race([Promise.resolve().then(() => {
        if (shuttingDown || performance.now() >= deadline || security.normalWindowRemaining() <= 0) {
          throw new Error('Security response release is closed.');
        }
        return action();
      }), new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error('Security response release is closed.')), remaining);
      })]);
      if (shuttingDown || performance.now() >= deadline || security.normalWindowRemaining() <= 0) {
        throw new Error('Security response release is closed.');
      }
      return result;
    } finally { clearTimeout(timer); }
  };
  const pending = new Set();
  const track = (action, phase = 'route_action') => {
    const safe = Promise.resolve().then(action).catch(() => { markFailure(phase); });
    pending.add(safe);
    void safe.then(() => pending.delete(safe));
    return safe;
  };
  const expireNormalWindow = () => {
    if (normalDeadlineFired) return;
    normalDeadlineFired = true;
    policy.closeNormalWindow();
    markFailure('normal_observation_deadline');
    shuttingDown = true;
    if (!instrumentSecurity && context) { browserContextCloseStarted = true; void track(() => context.close(), 'shutdown_browser_context'); }
  };
  const drainSentNormalReads = async () => {
    if (!observeNormal || shuttingDown || normalInFlight.size === 0) return;
    const remaining = policy.normalWindowRemaining();
    if (remaining <= 0) { expireNormalWindow(); return; }
    let timer;
    try {
      await Promise.race([Promise.all([...normalInFlight]), new Promise(resolve => {
        timer = setTimeout(() => { expireNormalWindow(); resolve(); }, remaining);
      })]);
    } finally { clearTimeout(timer); }
  };
  const caseDeadline = instrumentSecurity ? instrumentCaseDeadline : performance.now() + (render ? 40000 : 20000);
  cardCaseDeadline = caseDeadline;
  const parentCountry = new ParentCountryObserver(policy, observeParentCountry, playwright, caseDeadline);
  const currencySettings = new CurrencySettingsObserver(policy, observeCurrencySettings, playwright, caseDeadline);
  const considerCard = () => {
    if (!selectCard) return;
    const frame = boundFrame;
    const scope = () => {
      try { return !shuttingDown && !normalAdmissionClosed && frame === boundFrame
        && frame && !frame.isDetached() && policy.nativeCardChoiceAllowed(frame.url()); } catch { return false; }
    };
    void cardChoice.consider(frame, scope, scope());
  };
  const { InstrumentSecurityScope, PendingLoader } = instrumentSecurity
    ? require('./hosted-pending-loader.cjs') : {};
  const security = instrumentSecurity ? new InstrumentSecurityScope(policy, caseDeadline) : null;
  const risk = new HostedRiskObserver(security ?? policy, riskJournal, playwright, riskFixture, caseDeadline, instrumentSecurity);
  const pendingLoader = instrumentSecurity ? new PendingLoader(risk, security) : null;
  let sourceGeneration = 0;
  const documents = instrumentSecurity ? new BoundDocumentObservations(caseDeadline, () => sourceGeneration) : null;
  const sameDocument = retainSameDocument ? new SameDocumentRetention(navigationRuntime, (frame, main, session) => {
    const facts = risk.evidence();
    return !shuttingDown && !normalAdmissionClosed && sourceGeneration === 0
      && frame === boundFrame && main === mainPage.mainFrame() && main.isDetached() === false
      && frame.isDetached() === false && policy.normalSourceMatches(main.url(), true)
      && policy.normalSourceMatches(frame.url()) && policy.riskSessionMatches(session)
      && policy.normalWindowRemaining() > 2500
      && security.firstStop() === 'not_observed' && security.admissionRemaining() > 0
      && security.normalWindowRemaining() > 0 && performance.now() < caseDeadline
      && facts.risk_journal_closed === false && facts.risk_journal_poisoned === false
      && facts.risk_telemetry_poisoned === false;
  }, documents, () => sourceGeneration) : null;
  let instrumentMainDocumentSeen = false;
  const securityClosed = () => shuttingDown || (security !== null && security.admissionRemaining() <= 0);
  const watchdog = setTimeout(() => {
    markFailure('watchdog');
    shuttingDown = true;
    security?.noteStop('watchdog_shutdown');
    if (context) { browserContextCloseStarted = true; void track(() => context.close()); }
    if (browser) void track(() => browser.close());
  }, Math.max(1, caseDeadline - performance.now()));
  watchdog.unref();
  const counts = { blocked_requests: 0, blocked_websockets: 0, returns_intercepted: 0,
    verified_static_assets: 0, integrity_rejections: 0, verified_render_assets: 0, bound_render_frames: 0,
    controller_asset_requests: 0, verified_controller_assets: 0, controller_replays_blocked: 0 };
  let observationDone;
  const observed = new Promise(resolve => { observationDone = resolve; });
  let observationStarted;
  const started = new Promise(resolve => { observationStarted = resolve; });
  let method = { method_config_observation: 'not_observed', method_config_response_observed: false,
    method_config_payload_delivered: false };
  let payer = { payer_observation: 'not_observed', payer_response_observed: false,
    payer_mode_matches: false, payer_currency_matches: false };
  try {
    proxy = await step('proxy_start', () => egressProxy({
      allowsAuthority: (host, port) => (!instrumentSecurity || !securityClosed())
        && (policy.allowsAuthority(host, port) || risk.authority(host, port)),
      allowsHttp: value => policy.allowsHttp(value),
    }, instrumentSecurity && fixtureOrigin === null));
    risk.start(proxy.server);
    parentCountry.start(proxy.server);
    currencySettings.start(proxy.server);
    browser = await step('browser_launch', () => playwright.chromium.launch({
      channel: 'chrome', headless: true, timeout: 15000,
      proxy: { server: proxy.server, bypass: '' },
      env: { PATH: process.env.PATH ?? '', LANG: 'en_US.UTF-8', TMPDIR: directory },
      args: ['--proxy-bypass-list=<-loopback>', '--disable-quic',
        '--force-webrtc-ip-handling-policy=disable_non_proxied_udp', '--disable-breakpad'],
    }));
    context = await step('browser_context', () => browser.newContext({
      ignoreHTTPSErrors: false, serviceWorkers: 'block', acceptDownloads: false,
      permissions: [],
    }));
    if (observeNormal) context.on('close', () => { browserContextClosed = true; });
    await context.addInitScript(() => {
      for (const name of ['Worker', 'SharedWorker']) {
        Object.defineProperty(globalThis, name, {
          value: class { constructor() { throw new Error('Workers are disabled in this preflight.'); } },
          writable: false, configurable: false,
        });
      }
    });
    await context.route('**/*', route => track(async () => {
      if (shuttingDown) throw new Error('Browser preflight is shutting down.');
      const request = route.request();
      const denial = policy.readDenial(request.url(), request.method(), request.resourceType());
      if (instrumentSecurity && request.resourceType() === 'document') {
        let frame;
        try { frame = request.frame(); } catch { /* Unavailable navigation is withheld. */ }
        const main = mainPage?.mainFrame();
        let stopReason;
        const stopAt = (value, reason) => { if (value) stopReason = reason; return value; };
        if (stopAt(!frame, 'document_frame_unavailable')
            || stopAt(frame === main && instrumentMainDocumentSeen, 'main_document_request')
            || stopAt(frame === boundFrame, 'bound_document_request')) {
          sourceGeneration++; security.close(stopReason); pendingLoader.close();
          counts.blocked_requests++; await route.abort('blockedbyclient'); return;
        }
        if (frame === main) instrumentMainDocumentSeen = true;
        else if (request.url() === initial) {
          counts.blocked_requests++; await route.abort('blockedbyclient'); return;
        }
      }
      if (observeParentCountry && request.resourceType() === 'document' && request.frame() === mainPage?.mainFrame()) {
        policy.noteParentMainDocument(request.url());
      }
      if (observeCurrencySettings && request.resourceType() === 'document' && request.frame() === mainPage?.mainFrame()) {
        policy.noteCurrencyMainDocument(request.url());
      }
      if (normalAdmissionClosed && !instrumentSecurity) {
        denial?.worker('admission_closed');
        counts.blocked_requests++;
        policy.noteDeniedRead(request.url(), request.method(), request.resourceType());
        await route.abort('blockedbyclient');
        denial?.commit();
        return;
      }
      if (await parentCountry.handle(route, mainPage?.mainFrame(), () => shuttingDown, () => normalAdmissionClosed)) return;
      if (observeCurrencySettings && await currencySettings.handle(route, mainPage?.mainFrame(), () => shuttingDown, () => normalAdmissionClosed)) return;
      if (pendingLoader && await pendingLoader.handle(route, context,
        () => ({ main: mainPage?.mainFrame(), bound: boundFrame }), securityClosed, () => sourceGeneration)) return;
      if (await risk.handle(route, context, mainPage?.mainFrame(), boundFrame,
        () => shuttingDown, instrumentSecurity ? securityClosed : () => normalAdmissionClosed)) return;
      if (instrumentSecurity && securityClosed()) {
        denial?.worker('admission_closed');
        counts.blocked_requests++; await route.abort('blockedbyclient'); denial?.commit(); return;
      }
      // Script admission and integrity share one immutable, non-null expected hash.
      const script = request.resourceType() === 'script';
      const expectedHash = script ? policy.assetHash(request.url(), request.method(), request.resourceType()) : null;
      let action = script ? (expectedHash === null ? 'block' : 'allow')
        : policy.decide(request.url(), request.method(), request.resourceType());
      const controller = expectedHash !== null
        && policy.isControllerTarget(request.url(), request.method(), request.resourceType());
      if (controller) {
        if (policy.claimControllerRequest(request.url(), request.method(), request.resourceType())) {
          counts.controller_asset_requests++;
        } else {
          action = 'block';
          counts.controller_replays_blocked++;
        }
      }
      let renderFrame = false, renderFrameSource;
      if (render && request.resourceType() === 'document' && action === 'block') {
        policy.noteRenderCheck('document_candidates');
        if (request.method() !== 'GET') {
          policy.noteRenderCheck('method_mismatch');
        } else {
          let frame, parent;
          try { frame = request.frame(); parent = frame.parentFrame(); }
          catch { frame = null; policy.noteRenderCheck('frame_unavailable'); }
          if (frame) {
            if (!mainPage || parent !== mainPage.mainFrame()) {
              policy.noteRenderCheck('parent_mismatch');
            } else if (policy.renderRequestTarget(request.url())) {
              let element;
              try { element = await frame.frameElement(); }
              catch { policy.noteRenderCheck('source_unavailable'); }
              if (element) {
                let source;
                try { source = await element.getAttribute('src'); }
                catch { policy.noteRenderCheck('source_unavailable'); }
                finally { await element.dispose(); }
                if (source !== undefined) {
                  renderFrame = policy.claimRenderFrame(source);
                  if (renderFrame) renderFrameSource = source;
                }
              }
              action = renderFrame ? 'allow' : 'block';
            }
          }
        }
      }
      let payerFromMainPage = false;
      if (mainPage && ['xhr', 'fetch'].includes(request.resourceType())
          && request.url().startsWith(new URL('/billing/payer-web-api/checkout?', initial).href)) {
        try { payerFromMainPage = request.frame() === mainPage.mainFrame(); } catch { /* Deny unavailable frame. */ }
      }
      let methodHeaders = null;
      if (!normalAdmissionClosed && observeConfig && boundFrame && ['xhr', 'fetch'].includes(request.resourceType())) {
        let fromBoundFrame = false;
        try { fromBoundFrame = request.frame() === boundFrame; }
        catch { denial?.fail('method_config', 'initiator_unavailable'); }
        if (fromBoundFrame) methodHeaders = policy.claimMethodConfig(request.url(), request.method(),
          request.resourceType(), request.headers(), request.postData(), denial);
        else denial?.fail('method_config', 'initiator_not_bound');
      } else if (denial?.roleIs('method_config')) {
        if (normalAdmissionClosed) denial.worker('admission_closed');
        else if (observeConfig && !boundFrame) denial.worker('bound_frame_unavailable');
      }
      let normalRead = null;
      if (!normalAdmissionClosed && observeNormal && mainPage && ['xhr', 'fetch'].includes(request.resourceType())) {
        let fromMain = false, fromBound = false;
        try {
          const frame = request.frame();
          const detached = frame.isDetached();
          if (detached === false) {
            fromMain = frame === mainPage.mainFrame() && policy.normalSourceMatches(frame.url(), true);
            const isBound = frame === boundFrame;
            fromBound = isBound && policy.normalSourceMatches(frame.url());
            if (!isBound) denial?.caller('initiator_not_bound');
            else if (!fromBound) denial?.caller('initiator_document_mismatch');
          } else denial?.caller(detached === true ? 'initiator_detached' : 'initiator_unavailable');
        } catch { denial?.caller('initiator_unavailable'); }
        normalRead = policy.claimNormalRead(request.url(), request.method(), request.resourceType(),
          request.headers(), request.postData(), fromMain, fromBound, denial);
      } else if (denial && !denial.roleIs('method_config')) {
        if (normalAdmissionClosed) denial.worker('admission_closed');
        else if (!observeNormal) denial.worker('observation_disabled');
        else if (!mainPage) denial.worker('initiator_unavailable');
      }
      if (normalRead !== null) {
        const { role, headers: outbound } = normalRead;
        if (role === 'checkout_status') delete normal.normal_checkout_status_state;
        const key = 'normal_' + role + '_';
        const originalSource = () => {
          try {
            const frame = request.frame();
            return frame.isDetached() === false && (role === 'checkout_status'
              ? frame === mainPage.mainFrame() && policy.normalSourceMatches(frame.url(), true)
              : frame === boundFrame && policy.normalSourceMatches(frame.url()));
          } catch { return false; }
        };
        let delivered = false;
        let activeRead, finishRead;
        const readStarted = performance.now();
        const clientState = { disposeStarted: false, disposed: false };
        let requestStarted, requestTimeout;
        let readPhase = 'normal_scope', readFailurePhase;
        const captureFailure = phase => {
          if (normal.normal_read_failure) return;
          const now = performance.now();
          const millis = (value, maximum) => Number.isFinite(value) && value >= 0 && value <= maximum
            ? Math.floor(value) : undefined;
          const evidence = { role, phase, deadline_fired: normalDeadlineFired,
            shutdown_started: shuttingDown, browser_context_close_started: browserContextCloseStarted,
            browser_context_closed: browserContextClosed, request_context_dispose_started: clientState.disposeStarted,
            request_context_disposed: clientState.disposed, window_closed: policy.normalEvidence().normal_read_window_closed };
          const timings = { window_remaining_ms: millis(policy.normalWindowRemaining(), 8000),
            elapsed_ms: millis(now - readStarted, 60000), request_timeout_ms: requestTimeout,
            request_elapsed_ms: requestStarted === undefined ? undefined : millis(now - requestStarted, 60000) };
          for (const [name, value] of Object.entries(timings)) if (value !== undefined) evidence[name] = value;
          normal.normal_read_failure = evidence;
        };
        const readStep = async (phase, action) => {
          readPhase = phase;
          try { return await step(phase, action); }
          catch { readFailurePhase ??= phase; captureFailure(phase); throw new Error('The normal read did not complete safely.'); }
        };
        try {
          if (shuttingDown || normalAdmissionClosed || !policy.normalSendAllowed(role) || !originalSource()) {
            throw new Error('The normal read scope ended.');
          }
          // One isolated storage context per send; no response cookie can cross polls or families.
          const construction = readStep('normal_context', () => playwright.request.newContext({
            ignoreHTTPSErrors: false, proxy: { server: proxy.server, bypass: '' },
          }));
          normalClientPromises.add(construction); // Retain before await, including shutdown races.
          normalClientStates.set(construction, clientState);
          const client = await construction;
          readPhase = 'normal_scope';
          const remaining = policy.normalWindowRemaining();
          if (shuttingDown || normalAdmissionClosed || remaining <= 0 || !policy.normalSendAllowed(role) || !originalSource()) {
            throw new Error('The normal read scope ended.');
          }
          normal[key + 'sends']++;
          activeRead = new Promise(resolve => { finishRead = resolve; });
          normalInFlight.add(activeRead);
          requestTimeout = Math.max(1, Math.min(10000, Math.floor(remaining)));
          requestStarted = performance.now();
          const response = await readStep('normal_get', () => client.get(request.url(), {
            headers: outbound, maxRedirects: 0, maxRetries: 0, timeout: requestTimeout,
          }));
          try {
            const status = await readStep('normal_status', () => {
              const value = response.status();
              if (!Number.isInteger(value) || value < 100 || value > 599) throw new Error('Invalid normal read status.');
              return value;
            });
            normal[key + 'http_status'] = status;
            normal[key + 'observation'] = status >= 300 && status < 400 ? 'redirect_rejected' : 'http_rejected';
            if (status === 200) {
              const fields = await readStep('normal_headers', () => response.headersArray());
              const content = fields.filter(field => field.name.toLowerCase() === 'content-type');
              const declared = fields.filter(field => field.name.toLowerCase() === 'content-length');
              const headerBytes = fields.reduce((sum, field) => sum + Buffer.byteLength(field.name + field.value), 0);
              if (headerBytes > 16384 || content.length !== 1
                  || !/^application\/json(?:\s*;[^\r\n]*)?$/iu.test(content[0].value)
                  || declared.length > 1 || (declared.length === 1
                    && (!/^[0-9]+$/u.test(declared[0].value) || Number(declared[0].value) > 65536))) {
                normal[key + 'observation'] = 'invalid_response';
              } else {
                // APIRequestContext already buffers; this is a post-buffer parse/delivery cap.
                const body = await readStep('normal_body', () => response.body());
                const observed = policy.observeNormalRead(role, body);
                normal[key + 'observation'] = observed.matched ? 'matched' : 'invalid_response';
                if (role === 'checkout_status') normal.normal_checkout_status_state = observed.state;
                if (role === 'consent_list' && observed.matched) {
                  for (const fact of ['items', 'pagination_known', 'has_more']) normal[key + fact] = observed[fact];
                }
                if (observed.matched && !shuttingDown && policy.normalSendAllowed(role) && originalSource()) {
                  const headers = Object.fromEntries(fields.filter(field => field.name.toLowerCase() !== 'set-cookie')
                    .map(field => [field.name, field.value]));
                  await readStep('normal_fulfill', () => route.fulfill({ response, headers, body }));
                  normal[key + 'delivered']++;
                  delivered = true;
                } else if (observed.matched) normal[key + 'observation'] = 'unavailable';
              }
            }
          } finally { await readStep('normal_response_dispose', () => response.dispose()); }
          if (delivered) {
            policy.markNormalReadDelivered(role); // Release only after successful disposal.
            considerCard();
          }
        } catch {
          captureFailure(readFailurePhase ?? readPhase);
          policy.failNormalRead(role);
          if (!delivered) normal[key + 'observation'] = 'unavailable';
          throw new Error('The normal read did not complete safely.');
        } finally {
          try {
            if (!delivered) {
              policy.failNormalRead(role);
              await step('normal_abort', () => route.abort('blockedbyclient'));
            }
          } finally { if (activeRead) { normalInFlight.delete(activeRead); finishRead(); } }
        }
      } else if (methodHeaders !== null) {
        // Capture once while the original normal window is still open; snapshot closure never resets it.
        const methodDeadline = instrumentSecurity ? Math.min(caseDeadline,
          performance.now() + policy.normalWindowRemaining(),
          performance.now() + security.normalWindowRemaining()) : null;
        const methodStep = async action => {
          if (!instrumentSecurity) return action();
          const remaining = Math.min(methodDeadline - performance.now(), security.normalWindowRemaining());
          if (shuttingDown || remaining <= 0) throw new Error('Original method response window ended.');
          let timer;
          try {
            const value = await Promise.race([Promise.resolve().then(() => {
              if (shuttingDown || performance.now() >= methodDeadline || security.normalWindowRemaining() <= 0) {
                throw new Error('Original method response window ended.');
              }
              return action();
            }), new Promise((_, reject) => {
              timer = setTimeout(() => reject(new Error('Original method response window ended.')), remaining);
            })]);
            if (shuttingDown || performance.now() >= methodDeadline || security.normalWindowRemaining() <= 0) {
              throw new Error('Original method response window ended.');
            }
            return value;
          } finally { clearTimeout(timer); }
        };
        const methodRead = (phase, action) => step(phase, () => methodStep(action));
        let delivered = false;
        try {
          if (shuttingDown || (normalAdmissionClosed || (instrumentSecurity
              && (securityClosed() || performance.now() >= methodDeadline)))) throw new Error('Browser preflight is shutting down.');
          if (!methodClientPromise) methodClientPromise = step('method_context', () => playwright.request.newContext({
            ignoreHTTPSErrors: false, proxy: { server: proxy.server, bypass: '' },
          }));
          const client = instrumentSecurity
            ? await methodRead('method_context', () => methodClientPromise) : await methodClientPromise;
          if (shuttingDown || (normalAdmissionClosed || (instrumentSecurity
              && (securityClosed() || performance.now() >= methodDeadline)))) throw new Error('Browser preflight is shutting down.');
          methodSendStarted = true;
          const response = await methodRead('method_get', () => client.get(request.url(), { headers: methodHeaders,
            maxRedirects: 0, maxRetries: 0, timeout: instrumentSecurity
              ? Math.max(1, Math.min(10000, methodDeadline - performance.now())) : 10000 }));
          try {
            const status = await methodRead('method_status', () => {
              const status = response.status();
              if (!Number.isInteger(status) || status < 100 || status > 599) throw new Error('Invalid method status.');
              return status;
            });
            method.method_config_response_observed = true;
            method.method_config_http_status = status;
            if (status >= 300 && status < 400) method.method_config_observation = 'redirect_rejected';
            else if (status !== 200) method.method_config_observation = 'http_rejected';
            else {
              const declared = (await methodRead('method_headers', () => response.headers()))['content-length'];
              if (declared !== undefined && (!/^[0-9]+$/u.test(declared) || Number(declared) > 262144)) {
                method.method_config_observation = 'invalid_response';
              } else {
                const body = await methodRead('method_body', () => response.body());
                method = { ...method, ...policy.observeMethodConfig(body) };
                if (policy.mayDeliverMethodConfig()) {
                  const headers = Object.fromEntries(Object.entries(await methodRead('method_headers', () => response.headers()))
                    .filter(([key]) => key.toLowerCase() !== 'set-cookie'));
                  await methodRead('method_fulfill', () => route.fulfill({ response, headers, body }));
                  method.method_config_payload_delivered = true;
                  delivered = true;
                }
              }
            }
          } finally { await step('method_response_dispose', () => response.dispose()); }
        } finally { if (!delivered) await step('method_abort', () => route.abort('blockedbyclient')); }
      } else if (payerFromMainPage
        && policy.claimPayerRequest(request.url(), request.method(), request.resourceType(),
        request.headers(), request.postData())) {
        observationStarted();
        let delivered = false;
        try {
          // Only the reviewed getter headers survive. Never follow a redirect or replay the GET.
          const response = await profileStep(() => route.fetch({ headers: {
            authorization: request.headers().authorization,
            accept: 'application/json, text/plain, */*',
            cookie: '', // Playwright otherwise adds BrowserContext cookies despite the header override.
          }, maxRedirects: 0, maxRetries: 0, timeout: instrumentSecurity
            ? Math.max(1, Math.min(10000, security.normalWindowRemaining())) : 10000 }));
          try {
            const status = response.status();
            if (!Number.isInteger(status) || status < 100 || status > 599) {
              throw new Error('Invalid payer observation status.');
            }
            payer.payer_response_observed = true;
            payer.payer_http_status = status;
            if (status >= 300 && status < 400) {
              payer.payer_observation = 'redirect_rejected';
            } else if (status !== 200) {
              payer.payer_observation = 'http_rejected';
            } else {
              const body = await profileStep(() => response.body());
              payer = { ...payer, ...policy.observePayer(body) };
              if (policy.mayDeliverPayer()) {
                policy.startNormalWindow();
                if (observeNormal) normalWindowTimer = setTimeout(expireNormalWindow, policy.normalWindowRemaining());
                await profileStep(() => route.fulfill({ response, body }));
                policy.markPayerDelivered();
                delivered = true;
              }
            }
          } finally { await response.dispose(); }
        } finally {
          // Default probe always withholds. Explicit rendering can deliver only the verified own payload.
          try { if (!delivered) await route.abort('blockedbyclient'); } finally { observationDone(); }
        }
      } else if (action === 'return') {
        counts.returns_intercepted++;
        await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Returned</title>' });
      } else if (action === 'allow') {
        // continue() can follow redirects without re-running the route handler.
        // Fetch exactly this approved GET once, then deliver only a nonredirect response.
        // The public bootstrap receives no page credentials, cookies or private referrer.
        const headers = controller ? { accept: '*/*', cookie: '', referer: '' } : { ...request.headers() };
        if (!controller) delete headers.referer;
        if (shuttingDown || (instrumentSecurity ? securityClosed() : normalAdmissionClosed)) throw new Error('Browser preflight is shutting down.');
        if (controller && !publicClientPromise) {
          // Separate storage prevents even a public Set-Cookie from changing the browser's cookie jar.
          publicClientPromise = playwright.request.newContext({
            ignoreHTTPSErrors: false, proxy: { server: proxy.server, bypass: '' },
          });
        }
        const publicClient = controller ? await profileStep(() => publicClientPromise) : null;
        if (shuttingDown || (instrumentSecurity ? securityClosed() : normalAdmissionClosed)) throw new Error('Browser preflight is shutting down.');
        const options = { headers, maxRedirects: 0, maxRetries: 0, timeout: instrumentSecurity
          ? Math.max(1, Math.min(10000, security.normalWindowRemaining())) : 10000 };
        const response = await profileStep(() => controller
          ? publicClient.get(request.url(), options) : route.fetch(options));
        try {
          if (controller) {
            const status = response.status();
            if (!Number.isInteger(status) || status < 100 || status > 599) throw new Error('Invalid public asset status.');
            counts.controller_http_status = status;
          }
          if ((controller && response.status() !== 200) || (response.status() >= 300 && response.status() < 400)) {
            counts.blocked_requests++;
            if (renderFrame) policy.noteRenderCheck('document_redirect');
            await route.abort('blockedbyclient');
          } else {
            const body = await profileStep(() => response.body());
            if (body.length > 2 * 1024 * 1024) throw new Error('Browser response exceeds the preflight boundary.');
            if (renderFrame && (response.status() !== 200
                || (fixtureOrigin === null && (!body.includes('window.checkoutVersion="v2.56.0"')
                  || !body.includes('/static/js/vendor.61207a66.js')
                  || !body.includes('/static/js/checkout.d2f9eaef.js'))))) {
              policy.noteRenderCheck('document_http_or_build_mismatch');
              throw new Error('The reviewed read-only frame document was not observed.');
            }
            if (expectedHash !== null) {
              if (!assetMatches(body, expectedHash)) {
                counts.integrity_rejections++;
                await route.abort('blockedbyclient');
                throw new Error('Browser script integrity did not match the reviewed build.');
              }
              if (controller) counts.verified_controller_assets++;
              else if (policy.isRenderAsset(request.url())) counts.verified_render_assets++;
              else counts.verified_static_assets++;
            }
            if (renderFrame) {
              policy.markRenderFrameDelivered();
              boundFrame = request.frame();
              documents?.bind(boundFrame, renderFrameSource, sourceGeneration);
              sameDocument?.bind(boundFrame, mainPage.mainFrame(), renderFrameSource);
            }
            if (response.status() === 200 && request.resourceType() === 'document'
                && request.url() === initial && request.frame() === mainPage.mainFrame()) policy.startParentCountryWindow();
            if (controller) {
              const publicHeaders = Object.fromEntries(Object.entries(response.headers())
                .filter(([key]) => key.toLowerCase() !== 'set-cookie'));
              await profileStep(() => route.fulfill({ response, headers: publicHeaders, body }));
            } else await profileStep(() => route.fulfill({ response, body }));
            if (renderFrame) {
              counts.bound_render_frames++;
              policy.noteRenderCheck('fulfilled');
            }
          }
        } finally { await response.dispose(); }
      } else {
        counts.blocked_requests++;
        policy.noteDeniedRead(request.url(), request.method(), request.resourceType());
        risk.noteDenied(request.url());
        await route.abort('blockedbyclient');
        denial?.commit();
      }
    }));
    await context.routeWebSocket(/.*/, socket => {
      counts.blocked_websockets++;
      return track(() => socket.close(), 'websocket_close'); // No connectToServer: no remote handshake.
    });
    context.on('page', opened => {
      try {
        opened.on('dialog', dialog => { void track(() => dialog.dismiss(), 'dialog_dismiss'); });
      } catch { markFailure('page_setup'); }
    });
    const page = await step('page_setup', () => context.newPage());
    mainPage = page;
    if (instrumentSecurity) {
      let mainNavigations = 0;
      const frameNavigations = new WeakMap();
      page.on('framenavigated', frame => {
        let stoppedBound = false;
        if (frame === page.mainFrame() && ++mainNavigations > 1) {
          sourceGeneration++; security.close('main_navigated'); pendingLoader.close();
        } else if (frame === boundFrame && (frameNavigations.get(frame) ?? 0) > 0) {
          if (!sameDocument?.retain(frame)) {
            sourceGeneration++; security.close('bound_navigated'); pendingLoader.close();
            stoppedBound = true;
          }
        }
        if (frame === page.mainFrame() || frame === boundFrame) {
          frameNavigations.set(frame, (frameNavigations.get(frame) ?? 0) + 1);
        }
        documents.navigated(frame, sourceGeneration, stoppedBound);
      });
      page.on('framedetached', frame => { if (frame === boundFrame) {
        sourceGeneration++; security.close('bound_detached'); pendingLoader.close();
      } });
    }
    page.setDefaultTimeout(3000);
    page.setDefaultNavigationTimeout(10000);
    await step('navigation', () => page.goto(initial, { waitUntil: 'domcontentloaded' }));
    if (policy.requestScopeVerified()) {
      let timer;
      await Promise.race([started, new Promise(resolve => { timer = setTimeout(resolve, 5000); })]);
      clearTimeout(timer);
      if (policy.bindingEvidence().payer_get_attempted) await observed;
    }
    if (policy.bindingEvidence().payer_payload_delivered) {
      const delay = observeNormal ? Math.max(0, policy.normalWindowRemaining() - 2500) : 10000;
      await new Promise(resolve => setTimeout(resolve, delay));
    }
    cardChoice.capture(); // Fixed snapshot: cancel actions without waiting for a new field state.
    sameDocument?.close(); // The exception ends at this original capture, before field inspection.
    if (observeConfig) {
      readiness = { card_readiness_observed: false };
      if (boundFrame) {
        const detached = stage => {
          try {
            const value = boundFrame.isDetached();
            if (typeof value !== 'boolean') throw new Error('Invalid frame attachment observation.');
            readinessAttachment[stage === 'before_count'
              ? 'readiness_frame_detached_before_count' : 'readiness_frame_detached_after_count'] = value;
            return value;
          } catch { markFailure('readiness_attachment'); throw new Error('Browser preflight failed safely.'); }
        };
        const unavailable = stage => ({ card_readiness_observed: false,
          card_readiness_unavailable_reason: 'frame_detached', readiness_frame_detached: true,
          readiness_detach_stage: stage });
        const observations = {};
        for (const name of ['cardnumber', 'expiry', 'cvc']) {
          readinessAttachment = { readiness_attachment_field: name };
          if (detached('before_count')) { readiness = unavailable('before_count'); break; }
          let phase = `readiness_${name}_locator`;
          let countStarted = false;
          try {
            const field = boundFrame.locator(`input[name="${name}"]`);
            phase = `readiness_${name}_count`;
            countStarted = true;
            const count = await normalStep(() => field.count());
            if (detached('after_count')) { readiness = unavailable('after_count'); break; }
            if (selectCard) {
              if (!Number.isInteger(count) || count < 0) throw new Error('Unavailable native field count.');
              observations[`${name}_match_count`] = count === 0 ? 'zero' : count === 1 ? 'one' : 'multiple';
            }
            observations[`${name}_unique`] = count === 1;
            phase = `readiness_${name}_visible`;
            observations[`${name}_visible`] = count === 1 && await normalStep(() => field.isVisible());
            phase = `readiness_${name}_enabled`;
            observations[`${name}_enabled`] = count === 1 && await normalStep(() => field.isEnabled());
            if (detached('after_count')) { readiness = unavailable('after_count'); break; }
          } catch {
            if (detached(countStarted ? 'after_count' : 'before_count')) {
              readiness = unavailable(countStarted ? 'after_count' : 'before_count'); break;
            }
            readiness = { card_readiness_observed: false };
            markFailure(phase);
            throw new Error('Browser preflight failed safely.');
          }
        }
        if (readiness.card_readiness_unavailable_reason === undefined) {
          readiness = { card_readiness_observed: true, ...observations };
        }
      }
    }
    if (observeNormal) {
      normal.normal_read_inspection_completed = readiness.card_readiness_observed === true
        && policy.normalWindowRemaining() > 500;
      normalAdmissionClosed = true; // No new request or late context-construction send after the fixed snapshot.
      await Promise.all([drainSentNormalReads(), parentCountry.drainSent(), currencySettings.drainSent()]); // Already sent reads only.
      policy.closeNormalWindow();
      if (instrumentSecurity) clearTimeout(normalWindowTimer);
    }
    if (instrumentSecurity) {
      // No normal/UI admission is reopened. Only the closed security observer remains live.
      while (!securityClosed()) await new Promise(resolve => setTimeout(resolve, Math.min(25, security.admissionRemaining())));
      if (!await risk.drainSent()) markFailure('risk_shutdown');
    }
    const visible = await step('visible_label', () =>
      page.getByText(/^(test mode|sandbox)$/iu, { exact: true }).first().isVisible());
    if (exercise !== null) await exercise(page, context);
    result = {
      result: fixtureOrigin === null ? 'blocked' : 'pass',
      reason: fixtureOrigin === null ? 'financial_sandbox_boundary_unverified' : 'local_fixture_only',
      navigation_passed: true, test_mode_visible: visible,
      sandbox_origin_verified: false, card_entry_enabled: false,
      ...(policy.candidateOrigin() === null ? {} : { candidate_origin: policy.candidateOrigin() }),
      ...policy.bindingEvidence(), ...cardChoice.evidence(), ...parentCountry.evidence(), ...currencySettings.evidence(), ...payer, ...(observeNormal ? { ...normal, ...policy.normalEvidence() } : {}), ...(observeConfig ? { ...method, ...readiness, ...readinessAttachment, method_config_send_started: methodSendStarted } : {}),
      browser_version: /^[0-9]+(?:\.[0-9]+){1,3}$/u.test(browser.version()) ? browser.version() : 'unclassified',
      ...counts, proxy_rejections: proxy.counts.rejected,
      proxy_connections: proxy.counts.connected,
      proxy_external_connections: proxy.counts.externalConnected,
    };
  } catch {
    markFailure('unclassified');
  } finally {
    cardChoice.capture();
    if (observeNormal) { normalAdmissionClosed = true; await Promise.all([drainSentNormalReads(), parentCountry.drainSent(), currencySettings.drainSent()]); }
    shuttingDown = true;
    security?.close(); pendingLoader?.close();
    documents?.close();
    sameDocument?.close();
    policy.closeNormalWindow();
    clearTimeout(normalWindowTimer);
    clearTimeout(watchdog);
    if (!await currencySettings.close() || currencySettings.failed()) markFailure('currency_settings_read');
    if (!await parentCountry.close() || parentCountry.failed()) markFailure('parent_country_read');
    await track(async () => {
      if (!await risk.close()) throw new Error('Risk relay did not close safely.');
    }, 'risk_shutdown');
    if (context) { browserContextCloseStarted = true; await track(() => context.close(), 'shutdown_browser_context'); }
    if (browser) await track(() => browser.close(), 'shutdown_browser');
    for (const promise of [publicClientPromise, methodClientPromise, ...normalClientPromises]) if (promise) await track(async () => {
      const client = await promise;
      const state = normalClientStates.get(promise);
      if (state) state.disposeStarted = true;
      await client.dispose();
      if (state) state.disposed = true;
    }, 'shutdown_request_context');
    await Promise.all([...pending]);
    if (proxy) await track(() => proxy.close(), 'shutdown_proxy');
    await track(() => fs.rm(directory, { recursive: true, force: true }), 'shutdown_directory');
    documents?.finish();
  }
  if (failed || result === undefined) throw new PreflightFailure({
    result: 'fail', reason: 'browser_preflight_failed', card_entry_enabled: false,
    browser_failure_phase: firstFailure ?? 'unclassified', ...counts,
    ...(policy.candidateOrigin() === null ? {} : { candidate_origin: policy.candidateOrigin() }),
    ...policy.bindingEvidence(), ...cardChoice.evidence(), ...parentCountry.evidence(), ...currencySettings.evidence(), ...payer, ...policy.renderEvidence(), ...policy.methodEvidence(),
    ...(observeNormal ? { ...normal, ...policy.normalEvidence() } : {}),
    ...(observeConfig ? { ...method, ...readiness, ...readinessAttachment, method_config_send_started: methodSendStarted } : {}),
    ...risk.evidence(), ...(security?.evidence() ?? {}), ...(pendingLoader?.evidence() ?? {}),
    ...(documents?.evidence() ?? {}),
    ...(sameDocument?.evidence() ?? {}),
  });
  return { ...result, ...counts, ...(security?.evidence() ?? {}), ...(pendingLoader?.evidence() ?? {}), ...(sameDocument?.evidence() ?? {}), ...(documents?.evidence() ?? {}), ...cardChoice.evidence(), ...risk.evidence(), ...parentCountry.evidence(), ...currencySettings.evidence(), ...(observeNormal ? { ...normal, ...policy.normalEvidence() } : {}), ...policy.renderEvidence(), ...policy.methodEvidence(), proxy_rejections: proxy.counts.rejected,
    proxy_connections: proxy.counts.connected, proxy_external_connections: proxy.counts.externalConnected };
}

async function main(instrumentSecurity = false) {
  // Only a fixed module location is an argument; the private URL arrives via stdin.
  if (process.argv.length !== 3 || !path.isAbsolute(process.argv[2])) throw new Error('Invalid browser configuration.');
  const chunks = [];
  let bytes = 0;
  for await (const chunk of process.stdin) {
    bytes += chunk.length;
    if (bytes > 20000) throw new Error('Invalid private browser input.');
    chunks.push(chunk);
  }
  const input = JSON.parse(Buffer.concat(chunks).toString('utf8'));
  if (!input || typeof input !== 'object' || Array.isArray(input)
      || !['expected,url', 'expected,render,url', 'expected,observeConfig,render,url', 'expected,observeConfig,observeNormal,render,url', 'expected,observeConfig,observeNormal,observeRisk,render,riskJournal,url', 'expected,observeConfig,observeNormal,observeParentCountry,render,url', 'expected,observeConfig,observeNormal,observeParentCountry,observeRisk,render,riskJournal,url', 'expected,observeConfig,observeCurrencySettings,observeNormal,render,url', 'expected,observeConfig,observeCurrencySettings,observeNormal,observeParentCountry,render,url', 'expected,observeConfig,observeCurrencySettings,observeNormal,observeRisk,render,riskJournal,url', 'expected,observeConfig,observeCurrencySettings,observeNormal,observeParentCountry,observeRisk,render,riskJournal,url'].includes(Object.keys(input).filter(key => key !== 'selectCard' && key !== 'retainSameDocument').sort().join(','))
      || (Object.hasOwn(input, 'retainSameDocument') && (!instrumentSecurity || input.retainSameDocument !== true))
      || (Object.hasOwn(input, 'selectCard') && (input.selectCard !== true || input.observeNormal !== true))
      || (Object.hasOwn(input, 'observeRisk') && input.observeRisk !== true)
      || (input.observeRisk === true && input.observeNormal !== true)
      || (Object.hasOwn(input, 'observeParentCountry') && (typeof input.observeParentCountry !== 'boolean' || (input.observeParentCountry && input.observeNormal !== true)))
      || (Object.hasOwn(input, 'observeCurrencySettings') && (typeof input.observeCurrencySettings !== 'boolean'
        || (input.observeCurrencySettings && input.observeNormal !== true)))
      || (Object.hasOwn(input, 'observeNormal') && typeof input.observeNormal !== 'boolean')
      || (Object.hasOwn(input, 'observeConfig') && typeof input.observeConfig !== 'boolean')
      || (Object.hasOwn(input, 'render') && typeof input.render !== 'boolean') || typeof input.url !== 'string'
      || !input.expected || typeof input.expected !== 'object') {
    throw new Error('Invalid private browser input.');
  }
  if (instrumentSecurity && (input.render !== true || input.observeConfig !== true || input.observeNormal !== true
      || input.observeRisk !== true || Object.hasOwn(input, 'selectCard'))) throw new Error('Invalid fixed security input.');
  // Reject non-provider input before requiring any browser module or launching Chrome.
  new HostedPolicy(input.url, null, input.expected, input.render ?? false, input.observeConfig ?? false, input.observeNormal ?? false, undefined, input.observeParentCountry ?? false, input.observeCurrencySettings ?? false);
  startupPhase = 'tooling_load';
  const playwright = require(process.argv[2]);
  const cardRuntime = input.selectCard === true ? await verifyCardRuntime(process.argv[2]) : null;
  startupPhase = 'browser_startup';
  const journal = input.observeRisk === true ? RiskJournalClient.fromDescriptors(input.riskJournal) : null;
  const result = await inspect(input.url, playwright, null, null, input.expected, input.render ?? false, input.observeConfig ?? false, input.observeNormal ?? false, journal, null, input.observeParentCountry ?? false, input.observeCurrencySettings ?? false, input.selectCard ?? false, cardRuntime, instrumentSecurity, input.retainSameDocument ?? false, process.argv[2]);
  process.stdout.write(JSON.stringify(result) + '\n');
}

if (require.main === module) {
  main().catch(error => {
    const safe = error instanceof PreflightFailure ? error.evidence() : { result: 'fail',
      reason: 'browser_preflight_failed', card_entry_enabled: false, browser_failure_phase: startupPhase };
    process.stdout.write(JSON.stringify(safe) + '\n');
    process.exitCode = 1;
  });
}
module.exports = { inspect, BoundDocumentObservations, SameDocumentRetention, verifyNavigationRuntime, instrumentMain: async () => {
  try { await main(true); } catch (error) {
    const safe = error instanceof PreflightFailure ? error.evidence() : { result: 'fail',
      reason: 'browser_preflight_failed', card_entry_enabled: false, browser_failure_phase: startupPhase };
    process.stdout.write(JSON.stringify(safe) + '\n'); process.exitCode = 1;
  }
} };
