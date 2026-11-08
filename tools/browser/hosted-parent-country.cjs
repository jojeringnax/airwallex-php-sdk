'use strict';

// One source-defined public parent read. This adds no iframe, country value or UI action role.
class ParentCountryObserver {
  #policy;
  #enabled;
  #playwright;
  #proxy;
  #task;
  #client;
  #clientDisposal;
  #responses = new Map();
  #deadline = 0;
  #caseDeadline;
  #closed = false;
  #failed = false;
  #sends = 0;
  #delivered = 0;
  #outcome = 'not_observed';
  #status;
  #phase;
  #timeout;
  constructor(policy, enabled, playwright, caseDeadline = Infinity) {
    if (typeof enabled !== 'boolean' || typeof caseDeadline !== 'number' || Number.isNaN(caseDeadline)) throw new Error('Invalid parent country configuration.');
    this.#policy = policy; this.#enabled = enabled; this.#playwright = playwright; this.#caseDeadline = caseDeadline;
  }
  start(proxy) { this.#proxy = proxy; }
  #remaining() { return Math.max(0, Math.min(this.#deadline, this.#caseDeadline) - performance.now()); }
  #disposeClient(client) {
    this.#clientDisposal ??= Promise.resolve().then(() => client.dispose()).then(() => true, () => false);
    return this.#clientDisposal;
  }
  #disposeResponse(response) {
    if (!this.#responses.has(response)) this.#responses.set(response,
      Promise.resolve().then(() => response.dispose()).then(() => true, () => false));
    return this.#responses.get(response);
  }
  async #bounded(promise) {
    let timer;
    try { return await Promise.race([Promise.resolve(promise).then(value => value !== false, () => false),
      new Promise(resolve => { timer = setTimeout(() => resolve(false), this.#remaining()); })]); }
    finally { clearTimeout(timer); }
  }
  async #step(phase, action) {
    if (this.#remaining() <= 0) {
      this.#phase ??= phase; this.#failed = true; this.#policy.failParentCountry();
      throw new Error('Parent country read exceeded its original bound.');
    }
    let value;
    if (!await this.#bounded(Promise.resolve().then(action).then(result => { value = result; return true; }))) {
      this.#phase ??= phase; this.#failed = true; this.#policy.failParentCountry();
      throw new Error('Parent country read did not complete safely.');
    }
    return value;
  }
  handle(route, main, closing, admissionsClosed) {
    if (!this.#enabled || this.#closed || this.#task) return Promise.resolve(false);
    const request = route.request();
    const original = () => {
      try { const frame = request.frame();
        return frame === main && frame.isDetached() === false && this.#policy.normalSourceMatches(frame.url(), true);
      } catch { return false; }
    };
    const outgoing = this.#policy.claimParentCountry(request.url(), request.method(), request.resourceType(),
      request.headers(), request.postData(), original());
    if (outgoing === null) return Promise.resolve(false);
    this.#deadline = performance.now() + this.#policy.parentCountryWindowRemaining();
    this.#task = this.#read(route, request, outgoing, original, closing, admissionsClosed);
    return this.#task.then(() => true);
  }
  async #read(route, request, headers, original, closing, admissionsClosed) {
    let delivered = false;
    const eligible = () => !this.#closed && !closing() && original() && this.#policy.parentCountrySendAllowed();
    try {
      if (!eligible() || admissionsClosed()) throw new Error('Parent country scope ended.');
      this.#client = Promise.resolve().then(() => {
        if (!eligible() || admissionsClosed()) throw new Error('Parent country scope ended.');
        return this.#playwright.request.newContext({
          ignoreHTTPSErrors: false, proxy: { server: this.#proxy, bypass: '' },
        });
      }).then(client => { if (this.#closed || this.#remaining() <= 0) void this.#disposeClient(client); return client; });
      const client = await this.#step('context', () => this.#client);
      if (!eligible() || admissionsClosed()) throw new Error('Parent country scope ended.');
      const response = await this.#step('get', () => {
        if (!eligible() || admissionsClosed()) throw new Error('Parent country scope ended.');
        this.#timeout = Math.max(1, Math.floor(Math.min(this.#remaining(), this.#policy.parentCountryWindowRemaining())));
        this.#sends++;
        return client.get(request.url(), {
          headers, maxRedirects: 0, maxRetries: 0, timeout: this.#timeout,
        }).then(created => { if (this.#closed || this.#remaining() <= 0) void this.#disposeResponse(created); return created; });
      });
      try {
        this.#status = await this.#step('status', () => {
          const status = response.status();
          if (!Number.isInteger(status) || status < 100 || status > 599) throw new Error('Invalid parent country status.');
          return status;
        });
        this.#outcome = this.#status >= 300 && this.#status < 400 ? 'redirect_rejected' : 'http_rejected';
        if (this.#status === 200) {
          const fields = await this.#step('headers', async () => {
            const fields = await response.headersArray();
            if (!Array.isArray(fields) || fields.some(field => !field || typeof field.name !== 'string'
                || typeof field.value !== 'string')) throw new Error('Invalid parent country headers.');
            return fields;
          });
          const content = fields.filter(field => field.name.toLowerCase() === 'content-type');
          const length = fields.filter(field => field.name.toLowerCase() === 'content-length');
          if (fields.reduce((sum, field) => sum + Buffer.byteLength(field.name + field.value), 0) > 16384
              || content.length !== 1 || !/^application\/json(?:\s*;[^\r\n]*)?$/iu.test(content[0].value)
              || length.length > 1 || (length.length === 1
                && (!/^[0-9]+$/u.test(length[0].value) || Number(length[0].value) > 65536))) {
            this.#outcome = 'invalid_response';
          } else {
            const body = await this.#step('body', () => response.body());
            this.#outcome = this.#policy.observeParentCountry(body) ? 'matched' : 'invalid_response';
            if (this.#outcome === 'matched' && eligible()) {
              const safeHeaders = Object.fromEntries(fields.filter(field => !['set-cookie', 'set-cookie2']
                .includes(field.name.toLowerCase())).map(field => [field.name, field.value]));
              await this.#step('fulfill', () => {
                if (!eligible()) throw new Error('Parent country delivery scope ended.');
                return route.fulfill({ response, headers: safeHeaders, body });
              });
              this.#delivered++; delivered = true;
            } else if (this.#outcome === 'matched') this.#outcome = 'unavailable';
          }
        }
      } finally { if (!await this.#bounded(this.#disposeResponse(response))) { this.#failed = true; this.#phase ??= 'dispose'; } }
    } catch {
      this.#phase ??= 'scope'; this.#failed = true;
      if (!delivered) this.#outcome = 'unavailable';
    } finally {
      if (!delivered) {
        this.#policy.failParentCountry();
        if (!await this.#bounded(Promise.resolve().then(() => route.abort('blockedbyclient')))) { this.#failed = true; this.#phase ??= 'abort'; }
      }
      if (this.#client && !await this.#bounded(this.#client.then(client => this.#disposeClient(client)))) { this.#failed = true; this.#phase ??= 'context_dispose'; }
    }
  }
  async drainSent() {
    if (this.#sends > 0 && this.#task && !await this.#bounded(this.#task)) this.#failed = true;
  }
  async close() {
    if (!this.#enabled) return true;
    this.#closed = true; this.#policy.closeParentCountryWindow();
    if (this.#client && !await this.#bounded(this.#client.then(client => this.#disposeClient(client)))) { this.#failed = true; this.#phase ??= 'context_dispose'; }
    if (this.#task && !await this.#bounded(this.#task)) this.#failed = true;
    if (!await this.#bounded(Promise.all([...this.#responses.values()]).then(values => values.every(Boolean)))) this.#failed = true;
    return !this.#failed;
  }
  failed() { return this.#failed; }
  evidence() {
    return this.#enabled ? { ...this.#policy.parentCountryEvidence(), parent_country_sends: this.#sends,
      parent_country_delivered: this.#delivered, parent_country_outcome: this.#outcome,
      ...(this.#status === undefined ? {} : { parent_country_http_status: this.#status }),
      ...(this.#timeout === undefined ? {} : { parent_country_request_timeout_ms: this.#timeout }),
      ...(this.#phase === undefined ? {} : { parent_country_failure_phase: this.#phase }) } : {};
  }
  toJSON() { throw new Error('Private parent country observer cannot be serialized.'); }
}
module.exports = { ParentCountryObserver };
