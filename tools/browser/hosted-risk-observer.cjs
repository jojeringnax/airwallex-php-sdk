'use strict';

const { HostedRiskPolicy, riskCookieNames } = require('./hosted-risk-policy.cjs');
const { HostedRiskRelay } = require('./hosted-risk-relay.cjs');

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
  constructor(parent, journal, playwright, fixture = null, caseDeadline = performance.now() + 40000) {
    if (!Number.isFinite(caseDeadline)) throw new Error('Risk case deadline is invalid.');
    this.#caseDeadline = caseDeadline;
    this.#parent = parent;
    this.#journal = journal;
    this.#playwright = playwright;
    this.#fixture = fixture;
    this.#policy = new HostedRiskPolicy(parent, journal !== null, fixture);
  }

  start(proxy) {
    this.#proxy = proxy;
    this.#relay = this.#journal === null ? null : new HostedRiskRelay(proxy,
      this.#fixture === null ? null : { origin: this.#fixture.bwsOrigin, ca: this.#fixture.ca });
  }

  authority(host, port) {
    return !this.#closed && performance.now() < this.#caseDeadline && this.#policy.authority(host, port);
  }
  noteDenied(value) { this.#policy.noteDenied(value); }
  evidence() { return { ...this.#policy.evidence(), ...(this.#journal?.evidence() ?? {}) }; }

  #rememberDeadline() {
    const remaining = Math.min(this.#parent.normalWindowRemaining(), this.#caseDeadline - performance.now());
    if (!Number.isFinite(remaining) || remaining <= 0) throw new Error('Risk scope is unavailable.');
    this.#deadline ??= performance.now() + Math.min(8000, remaining);
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

  #original(frame, main, bound) {
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
        if (!claim || this.#closed || closing() || admissionClosed() || !this.#original(frame, main, bound)) return true;
        this.#beforeCookies = await this.#value(context.cookies());
        if (this.#beforeCookies.some(cookie => riskCookieNames.has(cookie.name))) {
          this.#policy.note('cookie_unbound'); return true;
        }
        if (this.#closed || closing() || admissionClosed() || !this.#original(frame, main, bound)) return true;
        // Retain construction before await; shutdown disposes every created public context.
        this.#rememberDeadline();
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
      } catch { this.#policy.poison('response'); }
      finally {
        if (!delivered && !await this.#bounded(Promise.resolve().then(() => route.abort('blockedbyclient')))) {
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
    try {
      // Exactly the admitted visitor plus eight finite events may wait; sends remain serial.
      if (this.#waiting >= 9) { this.#policy.poison('budget'); return true; }
      this.#waiting++;
      queued = true;
      const task = this.#chain.then(async () => {
        if (this.#closed || closing() || admissionClosed() || !this.#original(frame, main, bound) || !this.#policy.live()) {
          this.#policy.poison('document_changed'); throw new Error('Risk scope is unavailable.');
        }
        const deadline = Math.min(this.#caseDeadline, this.#deadline, performance.now() + this.#parent.normalWindowRemaining());
        const forward = async selected => this.#journal.run(selected, async () => {
          if (this.#closed || closing() || admissionClosed() || !this.#original(frame, main, bound) || !this.#policy.live()) {
            throw new Error('Risk scope is unavailable.');
          }
          this.#policy.invoke(selected.role);
          const response = await this.#relay.send(selected.url, selected.role === 'preflight' ? 'OPTIONS' : 'POST',
            selected.role, selected.headers, selected.body, deadline);
          this.#policy.status(selected.role, response.status);
          return response;
        }, response => this.#policy.validateResponse(selected, response), deadline);
        // Browser route interception omits automatic CORS OPTIONS: perform the exact grant check before the body.
        if (claim.role === 'visitor') {
          const preflight = this.#policy.preflightFor(claim);
          if (preflight !== null) await forward(preflight);
        }
        const response = await forward(claim);
        if (this.#closed || closing() || !this.#original(frame, main, bound) || !this.#policy.live()) {
          throw new Error('Risk scope is unavailable.');
        }
        await this.#value(route.fulfill({ status: response.status,
          headers: this.#policy.responseHeaders(response), body: response.body }));
        delivered = true;
        this.#policy.delivered(claim.role);
      });
      this.#chain = task.catch(() => {});
      await this.#value(task);
    } catch { this.#policy.poison('journal'); }
    finally {
      if (queued) this.#waiting--;
      if (!delivered && !await this.#bounded(Promise.resolve().then(() => route.abort('blockedbyclient')))) {
        this.#cleanupFailed = true; this.#policy.poison('shutdown');
      }
    }
    return true;
  }

  async close() {
    this.#closed = true;
    let clean = this.#relay === undefined || this.#relay === null || await this.#bounded(this.#relay.close());
    clean = await this.#bounded(this.#chain) && clean;
    clean = await this.#bounded(Promise.all([...this.#handlers])) && clean;
    clean = await this.#bounded(Promise.all([...this.#responseDisposals.values()]).then(values => values.every(Boolean))) && clean;
    if (this.#publicClient) {
      clean = await this.#bounded(this.#publicClient.then(client => this.#disposePublic(client))) && clean;
    }
    if (this.#journal) clean = await this.#bounded(this.#journal.close()) && clean;
    if (!clean || this.#cleanupFailed) this.#policy.poison('shutdown');
    return clean && !this.#cleanupFailed;
  }

  toJSON() { throw new Error('Private risk observer cannot be serialized.'); }
}

module.exports = { HostedRiskObserver };
