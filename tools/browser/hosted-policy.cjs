'use strict';

const http = require('node:http');
const net = require('node:net');
const { createHash } = require('node:crypto');

// Development-only preflight: no financial request and no card entry is enabled.
// The shared checkout origin is a candidate initial document, never Sandbox proof.
const candidateOrigins = new Set([
  'https://checkout.airwallex.com',
  'https://checkout.sandbox.airwallex.com',
]);
// Public GETs from both candidate origins matched these exact bytes on 2026-11-08.
// New builds require fresh source verification and independent policy review.
const staticAssets = Object.freeze({
  '/pay/static/v2.286.0/js/vendor.20d1746387dc4efce401.js':
    '1c3f66b6b0ef42939e2c5b6e791b7489d89bf21f7bc7fc270523c6bcc1677c9c',
  '/pay/static/v2.286.0/js/749.7f82c5bd7427d761872f.js':
    '88c82045bf08ae4f7df3bf0fcde6d30ee78864e4aa1d7dad7204183de0fabbee',
  '/pay/static/v2.286.0/js/main.ddc4fcf3b752ddbd5f14.js':
    '72ce3dbcd9ef575f449648ea71b1f7c536a9a4157030de3ebe51c71b19cd4627',
});
// Public Sandbox Elements v2.56.0 and its closed Drop-in dependency graph, verified 2026-11-08.
const renderAssets = Object.freeze({
  '/assets/elements.bundle.min.js':
    'a85d68f422e8739828b0190cff84d03f7d0a205260dd76fd6d46d761f509cafb',
  '/static/js/1448.9354791a.chunk.js':
    '7de4517d6f121392e689ebd835fdbd86235891f973c42f7a83d2a3beeda68ad0',
  '/static/js/1456.02e69237.chunk.js':
    'b38f20c8f46b59024b486e0018fbd93b554d0dcfde952ac5450744ee1514fe95',
  '/static/js/1464.29ec3a7b.chunk.js':
    '94180ebe999b3d9d242e54450ea6a8adce9d01f29208fd2acdb23a250df3baf2',
  '/static/js/1519.720f0152.chunk.js':
    '77161f5512fe093230bc88d5a22ff5f559ca29ca9518e6659e878752dddae91c',
  '/static/js/1615.ab4ca615.chunk.js':
    '4844d3f2569a36c6adc70a794c5586df7a1d7e3ae970ac5d60ef8889561fdc6e',
  '/static/js/2495.b0916b22.chunk.js':
    '5e8a005ae7ca19932af88923cb78442a0aa40c625e29b6c7e5bd2799aec83d06',
  '/static/js/2546.a8d0eee7.chunk.js':
    'cd636d25949578ec8bc339d0512c44e757601590e391c241781f182fa7a3dc74',
  '/static/js/2678.abe26b48.chunk.js':
    'a88fd49ae21a86697562dfc73616b820cf4c54b34d47c4131253a7cbb505b828',
  '/static/js/3370.648e1309.chunk.js':
    'c0f307f0f719a707aaf7847020fe85a3dc7018cae4853b9b3621b32ed93cd0cc',
  '/static/js/3526.1a33e25e.chunk.js':
    'd0a3f09cea8944d2606fed53de9baeb42d96539cd3f50f747d20367d9aa0d8ab',
  '/static/js/3669.abcbfa75.chunk.js':
    '99207f29c1a9914beec573bb0a9197503ca9172cba9611f71a43da264e7a28f7',
  '/static/js/4005.eaa42562.chunk.js':
    '1286bd564966d8c9c490b8851164d3b24614b33658559db36dea4addc1e1da9c',
  '/static/js/4034.bd670c27.chunk.js':
    'f45417e39c1c1bba0ac85953588109943fcc63a6ba324c7b4f6f37107578df57',
  '/static/js/4954.a3e9db8f.chunk.js':
    '589bf0b0ddd1f1853fcf2176b5064784a3363e66a4447295a9f3515bb4ccc817',
  '/static/js/5006.c8e4b4bb.chunk.js':
    'a04c09a5bb24bc2999ebf3ea658eb040b48f6a2290d28ac231f7c548c6bbe427',
  '/static/js/5375.9ef4ace9.chunk.js':
    'fbe9c041b81e1932e7c9dff340dd8a004c5d3846572ac2394343c04be1991c53',
  '/static/js/552.6e622864.chunk.js':
    'ce44faa681e507baae317b5a43c58a78a5b322539c9a8507f540f7f888a7f9de',
  '/static/js/5766.3c92c4a8.chunk.js':
    'd14abd13f5e75abc285d8d46fbdf370de9a902d55cba371851c96226342986c4',
  '/static/js/5805.6f0e8774.chunk.js':
    'a372ae957a7c4a144247a8810fecb7d7e2ad784fe0de8f4dc65b3a84d633d20f',
  '/static/js/5862.8596651d.chunk.js':
    'e663f40bd2a71f74a81f6d57ac3ee4a0c01e08cb1cd04d21faf56dc16e380511',
  '/static/js/6114.b52d1e0d.chunk.js':
    'fc872e7a5d169bf25695ff3be55f08cf96a92cc7ea96b83ed13c23675746be23',
  '/static/js/6887.0be9a650.chunk.js':
    'b32a19c2f28e6f0d172a7762a57ac35ce5d81c516e334d462a5b6849579ba936',
  '/static/js/7215.87f033a7.chunk.js':
    'dfc03f732d7e564fb1bf5b644ebafa7d15366836e506a25c1919b9e1b8e701b9',
  '/static/js/7244.dd6b1a33.chunk.js':
    '5e00b68a47f7dedec073ddecd9d063f7f07326e7ef8fb36151bcca1fb8d98289',
  '/static/js/7310.c7f49da1.chunk.js':
    '1f5dc8aebbbbdab040a18208320d1cf076c3ea1aab566b1d4f36285234b18c73',
  '/static/js/7564.4813fec6.chunk.js':
    '334e308e6b0a3eaa5301cb6500c546ee5829aad8928d8de3c2e84dbccdb47c54',
  '/static/js/7715.2feff162.chunk.js':
    '46fea3daeda031d09393245bc591933593b95c5b669e496ca732c90ade223465',
  '/static/js/7845.b35be99e.chunk.js':
    'caf6e6afded41eb281b274a745a31084f8583f9cb4964eb9b5a92202cc663bc3',
  '/static/js/805.6469fd0d.chunk.js':
    '84ec662ab0952d9f02c48850e11512ed0d6f156565cefd1b969c04463684d662',
  '/static/js/8244.014b3345.chunk.js':
    '12e3beafc80c086151c22cdb357c2cf7c76c7f9f69c6480943c9d863dafbfa2c',
  '/static/js/8355.559178e5.chunk.js':
    'a8ce63ac7e7d2b961a8b1b057402c803f83e18850317257ee182480ad720d59d',
  '/static/js/861.72bd89a3.chunk.js':
    '0e7176a7ce7a6c84aa26e2495c390233e095220661bd9276ee440a230e4975cc',
  '/static/js/8696.4bb590b3.chunk.js':
    'c665cf5ba86f14d59b500f38f1243ede5e72fa0fa5cfe4e935bdc65f9f5bcad0',
  '/static/js/8963.a98d20bb.chunk.js':
    'd77eb07fe4dc988317cf120389076405b846a0ac40d4330965cbbb43d97e04b3',
  '/static/js/8993.eb825278.chunk.js':
    '6dc8796d69c89c7da1e26c9883e9f906e85c1c6328c42bebd4cb1e16011b268f',
  '/static/js/9126.dfecc435.chunk.js':
    'fbfd7b2b4a5fc3dec6c466d778488f3d4c794916ef1f2dc9daa05b05902816b9',
  '/static/js/9220.2b610317.chunk.js':
    'af85d8394b7b38506b828b0c9f5fd8710d0b80539b7407c43da5037dbde647a8',
  '/static/js/9727.ef6d685e.chunk.js':
    'f3358f0490ea55f5876409c40044210dadc181283701ef4ae5f1240c8134d919',
  '/static/js/9743.dd22c26a.chunk.js':
    'cb233eb9adb6064b8a1192c74a278f9753bd58be0f95b867ef62fc1f7ac514a8',
  '/static/js/9909.b5661b07.chunk.js':
    '19be74aaab799f66e9a4e3fe27a1691c35a4ea8a3afcce0827b53586858676b2',
  '/static/js/checkout.d2f9eaef.js':
    '3f0c7322f844c4aba58e8b46c9a12946160664ffb33d52b411892f34f3510887',
  '/static/js/vendor.61207a66.js':
    '13b59305407194e27f35cdeaf9ba2ecbbb049cf074012238d75461410cc7ea1f',
});
// Required public Components bootstrap, separate from the 43-file Elements graph.
const controllerAsset = Object.freeze({
  origin: 'https://static.sandbox.airwallex.com',
  pathname: '/widgets/sdk-controller/sdk/v1/index.js',
  sha256: 'ec137c7ecd9e5592792ec8f7e5780825d64012d8b722a4c8bb7188ea140dffc3',
});
const renderLanguages = new Set(['en', 'zh', 'ja', 'ko', 'ar', 'fr', 'es', 'nl', 'nl-NL', 'de', 'it',
  'zh-HK', 'pl', 'fi', 'ru', 'da', 'id', 'ms', 'sv', 'pt', 'ro']);

// Fixed local guard outcomes only. Never use URL, option keys or values as diagnostic labels.
const renderCheckNames = Object.freeze([
  'document_candidates', 'method_mismatch', 'frame_unavailable', 'parent_mismatch',
  'request_origin_mismatch', 'request_path_or_query_mismatch', 'source_unavailable',
  'scope_unverified', 'payer_not_delivered', 'reference_unavailable', 'limit_reached',
  'invalid_source_url', 'source_origin_mismatch', 'source_path_or_query_mismatch',
  'fragment_route_mismatch', 'fragment_keys_mismatch', 'build_mismatch', 'language_mismatch',
  'invalid_options', 'mode_mismatch', 'currency_mismatch', 'secret_mismatch',
  'customer_mismatch', 'intent_mismatch', 'qualified', 'document_redirect',
  'document_http_or_build_mismatch', 'fulfilled',
]);
const deniedReadNames = Object.freeze(['method_config', 'country', 'customer', 'consent_list', 'checkout_status', 'other_pa']);
const returnUrl = 'https://example.com/';

function assetMatches(bytes, expected) {
  return Buffer.isBuffer(bytes) && typeof expected === 'string'
    && createHash('sha256').update(bytes).digest('hex') === expected;
}

function parse(value) {
  try {
    if (typeof value !== 'string' || value.length > 16384 || /[\u0000-\u0020\\]/u.test(value)) return null;
    const url = new URL(value);
    return url.username || url.password ? null : url;
  } catch { return null; }
}

function privateJson(body) {
  if (!Buffer.isBuffer(body) || body.length > 65536) throw new Error('Invalid private read.');
  const raw = new TextDecoder('utf-8', { fatal: true }).decode(body);
  const data = JSON.parse(raw);
  const bounded = (node, depth) => depth <= 32 && (node === null || typeof node !== 'object'
    || Object.values(node).every(value => bounded(value, depth + 1)));
  if (!data || typeof data !== 'object' || Array.isArray(data) || !bounded(data, 0)) throw new Error('Invalid private read.');
  const scopes = [];
  for (let index = 0; index < raw.length; index++) {
    const char = raw[index];
    if (char === '{' || char === '[') scopes.push(char === '{' ? new Set() : null);
    else if (char === '}' || char === ']') scopes.pop();
    else if (char === '"') {
      const expression = /"(?:[^"\\]|\\.)*"/gy;
      expression.lastIndex = index;
      const match = expression.exec(raw);
      if (match === null) throw new Error('Invalid private read.');
      index = expression.lastIndex - 1;
      if (raw.slice(index + 1).trimStart()[0] === ':') {
        const key = JSON.parse(match[0]);
        const scope = scopes.at(-1);
        if (!(scope instanceof Set) || scope.has(key)) throw new Error('Invalid private read.');
        scope.add(key);
      }
    }
  }
  return data;
}

class HostedPolicy {
  #initial;
  #fixture;
  #token = null;
  #scope = false;
  #expires = 0;
  #payerAttempted = false;
  #expected;
  #render;
  #payerMatched = false;
  #payerDelivered = false;
  #frameClaimed = false;
  #frameReference = null;
  #frameSource = null;
  #controllerAttempted = false;
  #observeConfig;
  #observeNormal;
  #observeParentCountry;
  #parentCountryDeadline = 0;
  #parentMainRequested = false;
  #parentCountryClosed = false;
  #parentCountryAttempted = false;
  #parentCountryFailed = false;
  #normalClock;
  #normalDeadline = 0;
  #normalClosed = false;
  #normalAttempts = { customer: 0, consent_list: 0, country: 0, checkout_status: 0 };
  #statusBusy = false;
  #statusPoisoned = false;
  #frameDelivered = false;
  #configAttempted = false;
  #configMatched = false;
  #deniedReads = Object.fromEntries(deniedReadNames.map(name => [name, 0]));
  #renderChecks = Object.fromEntries(renderCheckNames.map(name => [name, 0]));
  constructor(initial, fixtureOrigin = null, expected = null, render = false, observeConfig = false, observeNormal = false, clock = () => performance.now(), observeParentCountry = false) {
    if (typeof render !== 'boolean' || (render && expected === null)) {
      throw new Error('Invalid read-only render configuration.');
    }
    if (typeof observeConfig !== 'boolean' || (observeConfig && !render)) {
      throw new Error('Method observation requires explicit rendering.');
    }
    if (typeof observeNormal !== 'boolean' || (observeNormal && !observeConfig) || typeof clock !== 'function') {
      throw new Error('Normal reads require explicit method observation.');
    }
    if (typeof observeParentCountry !== 'boolean' || (observeParentCountry && !observeNormal)) {
      throw new Error('Parent country requires explicit normal reads.');
    }
    this.#observeParentCountry = observeParentCountry;
    this.#observeNormal = observeNormal;
    this.#normalClock = clock;
    this.#observeConfig = observeConfig;
    this.#render = render;
    const url = parse(initial);
    if (!url) throw new Error('Invalid browser preflight configuration.');
    if (fixtureOrigin === null) {
      if (!candidateOrigins.has(url.origin) || url.pathname !== '/pay' || !url.searchParams.get('s')) {
        throw new Error('Invalid browser preflight configuration.');
      }
    } else {
      const fixture = parse(fixtureOrigin);
      if (!fixture || fixture.hostname !== '127.0.0.1' || fixture.protocol !== 'http:'
          || fixture.pathname !== '/' || fixture.search || fixture.hash || url.origin !== fixture.origin) {
        throw new Error('Invalid local fixture configuration.');
      }
    }
    this.#initial = url.href;
    this.#fixture = fixtureOrigin;
    this.#expected = expected;
    if (expected !== null) {
      if (!expected || typeof expected !== 'object' || Array.isArray(expected)
          || Object.keys(expected).sort().join(',') !== 'checkout_id,currency,mode'
          || typeof expected.checkout_id !== 'string' || expected.checkout_id.length === 0
          || Buffer.byteLength(expected.checkout_id, 'utf8') > 256 || /[\u0000-\u0020]/u.test(expected.checkout_id)
          || expected.mode !== 'SETUP' || !/^[A-Z]{3}$/u.test(expected.currency)) {
        throw new Error('Invalid private browser expectation.');
      }
      this.#expected = Object.freeze({ ...expected });
      // This is correlation with the SDK-verified URL, not independent JWT signature verification.
      const tokens = url.searchParams.getAll('s');
      if (tokens.length === 1 && /^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/u.test(tokens[0])) {
        try {
          const claim = JSON.parse(Buffer.from(tokens[0].split('.')[1], 'base64url').toString('utf8'));
          this.#scope = claim !== null && typeof claim === 'object' && !Array.isArray(claim)
            && typeof claim.resource_id === 'string' && claim.resource_id.length <= 1024
            && claim.resource_id.includes(':') && claim.resource_id.split(':').pop() === expected.checkout_id
            && Number.isSafeInteger(claim.exp) && claim.exp > Math.floor(Date.now() / 1000);
          if (this.#scope) {
            this.#token = tokens[0];
            this.#expires = claim.exp;
          }
        } catch { /* No raw URL/token/claim becomes an error or diagnostic. */ }
      }
    }
  }

  claimPayerRequest(value, method, type, headers, body = null) {
    const url = parse(value);
    const initial = new URL(this.#initial);
    if (!this.requestScopeVerified() || this.#payerAttempted || !url || method !== 'GET'
        || !['xhr', 'fetch'].includes(type) || body !== null || url.origin !== initial.origin
        || url.pathname !== '/billing/payer-web-api/checkout'
        || !/^\?ts=[0-9]{13}$/u.test(url.search) || url.hash || value !== url.origin + url.pathname + url.search
        || headers.authorization !== `Bearer ${this.#token}`) return false;
    // Set before sending: the public application's interceptor may otherwise retry network/5xx failures.
    this.#payerAttempted = true;
    return true;
  }

  requestScopeVerified() {
    return this.#scope && this.#expires > Math.floor(Date.now() / 1000)
      && (this.#fixture !== null || this.candidateOrigin() === 'sandbox');
  }

  bindingEvidence() {
    return { checkout_token_matches: this.#scope, payer_request_scope_verified: this.requestScopeVerified(),
      payer_get_attempted: this.#payerAttempted, payer_customer_mapping_verified: false,
      payer_payload_delivered: this.#payerDelivered, financial_routing_verified: false,
      render_requested: this.#render, render_frame_binding_available: this.#frameReference !== null };
  }

  observePayer(body) {
    this.#payerMatched = false;
    this.#frameReference = null;
    try {
      if (!Buffer.isBuffer(body) || body.length > 65536) throw new Error();
      const data = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(body));
      if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error();
      const mode = data.mode === this.#expected?.mode;
      const currency = data.currency === this.#expected?.currency;
      this.#payerMatched = mode && currency;
      const setup = data.setupModeDetails;
      if (this.#render && this.#payerMatched && setup && typeof setup === 'object' && !Array.isArray(setup)) {
        const secret = setup.externalTransactionClientSecret || setup.externalCustomerClientSecret;
        if (typeof secret === 'string' && secret.length > 0 && secret.length <= 8192
            && typeof setup.billingCustomerExternalId === 'string' && setup.billingCustomerExternalId.length > 0
            && setup.billingCustomerExternalId.length <= 1024) {
          this.#frameReference = { secret, customer: setup.billingCustomerExternalId,
            intent: setup.billingTransactionExternalId ?? null };
        }
      }
      return { payer_observation: mode && currency ? 'matched' : 'mode_or_currency_mismatch',
        payer_mode_matches: mode, payer_currency_matches: currency };
    } catch {
      return { payer_observation: 'invalid_response', payer_mode_matches: false, payer_currency_matches: false };
    }
  }

  renderEnabled() { return this.#render && this.requestScopeVerified(); }

  isRenderAsset(value) {
    const url = parse(value);
    return this.renderEnabled() && url !== null && (this.#fixture !== null
      ? url.pathname === '/render-sdk.js' : Object.hasOwn(renderAssets, url.pathname));
  }

  mayDeliverPayer() { return this.renderEnabled() && this.#payerAttempted && this.#payerMatched; }

  markPayerDelivered() {
    if (!this.mayDeliverPayer()) throw new Error('The owned payer data has not been verified.');
    this.#payerDelivered = true;
  }

  noteRenderCheck(name) {
    if (this.#render && Object.hasOwn(this.#renderChecks, name)) {
      this.#renderChecks[name] = Math.min(1000000, this.#renderChecks[name] + 1);
    }
    return false;
  }

  renderEvidence() {
    return this.#render ? { render_frame_checks: { ...this.#renderChecks } } : {};
  }

  renderRequestTarget(value) {
    const url = parse(value);
    if (!url || url.origin !== new URL(this.#initial).origin) {
      return this.noteRenderCheck('request_origin_mismatch');
    }
    if (value !== new URL(this.#fixture === null ? '/' : '/render-frame', this.#initial).href) {
      return this.noteRenderCheck('request_path_or_query_mismatch');
    }
    return true;
  }

  claimRenderFrame(value) {
    if (!this.renderEnabled()) return this.noteRenderCheck('scope_unverified');
    if (!this.#payerDelivered) return this.noteRenderCheck('payer_not_delivered');
    if (!this.#frameReference) return this.noteRenderCheck('reference_unavailable');
    if (this.#frameClaimed) return this.noteRenderCheck('limit_reached');
    const url = parse(value);
    if (!url) return this.noteRenderCheck('invalid_source_url');
    if (url.origin !== new URL(this.#initial).origin) return this.noteRenderCheck('source_origin_mismatch');
    if (url.search || url.pathname !== (this.#fixture === null ? '/' : '/render-frame')) {
      return this.noteRenderCheck('source_path_or_query_mismatch');
    }
    if (!url.hash.startsWith('#/elements/dropin?')) return this.noteRenderCheck('fragment_route_mismatch');
    try {
      const query = new URLSearchParams(url.hash.slice('#/elements/dropin?'.length));
      if ([...query.keys()].sort().join(',') !== 'appVersion,lang,options') {
        return this.noteRenderCheck('fragment_keys_mismatch');
      }
      if (query.get('appVersion') !== 'v2.56.0') return this.noteRenderCheck('build_mismatch');
      if (!renderLanguages.has(query.get('lang'))) return this.noteRenderCheck('language_mismatch');
      const raw = query.get('options');
      if (!raw || raw.length > 16384) return this.noteRenderCheck('invalid_options');
      const options = JSON.parse(raw);
      if (!options || typeof options !== 'object' || Array.isArray(options)) {
        return this.noteRenderCheck('invalid_options');
      }
      if (options.mode !== 'recurring') return this.noteRenderCheck('mode_mismatch');
      if (options.currency !== this.#expected.currency) return this.noteRenderCheck('currency_mismatch');
      if (options.client_secret !== this.#frameReference.secret) return this.noteRenderCheck('secret_mismatch');
      if (options.customer_id !== this.#frameReference.customer) return this.noteRenderCheck('customer_mismatch');
      if ((options.intent_id ?? null) !== this.#frameReference.intent) return this.noteRenderCheck('intent_mismatch');
      this.#frameClaimed = true;
      this.#frameSource = url.href;
      this.noteRenderCheck('qualified');
      return true;
    } catch { return this.noteRenderCheck('invalid_options'); }
  }

  markRenderFrameDelivered() {
    if (!this.#frameClaimed || !this.#payerDelivered || !this.renderEnabled()) {
      throw new Error('The read-only frame is not bound.');
    }
    this.#frameDelivered = true;
  }

  claimMethodConfig(value, method, type, headers, body = null) {
    const url = parse(value);
    if (!this.#observeConfig || !this.renderEnabled() || !this.#payerDelivered || !this.#frameDelivered
        || !this.#frameReference || this.#configAttempted || method !== 'GET'
        || !['fetch', 'xhr'].includes(type) || body !== null || !url
        || url.origin !== new URL(this.#initial).origin || url.pathname !== '/api/v1/pa/config/payment_method_types'
        || url.hash || value !== url.origin + url.pathname + url.search) return null;
    const query = url.searchParams;
    const required = { page_num: '0', page_size: '200', active: 'true',
      transaction_currency: this.#expected.currency, __verification_methods: 'true' };
    const allowed = new Set([...Object.keys(required), 'country_code', 'payment_intent_id']);
    if ([...query.keys()].some(key => !allowed.has(key) || query.getAll(key).length !== 1)
        || Object.entries(required).some(([key, val]) => query.get(key) !== val)
        || (query.has('country_code') && !/^[A-Z]{2}$/u.test(query.get('country_code')))
        || (query.has('payment_intent_id') && (typeof this.#frameReference.intent !== 'string'
          || query.get('payment_intent_id') !== this.#frameReference.intent))) return null;
    if (headers['client-secret'] !== this.#frameReference.secret || headers['x-api-version'] !== '2024-06-14'
        || !/^[0-9a-f]{32}$/u.test(headers['x-cloud-trace-context'] ?? '')
        || headers['content-type'] !== 'application/json' || headers['x-client-ip-source'] !== 'awx-checkout'
        || Object.keys(headers).some(key => key.startsWith('x-')
          && !['x-api-version', 'x-client-ip-source', 'x-device-type', 'x-cloud-trace-context'].includes(key))
        || ['authorization', 'x-auth-token', 'x-on-behalf-of', 'x-tax-region',
          'x-tax-calculation-identifier', 'x-pan'].some(key => Object.hasOwn(headers, key))
        || (Object.hasOwn(headers, 'x-device-type') && !['mobile', 'desktop'].includes(headers['x-device-type']))
        || (Object.hasOwn(headers, 'awx-tracker') && (typeof headers['awx-tracker'] !== 'string'
          || headers['awx-tracker'].length > 256 || /[^\x21-\x7e]/u.test(headers['awx-tracker'])))) return null;
    this.#configAttempted = true; // Before any send: the browser helper's retry must remain denied.
    const outbound = { accept: 'application/json, text/plain, */*', cookie: '', referer: '',
      'client-secret': headers['client-secret'], 'content-type': 'application/json',
      'x-api-version': '2024-06-14', 'x-client-ip-source': 'awx-checkout',
      'x-cloud-trace-context': headers['x-cloud-trace-context'] };
    for (const key of ['awx-tracker', 'x-device-type']) if (Object.hasOwn(headers, key)) outbound[key] = headers[key];
    return outbound;
  }

  observeMethodConfig(body) {
    this.#configMatched = false;
    try {
      if (!Buffer.isBuffer(body) || body.length > 262144) throw new Error();
      const data = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(body));
      const bounded = (node, depth) => depth <= 32 && (node === null || typeof node !== 'object'
        || Object.values(node).every(value => bounded(value, depth + 1)));
      if (!data || typeof data !== 'object' || Array.isArray(data) || !bounded(data, 0)
          || !Array.isArray(data.items) || data.items.length > 200
          || data.items.some(item => !item || typeof item !== 'object' || Array.isArray(item))
          || (Object.hasOwn(data, 'has_more') && typeof data.has_more !== 'boolean')) throw new Error();
      this.#configMatched = this.#configAttempted;
      return { method_config_observation: 'matched', method_config_items: data.items.length,
        method_config_pagination_known: typeof data.has_more === 'boolean',
        method_config_has_more: data.has_more === true,
        method_config_card_advertised: data.items.some(item => item.name === 'card') };
    } catch { return { method_config_observation: 'invalid_response' }; }
  }

  mayDeliverMethodConfig() {
    return this.#observeConfig && this.renderEnabled() && this.#frameDelivered && this.#configMatched;
  }

  normalSourceMatches(value, main = false) {
    const url = parse(value);
    return url !== null && value === url.href && this.renderEnabled()
      && url.href === (main ? this.#initial : this.#frameSource);
  }

  noteParentMainDocument(value) {
    if (!this.#observeParentCountry) return;
    if (this.#parentMainRequested || value !== this.#initial) this.closeParentCountryWindow();
    else this.#parentMainRequested = true;
  }

  startParentCountryWindow() {
    if (this.#observeParentCountry && this.#parentMainRequested && !this.#parentCountryClosed
        && this.renderEnabled() && this.#parentCountryDeadline === 0) {
      this.#parentCountryDeadline = this.#normalClock() + 8000;
    }
  }

  parentCountryWindowRemaining() {
    if (!this.#observeParentCountry || !this.renderEnabled() || this.#parentCountryClosed || this.#normalClosed
        || this.#parentCountryDeadline === 0) return 0;
    const remaining = this.#parentCountryDeadline - this.#normalClock();
    if (!Number.isFinite(remaining) || remaining <= 0) return 0;
    return this.#normalDeadline === 0 ? remaining : Math.min(remaining, this.normalWindowRemaining());
  }

  closeParentCountryWindow() { this.#parentCountryClosed = true; }
  failParentCountry() { this.#parentCountryFailed = true; }

  claimParentCountry(value, method, type, headers, body, fromMain) {
    const url = parse(value);
    if (!this.#observeParentCountry || !fromMain || this.#parentCountryAttempted
        || this.parentCountryWindowRemaining() <= 0 || !url || method !== 'GET'
        || !['fetch', 'xhr'].includes(type) || body !== null || url.search || url.hash
        || url.origin !== (this.#fixture ?? 'https://www.sandbox.airwallex.com') || url.pathname !== '/country'
        || value !== url.origin + url.pathname) return null;
    const outgoing = this.#normalHeaders(headers, 'parent_country');
    if (outgoing === null) return null;
    this.#parentCountryAttempted = true;
    return outgoing;
  }

  parentCountrySendAllowed() {
    return this.#parentCountryAttempted && !this.#parentCountryFailed && this.parentCountryWindowRemaining() > 0;
  }

  observeParentCountry(body) {
    try {
      const data = privateJson(body);
      if (typeof data.countryCode !== 'string' || !/^[A-Z]{2}$/u.test(data.countryCode)) throw new Error();
      return true;
    } catch { this.failParentCountry(); return false; }
  }

  parentCountryEvidence() {
    return this.#observeParentCountry ? { parent_country_requested: true,
      parent_country_startup_started: this.#parentCountryDeadline !== 0,
      parent_country_window_closed: this.#parentCountryClosed || this.#normalClosed
        || (this.#parentCountryDeadline !== 0 && this.parentCountryWindowRemaining() <= 0),
      parent_country_attempts: this.#parentCountryAttempted ? 1 : 0 } : {};
  }

  // A private source-generated session, never an operator label or output field.
  riskSessionMatches(value, main = false) {
    if (typeof value !== 'string' || !/^[A-Za-z0-9_-]{1,128}$/u.test(value) || !this.#frameSource) return false;
    try {
      const query = new URLSearchParams(new URL(this.#frameSource).hash.slice('#/elements/dropin?'.length));
      const options = privateJson(Buffer.from(query.get('options') ?? ''));
      return options.sessionId === value && this.#frameDelivered
        && this.normalSourceMatches(main ? this.#initial : this.#frameSource, main);
    } catch { return false; }
  }

  normalSendAllowed(role) {
    return this.#observeNormal && this.#payerDelivered && this.normalWindowRemaining() > 0
      && Object.hasOwn(this.#normalAttempts, role) && this.#normalAttempts[role] > 0
      && (role === 'checkout_status' ? !this.#statusPoisoned : this.#frameDelivered && this.#frameReference !== null);
  }

  startNormalWindow() {
    if (this.#observeNormal && this.mayDeliverPayer() && this.#normalDeadline === 0) {
      this.#normalDeadline = this.#normalClock() + 8000;
    }
  }

  normalWindowRemaining() {
    if (!this.#observeNormal || this.#normalClosed || !this.requestScopeVerified()) return 0;
    const remaining = this.#normalDeadline - this.#normalClock();
    return Number.isFinite(remaining) && remaining > 0 ? remaining : 0;
  }

  closeNormalWindow() { this.#normalClosed = true; }

  #normalHeaders(headers, role) {
    const common = ['accept', 'accept-language', 'accept-encoding', 'host', 'connection', 'priority',
      'user-agent', 'origin', 'referer', 'cookie', 'sec-ch-ua', 'sec-ch-ua-mobile', 'sec-ch-ua-platform',
      'sec-fetch-dest', 'sec-fetch-mode', 'sec-fetch-site'];
    const pa = ['client-secret', 'content-type', 'awx-tracker', 'x-api-version', 'x-client-ip-source',
      'x-device-type', 'x-cloud-trace-context'];
    const allowed = [...common, ...(role === 'pa' ? pa : role === 'checkout_status' ? ['authorization'] : [])];
    if (!headers || typeof headers !== 'object' || Object.keys(headers).some(key => !allowed.includes(key))
        || (Object.hasOwn(headers, 'origin') && headers.origin !== new URL(this.#initial).origin)) return null;
    if (role === 'pa') {
      if (headers['client-secret'] !== this.#frameReference?.secret || headers['x-api-version'] !== '2024-06-14'
          || headers['content-type'] !== 'application/json' || headers['x-client-ip-source'] !== 'awx-checkout'
          || !/^[0-9a-f]{32}$/u.test(headers['x-cloud-trace-context'] ?? '')
          || (Object.hasOwn(headers, 'x-device-type') && !['mobile', 'desktop'].includes(headers['x-device-type']))
          || (Object.hasOwn(headers, 'awx-tracker') && (typeof headers['awx-tracker'] !== 'string'
            || headers['awx-tracker'].length > 256 || /[^\x21-\x7e]/u.test(headers['awx-tracker'])))) return null;
      const result = { accept: 'application/json, text/plain, */*', cookie: '', referer: '' };
      for (const key of pa) if (Object.hasOwn(headers, key)) result[key] = headers[key];
      return result;
    }
    if (role === 'parent_country' && headers.accept !== 'application/json, text/plain, */*') return null;
    if (role === 'checkout_status' && headers.authorization !== `Bearer ${this.#token}`) return null;
    return { accept: role === 'country' ? '*/*' : 'application/json, text/plain, */*', cookie: '', referer: '',
      ...(role === 'checkout_status' ? { authorization: headers.authorization } : {}),
      ...(['country', 'parent_country'].includes(role) && Object.hasOwn(headers, 'origin') ? { origin: headers.origin } : {}) };
  }

  claimNormalRead(value, method, type, headers, body, fromMain, fromBound) {
    const url = parse(value);
    if (!this.#observeNormal || !this.#payerDelivered || this.normalWindowRemaining() <= 0 || !url
        || method !== 'GET' || !['fetch', 'xhr'].includes(type) || body !== null || url.hash
        || value !== url.origin + url.pathname + url.search) return null;
    const origin = new URL(this.#initial).origin;
    let role;
    if (fromMain && url.origin === origin && url.pathname === '/billing/payer-web-api/checkout/status'
        && !url.search) role = 'checkout_status';
    else if (fromBound && this.#frameDelivered && this.#frameReference) {
      const customer = this.#frameReference.customer;
      if (!/^[A-Za-z0-9_-]{1,200}$/u.test(customer)) return null;
      if (url.origin === origin && url.pathname === '/api/v1/pa/customers/' + customer && !url.search) role = 'customer';
      else if (url.origin === origin && url.pathname === '/api/v1/pa/payment_consents'
          && url.search.slice(1).split('&').sort().join('&')
            === ['customer_id=' + customer, 'status=VERIFIED', 'page_size=100'].sort().join('&')) role = 'consent_list';
      else if (url.origin === (this.#fixture ?? 'https://www.sandbox.airwallex.com')
          && url.pathname === '/country' && !url.search) role = 'country';
    }
    if (!role || this.#normalAttempts[role] >= (role === 'checkout_status' ? 3 : 1)
        || (role === 'checkout_status' && (this.#statusPoisoned || this.#statusBusy))) return null;
    const outbound = this.#normalHeaders(headers, ['customer', 'consent_list'].includes(role) ? 'pa' : role);
    if (outbound === null) return null;
    this.#normalAttempts[role]++;
    if (role === 'checkout_status') this.#statusBusy = true;
    return { role, headers: outbound };
  }

  failNormalRead(role) {
    if (role === 'checkout_status') this.#statusPoisoned = true;
  }

  observeNormalRead(role, body) {
    let checkoutState = 'unavailable';
    try {
      const data = privateJson(body);
      if (role === 'customer') {
        if (data.id !== this.#frameReference?.customer) throw new Error();
      } else if (role === 'consent_list') {
        if (!Array.isArray(data.items) || data.items.length > 100
            || data.items.some(item => !item || typeof item !== 'object' || Array.isArray(item)
              || typeof item.id !== 'string' || !/^[A-Za-z0-9_-]{1,200}$/u.test(item.id)
              || item.customer_id !== this.#frameReference?.customer || item.status !== 'VERIFIED')
            || new Set(data.items.map(item => item.id)).size !== data.items.length
            || (Object.hasOwn(data, 'has_more') && typeof data.has_more !== 'boolean')) throw new Error();
        return { matched: true, items: data.items.length, pagination_known: typeof data.has_more === 'boolean',
          has_more: data.has_more === true };
      } else if (role === 'country') {
        if (typeof data.countryCode !== 'string' || !/^[A-Z]{2}$/u.test(data.countryCode)) throw new Error();
      } else if (role === 'checkout_status') {
        // Hosted 2.286.0 Zt() returns this gateway object unchanged; SDK ACTIVE is a separate contract.
        checkoutState = ['REQUESTED', 'PENDING', 'COMPLETED', 'EXPIRED'].includes(data.status)
          ? data.status : typeof data.status === 'string' ? 'unlisted' : 'unavailable';
        if (checkoutState !== 'REQUESTED') throw new Error();
      } else throw new Error();
      return { matched: true, ...(role === 'checkout_status' ? { state: checkoutState } : {}) };
    } catch {
      this.failNormalRead(role);
      return { matched: false, ...(role === 'checkout_status' ? { state: checkoutState } : {}) };
    }
  }

  markNormalReadDelivered(role) {
    if (role === 'checkout_status') this.#statusBusy = false;
  }

  normalEvidence() {
    return this.#observeNormal ? { normal_reads_requested: true, normal_read_window_closed: this.#normalClosed,
      normal_customer_attempts: this.#normalAttempts.customer, normal_consent_list_attempts: this.#normalAttempts.consent_list,
      normal_country_attempts: this.#normalAttempts.country, normal_checkout_status_attempts: this.#normalAttempts.checkout_status,
      normal_checkout_status_poisoned: this.#statusPoisoned } : {};
  }

  noteDeniedRead(value, method, type) {
    if (!this.#observeConfig || method !== 'GET' || !['xhr', 'fetch'].includes(type)) return;
    const url = parse(value);
    if (!url) return;
    // The pinned helper rN maps this read to www.sandbox, not the same-origin PA gateway.
    // Classification grants no route or proxy authority; that origin remains blocked.
    const countryOrigin = this.#fixture ?? 'https://www.sandbox.airwallex.com';
    if (url.origin === countryOrigin && url.pathname === '/country' && !url.search && !url.hash
        && value === url.origin + url.pathname) {
      this.#deniedReads.country = Math.min(1000000, this.#deniedReads.country + 1);
      return;
    }
    if (url.origin !== new URL(this.#initial).origin) return;
    const pathname = url.pathname;
    const family = pathname === '/api/v1/pa/config/payment_method_types' ? 'method_config'
      : /^\/api\/v1\/pa\/customers\/[^/]+$/u.test(pathname) ? 'customer'
      : pathname === '/api/v1/pa/payment_consents' ? 'consent_list'
      : pathname === '/billing/payer-web-api/checkout/status' ? 'checkout_status'
      : pathname.startsWith('/api/v1/pa/') ? 'other_pa' : null;
    if (family) this.#deniedReads[family] = Math.min(1000000, this.#deniedReads[family] + 1);
  }

  methodEvidence() {
    return this.#observeConfig ? { method_config_requested: true, method_config_get_attempted: this.#configAttempted,
      denied_read_families: { ...this.#deniedReads } } : {};
  }

  decide(value, method, type) {
    const url = parse(value);
    if (!url) return 'block';
    if (url.origin === 'https://example.com' && url.pathname === '/' && method === 'GET' && type === 'document') {
      return 'return'; // Never send redirect parameters or Referer to the external return destination.
    }
    if (method !== 'GET') return 'block';
    if (type === 'document' && url.href === this.#initial) return 'allow';
    if (this.assetHash(value, method, type) !== null) return 'allow';
    if (this.#fixture !== null && url.origin === this.#fixture
        && type === 'document'
        && ['/frame', '/redirect', '/redirect-same', '/redirect-other'].includes(url.pathname)) return 'allow';
    return 'block';
  }

  isControllerTarget(value, method, type) {
    if (method !== 'GET' || type !== 'script') return false;
    const url = parse(value);
    const origin = this.#fixture ?? controllerAsset.origin;
    const pathname = this.#fixture === null ? controllerAsset.pathname : '/controller-sdk.js';
    return url !== null && url.origin === origin && url.pathname === pathname
      && /^\?ts=[0-9]{13}$/u.test(url.search) && value === url.origin + url.pathname + url.search;
  }

  controllerHash(value, method, type) {
    if (!this.renderEnabled() || !this.isControllerTarget(value, method, type)) return null;
    return this.#fixture === null ? controllerAsset.sha256
      : '99856801c4bc5565a929fc02ab1ef5337ed6109963c37acb93a75dd3d4107d20';
  }

  claimControllerRequest(value, method, type) {
    if (this.#controllerAttempted || this.controllerHash(value, method, type) === null) return false;
    this.#controllerAttempted = true; // Never repeat this public GET after an ambiguous response or script error.
    return true;
  }

  assetHash(value, method, type) {
    const controller = this.controllerHash(value, method, type);
    if (controller !== null) return controller;
    const url = parse(value);
    if (!url || method !== 'GET' || type !== 'script') return null;
    if (this.renderEnabled() && url.origin === new URL(this.#initial).origin) {
      if (this.#fixture !== null && url.pathname === '/render-sdk.js' && /^\?ts=[0-9]{13}$/u.test(url.search)
          && value === url.origin + url.pathname + url.search) {
        return 'e50b6631263ae1b6b4f9294e47eddc2a04a85eaaa1d22ee9d49a744ddd282b3e';
      }
      if (this.#fixture === null && Object.hasOwn(renderAssets, url.pathname)) {
        const allowedQuery = url.pathname === '/assets/elements.bundle.min.js'
          ? /^\?ts=[0-9]{13}$/u.test(url.search) : url.search === '';
        if (allowedQuery && value === url.origin + url.pathname + url.search) return renderAssets[url.pathname];
      }
    }
    if (value !== url.origin + url.pathname) return null;
    if (this.#fixture !== null) {
      return url.origin === this.#fixture && url.pathname === '/asset.js'
        ? 'e7642fccb474be1170bd51a330b606aca3bd360717d6fb52abe2be2638b47a1c' : null;
    }
    return url.origin === new URL(this.#initial).origin
      && Object.hasOwn(staticAssets, url.pathname) ? staticAssets[url.pathname] : null;
  }

  candidateOrigin() {
    if (this.#fixture !== null) return null;
    return new URL(this.#initial).hostname === 'checkout.sandbox.airwallex.com' ? 'sandbox' : 'shared';
  }

  allowsAuthority(host, port) {
    if (this.#fixture !== null) {
      return host === '127.0.0.1' && String(port) === new URL(this.#fixture).port;
    }
    const url = parse(`https://${host}:${port}/`);
    if (!url || String(port) !== '443') return false;
    return url.origin === new URL(this.#initial).origin
      || (this.renderEnabled() && url.origin === controllerAsset.origin)
      || (this.#observeNormal && this.#payerDelivered && this.normalWindowRemaining() > 0
        && url.origin === 'https://www.sandbox.airwallex.com')
      || (this.parentCountrySendAllowed() && url.origin === 'https://www.sandbox.airwallex.com');
  }

  allowsHttp(value) {
    const url = parse(value);
    return this.#fixture !== null && url !== null && url.origin === this.#fixture;
  }

  toJSON() { throw new Error('Browser preflight configuration cannot be serialized.'); }
}

// A second host boundary protects browser traffic outside page routing (including
// redirects/background traffic). CONNECT is tunneled unchanged: browser TLS remains enabled.
async function egressProxy(policy) {
  const sockets = new Set();
  const counts = { rejected: 0, connected: 0, externalConnected: 0 };
  const server = http.createServer((request, response) => {
    if (!policy.allowsHttp(request.url)) {
      counts.rejected++;
      response.writeHead(403).end();
      return;
    }
    const url = new URL(request.url);
    const upstream = http.request(url, { method: request.method, headers: request.headers }, reply => {
      response.writeHead(reply.statusCode ?? 502, reply.headers);
      reply.pipe(response);
    });
    upstream.on('error', () => {
      if (!response.headersSent) response.writeHead(502);
      response.end();
    });
    request.pipe(upstream);
  });
  server.on('connect', (request, client, head) => {
    const authority = /^([a-z0-9.-]+):([0-9]+)$/iu.exec(request.url ?? '');
    if (!authority || !policy.allowsAuthority(authority[1], authority[2])) {
      counts.rejected++;
      client.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');
      return;
    }
    const upstream = net.connect(Number(authority[2]), authority[1], () => {
      counts.connected++;
      if (authority[1] !== '127.0.0.1') counts.externalConnected++;
      client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
      if (head.length) upstream.write(head);
      client.pipe(upstream);
      upstream.pipe(client);
    });
    sockets.add(upstream);
    upstream.on('close', () => sockets.delete(upstream));
    upstream.on('error', () => client.destroy());
    client.on('error', () => upstream.destroy());
    client.on('close', () => upstream.destroy());
  });
  server.on('connection', socket => {
    sockets.add(socket);
    socket.on('error', () => {});
    socket.on('close', () => sockets.delete(socket));
  });
  server.on('upgrade', (_request, socket) => {
    counts.rejected++;
    socket.destroy();
  });
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
  });
  const address = server.address();
  if (!address || typeof address === 'string') throw new Error('Browser proxy could not start.');
  return {
    server: `http://127.0.0.1:${address.port}`,
    counts,
    close: async () => {
      for (const socket of sockets) socket.destroy();
      await new Promise(resolve => server.close(resolve));
    },
  };
}

module.exports = { HostedPolicy, egressProxy, parse, returnUrl, staticAssets, renderAssets, controllerAsset, renderCheckNames, deniedReadNames, assetMatches };
