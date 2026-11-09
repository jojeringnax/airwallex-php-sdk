'use strict';

const { HostedRiskPolicy, riskCookieNames } = require('./hosted-risk-policy.cjs');
const { HostedRiskRelay } = require('./hosted-risk-relay.cjs');

// Private stdout codec: exactly eight ASCII bytes replace only the eight-byte withheld value.
const preflightCodes = Object.freeze({ response_absent: 'r_absent', fields_not_array: 'f_array_',
  body_not_buffer: 'b_buffer', body_limit: 'b_limit_', status_not_integer: 's_int___',
  duplicate_selected_header: 'h_dup___', status_not_2xx: 's_2xx___', cors_origin: 'c_origin',
  cors_methods_post: 'c_post__', cors_headers_content_type: 'c_type__' });

// No input actions or financial request roles exist in this component.
class HostedRiskObserver {
  #policy;
  #parent;
  #journal;
  #playwright;
  #fixture;
  #proxy;
  #relay;
  #publicClient;
  #publicDisposal;
  #deadline;
  #caseDeadline;
  #handlers = new Set();
  #responseDisposals = new Map();
  #cleanupFailed = false;
  #closed = false;
  #beforeCookies = null;
  #chain = Promise.resolve();
  #waiting = 0;
  #window = 8000;
  #instrumentSecurity = false;
  #loaderValidation = 'not_reached';
  #loaderFailure = null;
  #loaderPhase = 'pre_send';
  #telemetryFailure = null;
  #validation = {};
  #preflightGate = null;
  #shutdownFailure = null;
  constructor(parent, journal, playwright, fixture = null, caseDeadline = performance.now() + 40000, instrumentSecurity = false) {
    if (typeof instrumentSecurity !== 'boolean') throw new Error('Invalid security profile.');
    this.#instrumentSecurity = instrumentSecurity;
    this.#window = instrumentSecurity ? 15000 : 8000;
    if (!Number.isFinite(caseDeadline)) throw new Error('Risk case deadline is invalid.');
    this.#caseDeadline = caseDeadline;
    this.#parent = parent;
    this.#journal = journal;
    this.#playwright = playwright;
    this.#fixture = fixture;
    this.#policy = new HostedRiskPolicy(parent, journal !== null, fixture);
  }

  loaderTarget(value) { return this.#policy.loaderTarget(value); }

  start(proxy) {
    this.#proxy = proxy;
    this.#relay = this.#journal === null ? null : new HostedRiskRelay(proxy,
      this.#fixture === null ? null : { origin: this.#fixture.bwsOrigin, ca: this.#fixture.ca }, this.#instrumentSecurity);
  }

  authority(host, port) {
    return !this.#closed && performance.now() < this.#caseDeadline && this.#policy.authority(host, port);
  }
  noteDenied(value) { this.#policy.noteDenied(value); }
  evidence() { return { ...this.#policy.evidence(), ...(this.#journal?.evidence() ?? {}),
    ...(this.#instrumentSecurity ? { security_loader_validation: this.#loaderValidation,
      ...(this.#loaderFailure ?? {}), ...this.#validationEvidence(), ...(this.#telemetryFailure ?? {}),
      ...(this.#shutdownFailure === null ? {} : { security_telemetry_shutdown_failure_stage: this.#shutdownFailure }) } : {}) }; }

  #validationEvidence() {
    const facts = { ...this.#validation };
    if (facts.security_preflight_validation === 'withheld' && this.#preflightGate !== null) {
      facts.security_preflight_validation = preflightCodes[this.#preflightGate];
    }
    return facts;
  }

  #telemetryRole(operation, role) {
    if (this.#instrumentSecurity) {
      if (operation.phase === 'queued_guard') operation.phase = undefined;
      if (['preflight', 'visitor', 'event'].includes(role)) operation.role = role;
    }
    return role; // Tap an original operand; never read a claim to populate a diagnostic.
  }

  #telemetryFailed(operation, gate = 'operation_failed', phase = operation.phase) {
    if (!this.#instrumentSecurity || this.#telemetryFailure !== null) return;
    if (phase === undefined) { this.#telemetryFailure = {}; return; }
    this.#telemetryFailure = { security_telemetry_first_failure_phase: phase,
      security_telemetry_first_failure_gate: gate,
      ...(operation.role === undefined ? {} : { security_telemetry_first_failure_role: operation.role }) };
  }

  #telemetryReject(operation, value, gate) {
    if (value) this.#telemetryFailed(operation, gate);
    return value;
  }

  #validated(operation, value) {
    if (!this.#instrumentSecurity || operation.role === undefined) return;
    const key = 'security_' + operation.role + '_validation';
    if (this.#validation[key] === undefined || this.#validation[key] === 'matched') {
      this.#validation[key] = value;
      if (operation.role === 'preflight' && value === 'withheld'
          && Object.hasOwn(preflightCodes, operation.validationGate)) this.#preflightGate = operation.validationGate;
    }
    if (value !== 'matched') this.#telemetryFailed(operation);
  }

  #shutdownResult(stage, value) {
    if (this.#instrumentSecurity && !value && this.#shutdownFailure === null) this.#shutdownFailure = stage;
    return value;
  }

  #priorCleanup(value) {
    if (value) this.#shutdownResult('prior_cleanup_failed', false);
    return value;
  }

  #loaderFailed(phase, gate) {
    if (!this.#instrumentSecurity || this.#loaderFailure !== null) return;
    let stop = 'not_observed';
    try { stop = this.#parent.firstStop?.() ?? 'not_observed'; } catch { /* Diagnostic memo only. */ }
    if (!['not_observed', 'document_frame_unavailable', 'main_document_request', 'bound_document_request',
      'main_navigated', 'bound_navigated', 'bound_detached', 'admission_case_reserve_elapsed',
      'admission_loader_window_elapsed', 'release_case_reserve_elapsed', 'release_loader_window_elapsed',
      'watchdog_shutdown', 'teardown'].includes(stop)) stop = 'not_observed';
    this.#loaderFailure = { security_loader_first_failure_phase: phase, security_loader_first_failure_gate: gate,
      security_loader_scope_stop_at_failure: stop };
  }

  #loaderReject(value, phase, gate) {
    if (value) this.#loaderFailed(phase, gate);
    return value;
  }

  #rememberDeadline() {
    const remaining = Math.min(this.#parent.normalWindowRemaining(), this.#caseDeadline - performance.now());
    if (!Number.isFinite(remaining) || remaining <= 0) throw new Error('Risk scope is unavailable.');
    this.#deadline ??= performance.now() + Math.min(this.#window, remaining);
  }

  async #bounded(promise) {
    const remaining = Math.max(0, Math.min(this.#caseDeadline, this.#deadline ?? performance.now() + 100) - performance.now());
    let timer;
    try {
      return await Promise.race([Promise.resolve(promise).then(value => value !== false, () => false),
        new Promise(resolve => { timer = setTimeout(() => resolve(false), remaining); })]);
    } finally { clearTimeout(timer); }
  }

  #disposePublic(client) {
    this.#publicDisposal ??= Promise.resolve().then(() => client.dispose()).then(() => true, () => false);
    return this.#publicDisposal;
  }

  #disposeResponse(response) {
    if (!this.#responseDisposals.has(response)) {
      this.#responseDisposals.set(response,
        Promise.resolve().then(() => response.dispose()).then(() => true, () => false));
    }
    return this.#responseDisposals.get(response);
  }

  async #value(promise) {
    let result;
    if (!await this.#bounded(Promise.resolve(promise).then(value => { result = value; return true; }))) {
      this.#closed = true; this.#cleanupFailed = true; this.#policy.poison('window');
      throw new Error('Risk operation did not finish inside its original bound.');
    }
    return result;
  }

  #original(frame, main, bound, phase = null) {
    if (this.#instrumentSecurity && phase !== null) {
      try {
        const inCase = performance.now() < this.#caseDeadline;
        if (!inCase) { this.#loaderFailed(phase, 'case_bound_failed'); return inCase; }
        const detached = frame.isDetached();
        if (detached !== false) {
          this.#loaderFailed(phase, detached === true ? 'frame_detached' : 'attachment_unavailable'); return false;
        }
        let reason = 'unrelated_frame';
        const branch = (value, label) => { if (!value) reason = label; return value; };
        const matched = (frame === main && branch(this.#parent.normalSourceMatches(frame.url(), true), 'main_source_mismatch'))
          || (frame === bound && branch(this.#parent.normalSourceMatches(frame.url()), 'bound_source_mismatch'));
        if (!matched) this.#loaderFailed(phase, reason);
        return matched;
      } catch { this.#loaderFailed(phase, 'original_probe_exception'); return false; }
    }
    try {
      return performance.now() < this.#caseDeadline && frame.isDetached() === false
        && ((frame === main && this.#parent.normalSourceMatches(frame.url(), true))
        || (frame === bound && this.#parent.normalSourceMatches(frame.url())));
    } catch { return false; }
  }

  handle(route, context, main, bound, closing, admissionClosed = () => false) {
    const task = this.#handle(route, context, main, bound, closing, admissionClosed);
    this.#handlers.add(task);
    void task.then(() => this.#handlers.delete(task), () => this.#handlers.delete(task));
    return task;
  }

  async #handle(route, context, main, bound, closing, admissionClosed) {
    if (this.#journal === null) return false;
    if (admissionClosed()) return false;
    const request = route.request();
    let frame;
    try { frame = request.frame(); } catch { return false; }
    if (!this.#original(frame, main, bound)) return false;
    const role = frame === main ? 'main' : 'dropin';
    const document = frame.url();
    if (this.#policy.loaderTarget(request.url())) {
      let delivered = false;
      try {
        this.#rememberDeadline();
        // Only these source-defined attributes enter private memory; no DOM text or input values are read.
        const proof = await this.#value(frame.evaluate(value => {
          const matches = [...document.scripts].filter(element => element.src === value);
          if (matches.length !== 1) return null;
          const script = matches[0];
          return { id: script.id, orderSession: script.getAttribute('data-order-session-id'),
            tenant: script.getAttribute('data-tenant') ?? script.getAttribute('tenant'),
            project: script.getAttribute('data-project-name'),
            disableTracking: script.getAttribute('data-disable-3rd-tracking') };
        }, request.url()));
        const query = new URL(request.url()).searchParams;
        const claim = this.#policy.claimLoader(request.url(), request.method(), request.resourceType(),
          proof === null ? null : { ...proof, document, role,
            session: query.get('sessionId'), merchant: query.get('merchantId') });
        if (!claim || this.#loaderReject(this.#closed, 'pre_send', 'observer_closed')
            || this.#loaderReject(closing(), 'pre_send', 'caller_closing')
            || this.#loaderReject(admissionClosed(), 'pre_send', 'admission_closed')
            || !this.#original(frame, main, bound, 'pre_send')) return true;
        this.#beforeCookies = await this.#value(context.cookies());
        if (this.#beforeCookies.some(cookie => riskCookieNames.has(cookie.name))) {
          this.#policy.note('cookie_unbound'); return true;
        }
        if (this.#loaderReject(this.#closed, 'pre_send', 'observer_closed')
            || this.#loaderReject(closing(), 'pre_send', 'caller_closing')
            || this.#loaderReject(admissionClosed(), 'pre_send', 'admission_closed')
            || !this.#original(frame, main, bound, 'pre_send')) return true;
        // Retain construction before await; shutdown disposes every created public context.
        this.#rememberDeadline();
        if (this.#instrumentSecurity) {
          const target = request.url();
          const selected = { role: 'loader', ordinal: 0, url: target, headers: { accept: '*/*' },
            body: Buffer.alloc(0), scope: claim.scope,
            sessionHash: require('node:crypto').createHash('sha256').update(query.get('sessionId')).digest('hex') };
          const deadline = Math.min(this.#deadline, this.#caseDeadline,
            performance.now() + this.#parent.normalWindowRemaining());
          const response = await this.#value(this.#journal.run(selected, async () => {
            if (this.#loaderReject(this.#closed, 'pre_send', 'observer_closed')
                || this.#loaderReject(closing(), 'pre_send', 'caller_closing')
                || this.#loaderReject(admissionClosed(), 'pre_send', 'admission_closed')
                || !this.#original(frame, main, bound, 'pre_send')
                || !this.#policy.live(gate => this.#loaderFailed('pre_send', gate))) throw new Error('Loader scope is unavailable.');
            this.#loaderPhase = 'send_guard';
            return this.#relay.send(target, 'GET', 'loader', selected.headers, selected.body, deadline,
              () => !this.#loaderReject(this.#closed, 'send_guard', 'observer_closed')
                && !this.#loaderReject(closing(), 'send_guard', 'caller_closing')
                && !this.#loaderReject(admissionClosed(), 'send_guard', 'admission_closed')
                && this.#original(frame, main, bound, 'send_guard'));
          }, value => {
            this.#loaderPhase = 'response_validation';
            try {
              const status = value.status === 200;
              if (!status) this.#loaderFailed('response_validation', 'http_status');
              const matched = status && this.#policy.verifyLoader(value.body,
                gate => this.#loaderFailed('response_validation', gate));
              this.#loaderValidation = matched ? 'matched' : 'withheld';
              return matched;
            } catch (error) {
              this.#loaderValidation = 'exception'; this.#loaderFailed('response_validation', 'validator_exception');
              throw error;
            }
          }, deadline));
          this.#loaderPhase = 'before_delivery';
          if (this.#loaderReject(this.#closed, 'before_delivery', 'observer_closed')
              || this.#loaderReject(closing(), 'before_delivery', 'caller_closing')
              || !this.#original(frame, main, bound, 'before_delivery')
              || !this.#policy.live(gate => this.#loaderFailed('before_delivery', gate))) return true;
          this.#loaderPhase = 'delivery';
          await this.#value(route.fulfill({ status: response.status,
            headers: this.#policy.responseHeaders(response), body: response.body }));
          delivered = true;
          return true;
        }
        this.#publicClient ??= this.#playwright.request.newContext({ ignoreHTTPSErrors: false,
          proxy: { server: this.#proxy, bypass: '' } }).then(client => {
          // Retain late completion even when bounded shutdown already reported failure.
          if (this.#closed) void this.#disposePublic(client);
          return client;
        });
        const client = await this.#value(this.#publicClient);
        if (this.#closed || closing() || admissionClosed() || !this.#original(frame, main, bound) || !this.#policy.live()) return true;
        const response = await this.#value(client.get(request.url(), {
          headers: { accept: '*/*', cookie: '', referer: '' }, maxRedirects: 0, maxRetries: 0,
          timeout: Math.max(1, Math.min(this.#parent.normalWindowRemaining(), this.#caseDeadline - performance.now())),
        }).then(created => {
          if (this.#closed) void this.#disposeResponse(created);
          return created;
        }));
        try {
          if (this.#closed || closing() || !this.#original(frame, main, bound)) return true;
          if (response.status() !== 200) { this.#policy.poison('response'); return true; }
          const body = await this.#value(response.body());
          if (!this.#policy.verifyLoader(body)) return true;
          if (this.#closed || closing() || !this.#original(frame, main, bound)) return true;
          const headers = Object.fromEntries(Object.entries(response.headers())
            .filter(([name]) => !['set-cookie', 'set-cookie2'].includes(name.toLowerCase())));
          await this.#value(route.fulfill({ response, headers, body }));
          delivered = true;
        } finally {
          if (!await this.#bounded(this.#disposeResponse(response))) this.#cleanupFailed = true;
        }
      } catch { this.#loaderFailed(this.#loaderPhase, 'operation_failed'); this.#policy.poison('response'); }
      finally {
        if (!delivered && !await this.#bounded(Promise.resolve().then(() => route.abort('blockedbyclient')))) {
          this.#loaderFailed('loader_abort', 'operation_failed');
          this.#cleanupFailed = true; this.#policy.poison('shutdown');
        }
      }
      return true;
    }
    if (!['POST', 'OPTIONS'].includes(request.method())) return false;
    if (!this.#policy.telemetryMatches(document, role)) return false;
    // Collect only new known first-party risk cookies after the verified loader. Never pass an arbitrary jar.
    if (this.#beforeCookies !== null
        && !this.#policy.bindCookies(this.#beforeCookies, await this.#value(context.cookies()))) {
      this.#policy.poison('cookie_unbound'); return false;
    }
    const headers = this.#policy.cookieProjection(await this.#value(request.allHeaders()));
    if (headers === null) return false;
    const claim = this.#policy.claim(request.url(), request.method(), request.resourceType(),
      headers, request.postDataBuffer() ?? Buffer.alloc(0), document, role);
    if (claim === null) return false;
    let delivered = false;
    let queued = false;
    const operation = { phase: 'queued_guard' };
    let activeOperation = operation;
    try {
      // Exactly the admitted visitor plus eight finite events may wait; sends remain serial.
      if (this.#waiting >= 9) { this.#telemetryFailed(operation); this.#policy.poison('budget'); return true; }
      this.#waiting++;
      queued = true;
      const task = this.#chain.then(async () => {
        if (this.#telemetryReject(operation, this.#closed, 'observer_closed')
            || this.#telemetryReject(operation, closing(), 'caller_closing')
            || this.#telemetryReject(operation, admissionClosed(), 'admission_closed')
            || this.#telemetryReject(operation, !this.#original(frame, main, bound), 'original_source_unavailable')
            || this.#telemetryReject(operation, !this.#policy.live(), 'policy_unavailable')) {
          this.#policy.poison('document_changed'); throw new Error('Risk scope is unavailable.');
        }
        const deadline = Math.min(this.#caseDeadline, this.#deadline, performance.now() + this.#parent.normalWindowRemaining());
        const forward = async (selected, knownRole = operation.role) => {
          const part = { phase: 'prepare_ack', ...(knownRole === undefined ? {} : { role: knownRole }) };
          activeOperation = part;
          return this.#journal.run(selected, async () => {
            part.phase = 'invoke_guard';
            if (this.#telemetryReject(part, this.#closed, 'observer_closed')
                || this.#telemetryReject(part, closing(), 'caller_closing')
                || this.#telemetryReject(part, admissionClosed(), 'admission_closed')
                || this.#telemetryReject(part, !this.#original(frame, main, bound), 'original_source_unavailable')
                || this.#telemetryReject(part, !this.#policy.live(), 'policy_unavailable')) {
              throw new Error('Risk scope is unavailable.');
            }
            this.#policy.invoke(this.#telemetryRole(part, selected.role));
            part.phase = 'relay';
            const response = await this.#relay.send(selected.url, selected.role === 'preflight' ? 'OPTIONS' : 'POST',
              selected.role, selected.headers, selected.body, deadline, this.#instrumentSecurity
                ? () => !this.#closed && !closing() && !admissionClosed() && this.#original(frame, main, bound) : null);
            this.#policy.status(selected.role, response.status);
            return response;
          }, response => {
            part.phase = 'response_validation';
            try {
              const report = this.#instrumentSecurity && part.role === 'preflight' ? gate => {
                part.validationGate ??= gate;
              } : null;
              const validate = report === null ? () => this.#policy.validateResponse(selected, response)
                : () => this.#policy.validateResponse(selected, response, report);
              const matched = validate();
              this.#validated(part, matched === true ? 'matched' : 'withheld');
              part.phase = 'result_ack';
              return matched;
            } catch (error) {
              this.#validated(part, 'exception');
              throw error; // The original journal receives the same exception; no property is inspected.
            }
          }, deadline);
        };
        // Browser route interception omits automatic CORS OPTIONS: perform the exact grant check before the body.
        if (this.#telemetryRole(operation, claim.role) === 'visitor') {
          const preflight = this.#policy.preflightFor(claim);
          if (preflight !== null) await forward(preflight, 'preflight');
        }
        const response = await forward(claim);
        activeOperation = operation;
        operation.phase = 'before_delivery';
        if (this.#telemetryReject(operation, this.#closed, 'observer_closed')
            || this.#telemetryReject(operation, closing(), 'caller_closing')
            || this.#telemetryReject(operation, !this.#original(frame, main, bound), 'original_source_unavailable')
            || this.#telemetryReject(operation, !this.#policy.live(), 'policy_unavailable')) {
          throw new Error('Risk scope is unavailable.');
        }
        operation.phase = 'delivery';
        await this.#value(route.fulfill({ status: response.status,
          headers: this.#policy.responseHeaders(response), body: response.body }));
        delivered = true;
        this.#policy.delivered(claim.role);
      });
      this.#chain = task.catch(() => {});
      await this.#value(task);
    } catch { this.#telemetryFailed(activeOperation); this.#policy.poison('journal'); }
    finally {
      if (queued) this.#waiting--;
      if (!delivered && !await this.#bounded(Promise.resolve().then(() => route.abort('blockedbyclient')))) {
        this.#telemetryFailed(activeOperation, 'operation_failed', 'abort');
        this.#cleanupFailed = true; this.#policy.poison('shutdown');
      }
    }
    return true;
  }

  async drainSent() {
    if (!this.#instrumentSecurity || this.#handlers.size === 0) return true;
    // Admissions are already closed by the caller. Only retained, already-invoked handlers may finish.
    return this.#bounded(Promise.all([...this.#handlers]));
  }

  async close() {
    this.#closed = true;
    // New-profile teardown can reap completed work inside the case reserve; it grants no send/release.
    const bounded = !this.#instrumentSecurity ? value => this.#bounded(value) : async value => {
      let timer;
      try { return await Promise.race([Promise.resolve(value).then(result => result !== false, () => false),
        new Promise(resolve => { timer = setTimeout(() => resolve(false),
          Math.max(0, Math.min(1000, this.#caseDeadline - performance.now()))); })]); }
      finally { clearTimeout(timer); }
    };
    let clean = this.#relay === undefined || this.#relay === null || this.#shutdownResult('relay_close', await bounded(this.#relay.close()));
    clean = this.#shutdownResult('chain_drain', await bounded(this.#chain)) && clean;
    clean = this.#shutdownResult('handler_drain', await bounded(Promise.all([...this.#handlers]))) && clean;
    clean = this.#shutdownResult('response_disposal', await bounded(Promise.all([...this.#responseDisposals.values()]).then(values => values.every(Boolean)))) && clean;
    if (this.#publicClient) {
      clean = this.#shutdownResult('public_context', await bounded(this.#publicClient.then(client => this.#disposePublic(client)))) && clean;
    }
    if (this.#journal) clean = this.#shutdownResult('journal_close', await bounded(this.#journal.close())) && clean;
    if (!clean || this.#priorCleanup(this.#cleanupFailed)) this.#policy.poison('shutdown');
    return clean && !this.#cleanupFailed;
  }

  toJSON() { throw new Error('Private risk observer cannot be serialized.'); }
}

module.exports = { HostedRiskObserver };
