'use strict';

const { HostedRiskPolicy, riskCookieNames } = require('./hosted-risk-policy.cjs');
const { hash } = require('./instrument-journal.cjs');
const { parseInstrumentJson, object, keys, integer } = require('./instrument-json.cjs');

const googleKey = '6LfdekgtAAAAAE3guYoj6ibnZ5aWVUBW5Z4VZLPa';
const site = 'aac5fb818cb3';
const userPattern = '(?:[0-9a-f]{12}4[0-9a-f]{3}[89ab][0-9a-f]{15}|fgu[0-9]{2}[1-9][0-9]{8})';
const googleHosts = new Set(['www.recaptcha.net', 'www.google.com']);
const standardHeaders = new Set([
  'accept',
  'accept-language',
  'accept-encoding',
  'user-agent',
  'origin',
  'referer',
  'cookie',
  'content-type',
  'content-length',
  'host',
  'connection',
  'priority',
  'cache-control',
  'pragma',
  'sec-ch-ua',
  'sec-ch-ua-mobile',
  'sec-ch-ua-platform',
  'sec-fetch-site',
  'sec-fetch-mode',
  'sec-fetch-dest',
  'sec-fetch-user',
  'upgrade-insecure-requests'
]);

function canonical(value) {
  if (typeof value !== 'string' || value.length > 4096 || /[\x00-\x20\x7f]/u.test(value)) return null;
  try {
    const url = new URL(value);
    if (
      url.href !== value ||
      url.protocol !== 'https:' ||
      url.port ||
      url.username ||
      url.password ||
      url.hash ||
      /%2f|%5c|%00|%0a|%0d/iu.test(url.pathname) ||
      /\/\.{1,2}(?:\/|$)/u.test(url.pathname)
    )
      return null;
    return url;
  } catch {
    return null;
  }
}
function query(url) {
  if (url.search.length > 3073) return false;
  const entries = [...url.searchParams];
  if (entries.length > 32 || new Set(entries.map(([key]) => key)).size !== entries.length) return false;
  try {
    decodeURIComponent(url.search.replace(/\+/gu, ' '));
    return entries.every(
      ([key, value]) =>
        /^[A-Za-z0-9_.-]{1,64}$/u.test(key) && value.length <= 2048 && !/[\x00-\x1f\x7f]/u.test(value)
    );
  } catch {
    return false;
  }
}
function namespace(value) {
  const segments = value.split('/');
  return (
    segments.length >= 1 &&
    segments.length <= 6 &&
    segments.every((segment) => /^[A-Za-z0-9_.-]{1,128}$/u.test(segment) && !/^\.+$/u.test(segment))
  );
}
function outgoing(meta) {
  const result = {},
    origin = new URL(meta.sourceURL).origin;
  let bytes = 0;
  for (const [name, value] of Object.entries(meta.headers)) {
    if (
      !standardHeaders.has(name) ||
      typeof value !== 'string' ||
      /[^\x20-\x7e]/u.test(value) ||
      value.length > 4096 ||
      (bytes += Buffer.byteLength(name + value)) > 16384
    )
      throw new Error('Service headers are unsupported.');
    if (name === 'origin' && value !== origin) throw new Error('Service Origin is unbound.');
    if (name === 'referer') {
      const target = new URL(meta.url),
        source = new URL(meta.sourceURL);
      source.hash = '';
      if (
        value !== origin + '/' &&
        !(
          target.origin === origin &&
          (value === source.href || (meta.workerBound && value === meta.workerURL))
        )
      ) {
        throw new Error('Service Referer is unbound.');
      }
    }
    if (!['host', 'connection', 'content-length', 'accept-encoding'].includes(name)) result[name] = value;
  }
  return result;
}

class InstrumentServices {
  #reads;
  #counts = {};
  #delivered = {};
  #security = 0;
  #risk;
  #beforeCookies = [];
  #loader = false;
  #session;
  #loaderDocument;
  #ray;
  #forter = false;
  #forterGrant = false;
  #tencent = false;
  #user;
  #googleOrigin;
  #release;
  #language;
  constructor(reads, risk = new HostedRiskPolicy(reads.base, true)) {
    this.#reads = reads;
    this.#risk = risk;
  }
  get session() {
    return this.#session;
  }
  get loaderVerified() {
    return this.#loader;
  }
  get googleOrigin() {
    return this.#googleOrigin;
  }
  delivered(claim) {
    this.#delivered[claim.role] = (this.#delivered[claim.role] ?? 0) + 1;
  }
  bootstrapReady() {
    return (
      [
        'security_loader',
        'bws_visitor',
        'risk_document',
        'risk_bundle',
        'cf_passive',
        'forter_entry',
        'google_entry',
        'google_main_release',
        'tencent_entry',
        'tencent_preload'
      ].every((role) => this.#delivered[role] >= 1) && this.#delivered.tencent_static >= 3
    );
  }
  take(role, maximum) {
    const ordinal = this.#counts[role] ?? 0;
    if (ordinal >= maximum || this.#security >= 96) throw new Error('Service budget ended.');
    this.#counts[role] = ordinal + 1;
    this.#security++;
    return ordinal;
  }
  beforeCookies(cookies) {
    if (!Array.isArray(cookies) || cookies.some((cookie) => riskCookieNames.has(cookie.name)))
      throw new Error('Risk storage is not fresh.');
    this.#beforeCookies = cookies;
  }
  bindCookies(cookies) {
    return this.#risk.bindCookies(this.#beforeCookies, cookies);
  }
  #result(meta, role, maximum, limit, fields = {}) {
    if (
      Object.keys(meta.headers).some((name) => /^(?:authorization|client-secret|x-|baggage$)/u.test(name))
    ) {
      throw new Error('Private API headers cannot enter a service family.');
    }
    return {
      role,
      ordinal: this.take(role, maximum),
      method: meta.method,
      url: meta.url,
      body: meta.body,
      headers: fields.headers ?? outgoing(meta),
      responseLimit: limit,
      scope: hash(this.#reads.initial),
      security: true,
      resourceType: meta.type,
      ...fields
    };
  }
  claim(meta) {
    const url = canonical(meta.url);
    if (!url || !query(url) || !Buffer.isBuffer(meta.body) || meta.body.length > 65536) return null;
    const { sourceRole, method, type } = meta,
      get = method === 'GET' && meta.body.length === 0;
    if (this.#risk.loaderTarget(meta.url)) {
      if (!['main', 'dropin'].includes(sourceRole) || !meta.loaderProof) return null;
      const proof = this.#risk.claimLoader(meta.url, method, type, meta.loaderProof);
      if (!proof) throw new Error('Original loader is unbound.');
      this.#session = meta.loaderProof.session;
      this.#loaderDocument = meta.loaderProof.document;
      return this.#result(meta, 'security_loader', 1, 262144, { headers: { accept: '*/*' } });
    }
    if (url.hostname === 'bws.sandbox.airwallex.com' && ['main', 'dropin'].includes(sourceRole)) {
      if (!this.#loader) throw new Error('BWS loader proof is unavailable.');
      const projected = this.#risk.cookieProjection(meta.headers);
      if (!projected) throw new Error('BWS cookies are unbound.');
      const selected = this.#risk.claim(
        meta.url,
        method,
        type,
        projected,
        meta.body,
        meta.sourceURL,
        sourceRole
      );
      if (!selected) throw new Error('BWS source request is unbound.');
      return this.#result(meta, 'bws_' + selected.role, selected.role === 'event' ? 8 : 1, 65536, {
        headers: selected.headers,
        riskClaim: selected
      });
    }
    const root = '/webapp/fraud/device-fingerprint/';
    if (url.hostname === 'static.sandbox.airwallex.com' && get && this.#loader) {
      if (
        url.pathname === root + 'risk-iframe.html' &&
        type === 'document' &&
        ['main', 'dropin'].includes(meta.parentRole) &&
        meta.elementId === 'airwallex-risk-iframe'
      ) {
        const expected = new URLSearchParams({
          sessionId: this.#session,
          hasScriptId: 'false',
          disable3rdTracking: 'false',
          __domain__: new URL(this.#loaderDocument).origin
        });
        if ([...url.searchParams].sort().toString() !== [...expected].sort().toString())
          throw new Error('Risk child is unbound.');
        return this.#result(meta, 'risk_document', 1, 65536);
      }
      if (
        sourceRole === 'risk' &&
        type === 'script' &&
        !url.search &&
        url.pathname === root + 'risk-iframe-4035d85ec5dfcc1d4dcb.js'
      )
        return this.#result(meta, 'risk_bundle', 1, 262144);
      if (
        sourceRole === 'cf' &&
        type === 'script' &&
        this.#ray &&
        url.pathname === '/cdn-cgi/challenge-platform/scripts/jsd/main.js' &&
        url.search === '?ray=' + this.#ray
      )
        return this.#result(meta, 'cf_passive', 1, 524288);
    }
    if (
      sourceRole === 'risk' &&
      get &&
      type === 'script' &&
      url.hostname === site + '.cdn4.forter.com' &&
      !url.search &&
      new RegExp('^/(?:sn|snS)/' + site + '/script\\.js$', 'u').test(url.pathname)
    ) {
      return this.#result(meta, 'forter_entry', 1, 1048576);
    }
    if (sourceRole === 'risk' && this.#forter) {
      let user, kind;
      const ordinary = new RegExp('^/' + site + '/(' + userPattern + ')/(prop|wpt)\\.json$', 'u').exec(
        url.pathname
      );
      const generated = new RegExp('^(' + userPattern + ')-' + site + '\\.cdn\\.forter\\.com$', 'u').exec(
        url.hostname
      );
      if (ordinary && ['cdn0.forter.com', 'cdn6.forter.com'].includes(url.hostname)) {
        user = ordinary[1];
        kind = ordinary[2];
      } else if (generated && url.pathname === '/prop.json') {
        user = generated[1];
        kind = 'prop';
      }
      if (user) {
        if (this.#user && this.#user !== user) throw new Error('Forter identity changed.');
        this.#user = user;
        if (kind === 'wpt') {
          if (url.hostname !== 'cdn0.forter.com' || url.search) return null;
          if (
            method === 'OPTIONS' &&
            meta.body.length === 0 &&
            meta.headers['access-control-request-method'] === 'POST' &&
            meta.headers['access-control-request-headers'] === 'content-type'
          ) {
            // Preflight headers are the only additional standard fields for this role.
            const headers = { ...meta.headers };
            delete headers['access-control-request-method'];
            delete headers['access-control-request-headers'];
            return this.#result({ ...meta, headers }, 'forter_preflight', 1, 65536, {
              headers: {
                ...outgoing({ ...meta, headers }),
                'access-control-request-method': 'POST',
                'access-control-request-headers': 'content-type'
              }
            });
          }
          if (
            method !== 'POST' ||
            !/^application\/json;\s*charset=utf-8$/iu.test(meta.headers['content-type'] ?? '')
          )
            return null;
          const value = parseInstrumentJson(meta.body);
          if (
            !keys(value, ['wpt', 'sct', 'dlt']) ||
            !Object.values(value).every((item) => integer(item, 0, 3600000))
          ) {
            throw new Error('Forter measurement is unsupported.');
          }
        } else if (
          meta.body.length ||
          !['GET', 'POST'].includes(method) ||
          (url.hostname === 'cdn0.forter.com' ? !/^\?_=[0-9]{13}$/u.test(url.search) : Boolean(url.search))
        )
          return null;
        return this.#result(meta, 'forter_measurement', 11, 65536);
      }
    }
    if (
      get &&
      type === 'script' &&
      sourceRole === 'dropin' &&
      googleHosts.has(url.hostname) &&
      url.pathname === '/recaptcha/enterprise.js'
    ) {
      if (
        url.searchParams.get('render') !== googleKey ||
        [...url.searchParams.keys()].some((key) => !['render', 'hl'].includes(key)) ||
        (url.searchParams.has('hl') && url.searchParams.get('hl') !== 'en')
      )
        throw new Error('Google entry is unbound.');
      this.#googleOrigin = url.origin;
      this.#language = url.searchParams.get('hl') ?? 'en';
      return this.#result(meta, 'google_entry', 1, 524288);
    }
    if (
      this.#googleOrigin &&
      url.hostname === 'www.gstatic.com' &&
      get &&
      (type === 'script' || (type === 'other' && sourceRole === 'google' && meta.workerBound)) &&
      ['dropin', 'google'].includes(sourceRole)
    ) {
      const match =
        /^\/recaptcha\/releases\/([A-Za-z0-9_-]{1,64})\/recaptcha__([a-z]{2}(?:_[A-Z]{2})?)\.js$/u.exec(
          url.pathname
        );
      if (match && !url.search && match[1] === this.#release && match[2] === this.#language) {
        const role =
          sourceRole === 'dropin'
            ? 'google_main_release'
            : meta.workerBound
              ? 'google_worker_release'
              : 'google_anchor_release';
        return this.#result(meta, role, 1, 1048576);
      }
    }
    if (
      url.origin === this.#googleOrigin &&
      url.pathname.startsWith('/recaptcha/enterprise/') &&
      namespace(url.pathname.slice('/recaptcha/enterprise/'.length))
    ) {
      const leaf = url.pathname.split('/').at(-1);
      if (url.pathname.split('/').some((segment) => ['bframe', 'payload', 'userverify'].includes(segment))) {
        throw new Error('Interactive Google work is withheld.');
      }
      if (
        get &&
        type === 'document' &&
        leaf === 'anchor' &&
        meta.parentRole === 'dropin' &&
        url.searchParams.get('size') === 'invisible' &&
        url.searchParams.get('k') === googleKey &&
        url.searchParams.get('v') === this.#release
      )
        return this.#result(meta, 'google_anchor', 1, 262144);
      if (
        get &&
        ['script', 'other'].includes(type) &&
        sourceRole === 'google' &&
        leaf === 'webworker.js' &&
        [...url.searchParams.keys()].sort().join(',') === 'hl,v' &&
        url.searchParams.get('hl') === this.#language &&
        url.searchParams.get('v') === this.#release
      ) {
        return this.#result(meta, 'google_worker_initial', 1, 65536);
      }
      if (
        sourceRole === 'google' &&
        method === 'POST' &&
        ['fetch', 'xhr'].includes(type) &&
        (!meta.worker || meta.workerBound)
      )
        return this.#result(meta, 'google_connect', 20, 65536);
    }
    if (
      get &&
      type === 'script' &&
      sourceRole === 'dropin' &&
      url.hostname === 'turing.captcha.qcloud.com' &&
      /^\/(?:TCaptcha|TJCaptcha)\.js$/u.test(url.pathname) &&
      !url.search
    ) {
      return this.#result(meta, 'tencent_entry', 1, 1048576);
    }
    const tencent =
      url.hostname === 'turing.captcha.gtimg.com' && url.pathname.startsWith('/1/')
        ? url.pathname.slice(3)
        : url.hostname === 'turing.captcha.qcloud.com'
          ? url.pathname.slice(1)
          : null;
    if (this.#tencent && get && tencent && namespace(tencent) && ['dropin', 'tencent'].includes(sourceRole)) {
      if (
        type === 'document' &&
        tencent === 'template/drag_ele.html' &&
        meta.parentRole === 'dropin' &&
        meta.elementId === 'tcaptcha_iframe_dy' &&
        meta.hidden === true
      )
        return this.#result(meta, 'tencent_preload', 1, 1048576);
      if (
        ['script', 'stylesheet', 'image', 'font'].includes(type) &&
        /\.(?:js|css|png|svg|woff2?)$/u.test(tencent)
      )
        return this.#result(meta, 'tencent_static', 14, type === 'script' ? 1048576 : 262144);
    }
    return null;
  }
  preflightFor(claim) {
    if (
      claim.role === 'forter_measurement' &&
      claim.method === 'POST' &&
      /^application\/json/iu.test(claim.headers['content-type'] ?? '') &&
      !this.#forterGrant
    ) {
      return {
        role: 'forter_preflight',
        ordinal: this.take('forter_preflight', 1),
        method: 'OPTIONS',
        url: claim.url,
        headers: {
          accept: '*/*',
          origin: claim.headers.origin,
          'access-control-request-method': 'POST',
          'access-control-request-headers': 'content-type'
        },
        body: Buffer.alloc(0),
        responseLimit: 65536,
        scope: claim.scope,
        security: true
      };
    }
    if (claim.role !== 'bws_visitor') return null;
    const selected = this.#risk.preflightFor(claim.riskClaim);
    if (!selected) return null;
    return {
      role: 'bws_preflight',
      ordinal: this.take('bws_preflight', 1),
      method: 'OPTIONS',
      url: selected.url,
      headers: selected.headers,
      body: selected.body,
      responseLimit: 65536,
      scope: hash(this.#reads.initial),
      security: true,
      riskClaim: selected
    };
  }
  validate(claim, response) {
    if (response.status < 200 || response.status >= 300)
      throw new Error('Service response status is unsupported.');
    const media = response.headers['content-type'] ?? '';
    const script =
      claim.resourceType === 'script' ||
      ['google_worker_initial', 'google_worker_release'].includes(claim.role);
    const html = ['risk_document', 'google_anchor', 'tencent_preload'].includes(claim.role);
    if (
      (script &&
        !(claim.role === 'cf_passive' && response.body.length === 0) &&
        !/^(?:text|application)\/(?:javascript|ecmascript|x-javascript)(?:\s*;|$)/iu.test(media)) ||
      (html && !/^text\/html(?:\s*;|$)/iu.test(media))
    )
      throw new Error('Service media is unsupported.');
    if (claim.role === 'security_loader') {
      if (response.status !== 200 || !this.#risk.verifyLoader(response.body))
        throw new Error('Loader source changed.');
      this.#loader = true;
    } else if (claim.riskClaim) {
      if (!this.#risk.validateResponse(claim.riskClaim, response))
        throw new Error('BWS response is unbound.');
    } else if (claim.role === 'risk_document') {
      const raw = new TextDecoder('utf-8', { fatal: true }).decode(response.body);
      if (!raw.includes('risk-iframe-4035d85ec5dfcc1d4dcb.js')) throw new Error('Risk wrapper changed.');
      const rays = [...raw.matchAll(/\br:'([a-f0-9]{16})'/gu)];
      if (rays.length !== 1) throw new Error('Fresh passive entry is unbound.');
      this.#ray = rays[0][1];
    } else if (claim.role === 'forter_preflight') {
      const allowed = response.headers['access-control-allow-origin'];
      if (
        ![claim.headers.origin, '*'].includes(allowed) ||
        !(response.headers['access-control-allow-methods'] ?? '')
          .split(',')
          .map((value) => value.trim())
          .includes('POST') ||
        !(response.headers['access-control-allow-headers'] ?? '')
          .toLowerCase()
          .split(',')
          .map((value) => value.trim())
          .includes('content-type')
      ) {
        throw new Error('Forter CORS is unbound.');
      }
      this.#forterGrant = true;
    } else if (claim.role === 'forter_entry') this.#forter = true;
    else if (claim.role === 'tencent_entry') this.#tencent = true;
    else if (claim.role === 'google_entry') {
      const raw = new TextDecoder('utf-8', { fatal: true }).decode(response.body);
      const releases = [
        ...raw.matchAll(
          /https:\/\/www\.gstatic\.com\/recaptcha\/releases\/([A-Za-z0-9_-]{1,64})\/recaptcha__([a-z]{2}(?:_[A-Z]{2})?)\.js/gu
        )
      ];
      if (releases.length !== 1 || releases[0][2] !== this.#language)
        throw new Error('Google release is unbound.');
      this.#release = releases[0][1];
    }
    return {};
  }
  authority(host) {
    return (
      [
        'static.sandbox.airwallex.com',
        'bws.sandbox.airwallex.com',
        'www.recaptcha.net',
        'www.google.com',
        'www.gstatic.com',
        'fonts.gstatic.com',
        'turing.captcha.qcloud.com',
        'turing.captcha.gtimg.com',
        site + '.cdn4.forter.com',
        'cdn0.forter.com',
        'cdn6.forter.com',
        'cdn3.forter.com',
        'cdn9.forter.com'
      ].includes(host) ||
      new RegExp('^' + userPattern + '-' + site + '\\.cdn\\.forter\\.com$', 'u').test(host)
    );
  }
  evidence() {
    return { security_requests: this.#security, loader_verified: this.#loader };
  }
  toJSON() {
    throw new Error('Private instrument services cannot be serialized.');
  }
}

module.exports = { InstrumentServices, canonical, query, namespace, googleKey, site };
