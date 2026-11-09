'use strict';

const { createHash } = require('node:crypto');

// Anonymous public bytes inspected 2026-11-08; a changed deployment is withheld, never repinned automatically.
const fingerprintAsset = Object.freeze({
  origin: 'https://static.sandbox.airwallex.com',
  path: '/webapp/fraud/device-fingerprint/index.js',
  sha256: '8198d811842296bb2ab7e339c0f5f4309c39df77d20837e459c09e350c990f8b',
  expires: Date.parse('2026-11-15T00:00:00Z'),
});
const digest = value => createHash('sha256').update(value).digest('hex');
const reasons = new Set(['not_observed', 'scope_unavailable', 'session_mismatch', 'loader_replay',
  'loader_integrity', 'cookie_unbound', 'unknown_dependency', 'budget', 'journal', 'response',
  'window', 'document_changed', 'shutdown', 'cors', 'headers', 'body']);
const dependencyNames = ['risk_document', 'captcha', 'forter', 'sardine', 'monitoring', 'other'];
const riskCookieNames = new Set(['AWX_RISK_ID', 'AWX_RISK_SID', '__AWX_TEMP_F_D__']);

function json(body) {
  if (!Buffer.isBuffer(body) || body.length > 65536) throw new Error('Risk JSON is outside its bound.');
  const raw = new TextDecoder('utf-8', { fatal: true }).decode(body);
  const data = JSON.parse(raw);
  const bounded = (node, depth) => depth <= 32 && (node === null || typeof node !== 'object'
    || Object.values(node).every(value => bounded(value, depth + 1)));
  if (!bounded(data, 0)) throw new Error('Risk JSON is outside its depth bound.');
  const scopes = [];
  for (let index = 0; index < raw.length; index++) {
    const char = raw[index];
    if (char === '{' || char === '[') scopes.push(char === '{' ? new Set() : null);
    else if (char === '}' || char === ']') scopes.pop();
    else if (char === '"') {
      const expression = /"(?:[^"\\]|\\.)*"/gy;
      expression.lastIndex = index;
      const match = expression.exec(raw);
      if (match === null) throw new Error('Risk JSON is invalid.');
      index = expression.lastIndex - 1;
      if (raw.slice(index + 1).trimStart()[0] === ':') {
        const key = JSON.parse(match[0]), scope = scopes.at(-1);
        if (!(scope instanceof Set) || scope.has(key)) throw new Error('Risk JSON has duplicate fields.');
        scope.add(key);
      }
    }
  }
  return data;
}

class HostedRiskPolicy {
  #parent;
  #enabled;
  #fixture;
  #clock;
  #loader = null;
  #verified = false;
  #attempts = { visitor: 0, event: 0, preflight: 0 };
  #invocations = { visitor: 0, event: 0, preflight: 0 };
  #delivered = { visitor: 0, event: 0, preflight: 0 };
  #status = {};
  #eventBytes = 0;
  #wire = new Set();
  #cookies = new Map();
  #poisoned = false;
  #reason = 'not_observed';
  #grant = false;
  #dependencies = Object.fromEntries(dependencyNames.map(name => [name, 0]));
  constructor(parent, enabled = false, fixture = null, clock = Date.now) {
    if (typeof enabled !== 'boolean' || typeof clock !== 'function') throw new Error('Invalid risk observation configuration.');
    if (fixture !== null) {
      const source = new URL(fixture.origin), bws = new URL(fixture.bwsOrigin);
      if (source.hostname !== '127.0.0.1' || bws.hostname !== '127.0.0.1'
          || source.protocol !== 'http:' || bws.protocol !== 'https:'
          || !/^[a-f0-9]{64}$/u.test(fixture.loaderHash ?? '')) throw new Error('Invalid local risk fixture.');
    }
    this.#parent = parent;
    this.#enabled = enabled;
    this.#fixture = fixture;
    this.#clock = clock;
  }

  live(report = null) {
    if (report !== null) {
      const gate = (value, reason) => { if (!value) report(reason); return value; };
      return gate(this.#enabled, 'disabled') && gate(!this.#poisoned, 'policy_poisoned')
        && gate(this.#parent.renderEnabled(), 'render_unavailable')
        && gate(this.#parent.bindingEvidence().payer_payload_delivered, 'payer_not_delivered')
        && gate(this.#parent.normalEvidence().normal_reads_requested === true, 'normal_not_selected')
        && gate(this.#parent.normalWindowRemaining() > 0, 'scope_window_not_positive');
    }
    return this.#enabled && !this.#poisoned && this.#parent.renderEnabled()
      && this.#parent.bindingEvidence().payer_payload_delivered
      && this.#parent.normalEvidence().normal_reads_requested === true
      && this.#parent.normalWindowRemaining() > 0;
  }

  note(reason) { if (this.#enabled && reasons.has(reason) && this.#reason === 'not_observed') this.#reason = reason; }
  poison(reason) { this.note(reason); this.#poisoned = true; }

  loaderTarget(value) {
    try {
      const url = new URL(value), origin = this.#fixture?.origin ?? fingerprintAsset.origin;
      return url.origin === origin && url.pathname === (this.#fixture === null ? fingerprintAsset.path : '/fingerprint.js');
    } catch { return false; }
  }

  claimLoader(value, method, type, proof) {
    if (!this.#enabled || !this.loaderTarget(value)) return null;
    if (!this.live() || (this.#fixture === null && this.#clock() >= fingerprintAsset.expires)) {
      this.note('scope_unavailable'); return null;
    }
    if (this.#loader !== null) { this.note('loader_replay'); return null; }
    try {
      const url = new URL(value);
      if (method !== 'GET' || type !== 'script' || value !== url.href || url.hash || url.username || url.password
          || !proof || !['main', 'dropin'].includes(proof.role)
          || !this.#parent.normalSourceMatches(proof.document, proof.role === 'main')
          || typeof proof.session !== 'string' || !/^[A-Za-z0-9_-]{1,128}$/u.test(proof.session)
          || proof.id !== 'airwallex-fraud-api' || proof.orderSession !== proof.session
          || proof.disableTracking !== null
          || !this.#parent.riskSessionMatches(proof.session, proof.role === 'main')) throw new Error();
      const expected = ['sessionId=' + proof.session, 'hasScriptId=false', 'disable3rdTracking=false'];
      if (proof.merchant !== null) {
        // This fresh SETUP has no independently established merchant option. Do not invent one.
        throw new Error();
      }
      if (url.search.slice(1).split('&').sort().join('&') !== expected.sort().join('&')) throw new Error();
      if (proof.role === 'main' ? proof.tenant !== 'pa' || proof.project !== 'element-js'
        : proof.tenant !== null || proof.project !== null) throw new Error();
      this.#loader = { document: proof.document, role: proof.role, session: proof.session,
        tenant: proof.tenant, project: proof.project, scope: digest(proof.document + '\n' + proof.session),
        expectedHash: this.#fixture?.loaderHash ?? fingerprintAsset.sha256 };
      return { expectedHash: this.#loader.expectedHash, scope: this.#loader.scope };
    } catch { this.note('session_mismatch'); return null; }
  }

  loaderMatches(document, role) {
    return this.live() && this.#loader !== null && this.#loader.document === document && this.#loader.role === role;
  }

  telemetryMatches(document, role) { return this.#verified && this.loaderMatches(document, role); }

  verifyLoader(body, report = null) {
    if (report !== null) {
      const reject = (value, reason) => { if (value) report(reason); return value; };
      if (reject(!this.#loader, 'claim_missing') || !this.live(report)
          || reject(!Buffer.isBuffer(body), 'body_not_buffer') || reject(body.length > 262144, 'body_limit')
          || reject(digest(body) !== this.#loader.expectedHash, 'body_hash')) {
        this.poison('loader_integrity'); return false;
      }
      this.#verified = true;
      return true;
    }
    if (!this.#loader || !this.live() || !Buffer.isBuffer(body) || body.length > 262144
        || digest(body) !== this.#loader.expectedHash) { this.poison('loader_integrity'); return false; }
    this.#verified = true;
    return true;
  }

  // Only values absent before the verified loader and observed afterward may be bound.
  bindCookies(before, after) {
    if (!this.#verified || !Array.isArray(before) || !Array.isArray(after)) return false;
    if (before.some(cookie => riskCookieNames.has(cookie.name))) { this.note('cookie_unbound'); return false; }
    const values = new Map();
    for (const cookie of after) {
      if (!riskCookieNames.has(cookie.name)) continue;
      const host = new URL(this.#loader.document).hostname;
      if (typeof cookie.value !== 'string' || !/^[A-Za-z0-9._~-]{1,256}$/u.test(cookie.value)
          || ![host, '.' + host, '.airwallex.com'].includes(cookie.domain) || cookie.path !== '/'
          || cookie.httpOnly !== false
          || (values.has(cookie.name) && values.get(cookie.name) !== cookie.value)
          || (cookie.name === 'AWX_RISK_SID' && cookie.value !== this.#loader.session)) {
        this.note('cookie_unbound'); return false;
      }
      values.set(cookie.name, cookie.value);
    }
    this.#cookies = values;
    return true;
  }

  #headers(headers, role) {
    if (!headers || typeof headers !== 'object') return null;
    const origin = new URL(this.#loader.document).origin;
    const ambient = new Set(['accept', 'accept-language', 'accept-encoding', 'host', 'connection', 'priority',
      'user-agent', 'origin', 'referer', 'cookie', 'sec-ch-ua', 'sec-ch-ua-mobile', 'sec-ch-ua-platform',
      'sec-fetch-dest', 'sec-fetch-mode', 'sec-fetch-site', 'content-type', 'content-length',
      'access-control-request-method', 'access-control-request-headers']);
    if (Object.keys(headers).some(name => !ambient.has(name)) || headers.origin !== origin
        || (headers.referer && headers.referer !== origin + '/')) { this.note('headers'); return null; }
    const result = { accept: '*/*', origin, ...(headers.referer ? { referer: headers.referer } : {}) };
    if (headers['user-agent']) {
      if (typeof headers['user-agent'] !== 'string' || headers['user-agent'].length > 1024
          || /[^\x20-\x7e]/u.test(headers['user-agent'])) return null;
      result['user-agent'] = headers['user-agent'];
    }
    if (role === 'preflight') {
      if (headers['access-control-request-method'] !== 'POST'
          || headers['access-control-request-headers'] !== 'content-type' || headers.cookie || headers['content-type']) return null;
      return { ...result, 'access-control-request-method': 'POST', 'access-control-request-headers': 'content-type' };
    }
    if (role === 'visitor') {
      if (headers['content-type'] !== 'application/json' || headers.cookie) return null;
      return { ...result, 'content-type': 'application/json' };
    }
    if (!/^text\/plain;\s*charset=UTF-8$/iu.test(headers['content-type'] ?? '')) return null;
    if (headers.cookie) {
      const names = new Set();
      for (const pair of headers.cookie.split(';')) {
        const match = /^\s*([A-Za-z0-9_]+)=([A-Za-z0-9._~-]{1,256})\s*$/u.exec(pair);
        if (!match || names.has(match[1]) || this.#cookies.get(match[1]) !== match[2]) {
          this.note('cookie_unbound'); return null;
        }
        names.add(match[1]);
      }
      result.cookie = headers.cookie;
    }
    return { ...result, 'content-type': headers['content-type'] };
  }

  cookieProjection(headers) {
    // Browser ambient cookies never become upstream authority; only freshly bound risk names survive.
    if (!headers.cookie) return headers;
    const selected = [];
    for (const pair of headers.cookie.split(';')) {
      const match = /^\s*([A-Za-z0-9_]+)=([^;]*)\s*$/u.exec(pair);
      if (!match) return null;
      if (riskCookieNames.has(match[1])) selected.push(pair.trim());
    }
    const result = { ...headers };
    if (selected.length) result.cookie = selected.join('; ');
    else delete result.cookie;
    return result;
  }

  claim(value, method, type, headers, body, document, role) {
    if (!this.#verified || !this.loaderMatches(document, role)) { this.note('scope_unavailable'); return null; }
    try {
      const url = new URL(value), origin = this.#fixture?.bwsOrigin ?? 'https://bws.sandbox.airwallex.com';
      if (url.origin !== origin || value !== url.href || url.search || url.hash) return null;
      let kind;
      if (url.pathname === '/bws/v3/visit' && method === 'OPTIONS' && ['other', 'fetch', 'xhr'].includes(type)) kind = 'preflight';
      else if (url.pathname === '/bws/v3/visit' && method === 'POST' && ['fetch', 'xhr'].includes(type)) kind = 'visitor';
      else if (url.pathname === '/bws/v1/' + this.#loader.session && method === 'POST'
          && ['ping', 'fetch'].includes(type)) kind = 'event';
      if (!kind || !Buffer.isBuffer(body) || body.length > 65536
          || this.#attempts[kind] >= (kind === 'event' ? 8 : 1)
          || (kind === 'event' && this.#eventBytes + body.length > 262144)) { this.note('budget'); return null; }
      const outgoing = this.#headers(headers, kind);
      if (outgoing === null) return null;
      if (kind === 'preflight' ? body.length !== 0 : body.length === 0) return null;
      if (kind !== 'preflight' && !this.#body(kind, body)) { this.note('body'); return null; }
      const wire = digest(value) + ':' + digest(body);
      if (this.#wire.has(wire)) { this.note('budget'); return null; }
      const ordinal = this.#attempts[kind]++;
      this.#wire.add(wire);
      if (kind === 'event') this.#eventBytes += body.length;
      return { role: kind, ordinal, url: value, headers: outgoing, body,
        scope: this.#loader.scope, sessionHash: digest(this.#loader.session) };
    } catch { this.note('body'); return null; }
  }

  #body(role, body) {
    const data = json(body);
    if (role === 'visitor') {
      return data && typeof data === 'object' && !Array.isArray(data)
        && Object.keys(data).every(key => ['deviceInfo', 'requestTime', 'visitorToken'].includes(key))
        && data.deviceInfo && typeof data.deviceInfo === 'object' && !Array.isArray(data.deviceInfo)
        && Number.isSafeInteger(data.requestTime) && data.requestTime > 0
        && (!Object.hasOwn(data, 'visitorToken') || (typeof data.visitorToken === 'string' && data.visitorToken.length <= 8192));
    }
    const keys = new Set(['eventId', 'deviceId', 'tempDeviceId', 'visitorId', 'sardineFingerprint', 'sessionId',
      'window', 'event', 'tenant', 'projectName', 'forterToken', 'fromMainWindow']);
    return Array.isArray(data) && data.length > 0 && data.length <= 20 && data.every(item =>
      item && typeof item === 'object' && !Array.isArray(item) && Object.keys(item).every(key => keys.has(key))
      && item.sessionId === this.#loader.session && item.fromMainWindow === true
      && typeof item.eventId === 'string' && /^[a-f0-9]{40}$/u.test(item.eventId)
      && (item.tenant ?? null) === this.#loader.tenant && (item.projectName ?? null) === this.#loader.project
      && item.event && typeof item.event === 'object' && !Array.isArray(item.event)
      && item.window && typeof item.window === 'object' && item.window.document
      && item.window.document.url === new URL(this.#loader.document).origin + new URL(this.#loader.document).pathname);
  }

  preflightFor(visitor) {
    if (visitor.role !== 'visitor' || this.#grant || !this.#loader) return null;
    const headers = { ...visitor.headers, 'access-control-request-method': 'POST',
      'access-control-request-headers': 'content-type' };
    delete headers['content-type'];
    return this.claim(visitor.url, 'OPTIONS', 'other', headers, Buffer.alloc(0),
      this.#loader.document, this.#loader.role);
  }

  #responseFields(response) {
    if (!response || !Array.isArray(response.fields) || !Buffer.isBuffer(response.body)
        || response.body.length > 65536 || !Number.isInteger(response.status)) return null;
    const headers = new Map();
    for (const item of response.fields) {
      const name = item.name.toLowerCase();
      if (headers.has(name) && ['content-type', 'access-control-allow-origin',
        'access-control-allow-methods', 'access-control-allow-headers', 'access-control-allow-credentials'].includes(name)) return null;
      headers.set(name, item.value);
    }
    return headers;
  }

  validateResponse(claim, response) {
    const headers = this.#responseFields(response);
    if (!headers || response.status < 200 || response.status > 299) return false;
    const origin = claim.headers.origin;
    const allowedOrigin = headers.get('access-control-allow-origin');
    if (claim.role === 'preflight') {
      const methods = (headers.get('access-control-allow-methods') ?? '').split(',').map(value => value.trim());
      const names = (headers.get('access-control-allow-headers') ?? '').toLowerCase().split(',').map(value => value.trim());
      this.#grant = (allowedOrigin === origin || allowedOrigin === '*') && methods.includes('POST')
        && names.includes('content-type');
      return this.#grant;
    }
    if (claim.role === 'visitor') {
      if (!this.#grant || response.status !== 200
        || !/^application\/json(?:;\s*charset=utf-8)?$/iu.test(headers.get('content-type') ?? '')
        || (allowedOrigin !== origin && allowedOrigin !== '*')) return false;
      try {
        const value = json(response.body);
        return value && typeof value === 'object' && !Array.isArray(value)
          && value.data && typeof value.data === 'object' && !Array.isArray(value.data)
          && typeof value.data.visitorID === 'string' && value.data.visitorID.length > 0
          && value.data.visitorID.length <= 1024
          && (!Object.hasOwn(value.data, 'visitorToken') || (typeof value.data.visitorToken === 'string'
            && value.data.visitorToken.length <= 8192));
      } catch { return false; }
    }
    // Beacon is source-defined no-cors telemetry. A 2xx is not evidence that tracking succeeded.
    return claim.role === 'event';
  }

  responseHeaders(response) {
    return Object.fromEntries(response.fields.filter(item => !['set-cookie', 'set-cookie2',
      'transfer-encoding', 'content-length', 'connection', 'content-encoding'].includes(item.name.toLowerCase()))
      .map(item => [item.name, item.value]));
  }

  invoke(role) { if (Object.hasOwn(this.#invocations, role)) this.#invocations[role]++; }
  delivered(role) { if (Object.hasOwn(this.#delivered, role)) this.#delivered[role]++; }
  status(role, value) { if (Object.hasOwn(this.#invocations, role) && Number.isInteger(value) && value >= 100 && value <= 599) this.#status[role] = value; }

  authority(host, port) {
    if (!this.live()) return false;
    if (this.#fixture !== null) {
      const url = new URL(this.#fixture.bwsOrigin);
      return host === url.hostname && String(port) === url.port && this.#verified;
    }
    return String(port) === '443' && (host === 'static.sandbox.airwallex.com'
      || (host === 'bws.sandbox.airwallex.com' && this.#verified));
  }

  noteDenied(value) {
    if (!this.#enabled) return;
    try {
      const url = new URL(value);
      let name = 'other';
      if (url.hostname === 'static.sandbox.airwallex.com' && /risk-iframe\.html$/u.test(url.pathname)) name = 'risk_document';
      else if (/recaptcha|captcha/u.test(url.hostname + url.pathname)) name = 'captcha';
      else if (/forter|cloudfront|siteperformancetest/u.test(url.hostname) || url.hostname === '1.1.1.1') name = 'forter';
      else if (/sardine/u.test(url.hostname + url.pathname)) name = 'sardine';
      else if (url.hostname === 'm1.openfpcdn.io') name = 'monitoring';
      this.#dependencies[name] = Math.min(1000000, this.#dependencies[name] + 1);
      this.note('unknown_dependency');
    } catch { /* No unknown input becomes a label. */ }
  }

  evidence() {
    if (!this.#enabled) return {};
    const result = { risk_bootstrap_requested: true, risk_graph_complete: false, risk_financial_ready: false,
      risk_loader_attempted: this.#loader !== null, risk_loader_verified: this.#verified,
      risk_partial_reason: this.#reason, risk_telemetry_poisoned: this.#poisoned,
      risk_denied_dependencies: { ...this.#dependencies } };
    for (const role of ['visitor', 'event', 'preflight']) {
      result['risk_' + role + '_attempts'] = this.#attempts[role];
      result['risk_' + role + '_client_invocations'] = this.#invocations[role];
      result['risk_' + role + '_delivered'] = this.#delivered[role];
      if (this.#status[role]) result['risk_' + role + '_http_status'] = this.#status[role];
    }
    return result;
  }

  toJSON() { throw new Error('Private risk observation cannot be serialized.'); }
}

module.exports = { HostedRiskPolicy, fingerprintAsset, riskCookieNames, json };
