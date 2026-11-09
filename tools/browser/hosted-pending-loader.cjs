'use strict';

const sourceStops = new Set(['not_observed', 'document_frame_unavailable', 'main_document_request',
  'bound_document_request', 'main_navigated', 'bound_navigated', 'bound_detached',
  'admission_case_reserve_elapsed', 'admission_loader_window_elapsed', 'release_case_reserve_elapsed',
  'release_loader_window_elapsed', 'watchdog_shutdown', 'teardown']);

// Only the separate fixed entry can select this profile. It never grants a new network role.
class InstrumentSecurityScope {
  #parent; #case; #start; #closed = false; #clock; #firstStop = 'not_observed';
  constructor(parent, caseDeadline, clock = () => performance.now()) {
    if (!Number.isFinite(caseDeadline) || typeof clock !== 'function') throw new Error('Invalid security scope.');
    this.#parent = parent; this.#case = caseDeadline; this.#clock = clock;
  }
  enter() { this.#start ??= this.#clock(); }
  noteStop(reason) {
    if (this.#firstStop === 'not_observed' && sourceStops.has(reason)) this.#firstStop = reason;
  }
  firstStop() { return this.#firstStop; }
  close(reason = 'teardown') { this.#closed = true; this.noteStop(reason); }
  admissionRemaining() {
    if (this.#closed) return 0;
    let caseLimit, loaderLimit;
    const remaining = Math.max(0, Math.min(caseLimit = this.#case - 10000,
      loaderLimit = this.#start === undefined ? Infinity : this.#start + 15000) - this.#clock());
    if (remaining <= 0) this.noteStop(caseLimit <= loaderLimit
      ? 'admission_case_reserve_elapsed' : 'admission_loader_window_elapsed');
    return remaining;
  }
  normalWindowRemaining() {
    if (this.#closed) return 0;
    let caseLimit, loaderLimit, unstarted;
    const remaining = Math.max(0, Math.min(caseLimit = this.#case - 2000,
      loaderLimit = (unstarted = this.#start === undefined) ? this.#case - 10000 : this.#start + 15000) - this.#clock());
    if (remaining <= 0) this.noteStop(unstarted || caseLimit <= loaderLimit
      ? 'release_case_reserve_elapsed' : 'release_loader_window_elapsed');
    return remaining;
  }
  renderEnabled() { return this.#parent.renderEnabled(); }
  bindingEvidence() { return this.#parent.bindingEvidence(); }
  normalEvidence() { return this.#parent.normalEvidence(); }
  normalSourceMatches(...args) { return this.#parent.normalSourceMatches(...args); }
  riskSessionMatches(...args) { return this.#parent.riskSessionMatches(...args); }
  evidence() { return { instrument_security_requested: true, instrument_ready: false,
    financial_permission: false, security_graph_complete: false, security_source_first_stop: this.#firstStop }; }
  toJSON() { throw new Error('Private security scope cannot be serialized.'); }
}

// One original route can wait, but this component owns no HTTP client or journal.
class PendingLoader {
  #risk; #scope; #used = false; #closed = false; #promoted = false; #reason = 'not_observed';
  constructor(risk, scope) { this.#risk = risk; this.#scope = scope; }
  close() { this.#closed = true; }
  evidence() { return { security_loader_candidate: this.#used, security_loader_promoted: this.#promoted,
    security_loader_wait_reason: this.#reason }; }
  async handle(route, context, state, closing, generation) {
    const request = route.request();
    if (!this.#risk.loaderTarget(request.url())) return false;
    let frame, document, epoch;
    try { frame = request.frame(); document = frame.url(); epoch = generation(); } catch { return false; }
    const original = () => {
      try { const current = state(); return !this.#closed && !closing() && epoch === generation()
        && !frame.isDetached() && frame.url() === document && this.#scope.admissionRemaining() > 0
        && ((frame === current.main && this.#scope.normalSourceMatches(document, true))
          || (frame === current.bound && this.#scope.normalSourceMatches(document))); } catch { return false; }
    };
    let valid = false;
    try {
      const url = new URL(request.url());
      valid = request.method() === 'GET' && request.resourceType() === 'script' && request.url() === url.href
        && !url.username && !url.password && !url.hash && /^[A-Za-z0-9_-]{1,128}$/u.test(url.searchParams.get('sessionId') ?? '')
        && [...url.searchParams.keys()].sort().join(',') === 'disable3rdTracking,hasScriptId,sessionId'
        && url.searchParams.get('hasScriptId') === 'false' && url.searchParams.get('disable3rdTracking') === 'false';
    } catch { /* No value escapes the private route. */ }
    if (this.#used || !valid || !original()) {
      this.#closed = true; this.#reason = this.#used ? 'duplicate' : 'scope';
      await route.abort('blockedbyclient'); return true;
    }
    this.#used = true; this.#scope.enter();
    const deadline = Math.min(performance.now() + 2000, performance.now() + this.#scope.admissionRemaining());
    try {
      const session = new URL(request.url()).searchParams.get('sessionId');
      while (original() && performance.now() < deadline) {
        const current = state();
        if (current.bound && this.#scope.riskSessionMatches(session, frame === current.main)) {
          this.#promoted = true; this.#reason = 'promoted';
          return await this.#risk.handle(route, context, current.main, current.bound,
            () => this.#closed || closing() || epoch !== generation(), () => this.#scope.admissionRemaining() <= 0);
        }
        await new Promise(resolve => setTimeout(resolve, Math.min(10, Math.max(1, deadline - performance.now()))));
      }
      this.#reason = original() ? 'expired' : 'scope'; this.#closed = true;
      await route.abort('blockedbyclient'); return true;
    } finally { /* Candidate and its result never become replaceable. */ }
  }
  toJSON() { throw new Error('Private loader candidate cannot be serialized.'); }
}

module.exports = { InstrumentSecurityScope, PendingLoader };
