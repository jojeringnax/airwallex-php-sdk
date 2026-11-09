'use strict';

const { HostedPolicy, controllerAsset } = require('./hosted-policy.cjs');
const { parseInstrumentJson, object, identity } = require('./instrument-json.cjs');
const { hash } = require('./instrument-journal.cjs');

// Only this separate profile uses a longer immutable clock. The legacy policy is unchanged.
class InstrumentReadPolicy extends HostedPolicy {
  #deadline;
  #closed = false;
  #normalClosed = false;
  #parentClosed = false;
  #currencyClosed = false;
  constructor(initial, expected, deadline) {
    super(initial, null, expected, true, true, true, undefined, true, true);
    if (!Number.isFinite(deadline) || deadline <= performance.now() || !this.requestScopeVerified()) {
      throw new Error('Fresh Sandbox instrument scope is unavailable.');
    }
    this.#deadline = deadline;
  }
  #remaining() {
    return this.#closed || !this.requestScopeVerified() ? 0 : Math.max(0, this.#deadline - performance.now());
  }
  normalWindowRemaining() {
    return this.#normalClosed ? 0 : this.#remaining();
  }
  parentCountryWindowRemaining() {
    return this.#parentClosed ? 0 : this.#remaining();
  }
  currencySettingsWindowRemaining() {
    return this.#currencyClosed ? 0 : this.#remaining();
  }
  closeNormalWindow() {
    this.#normalClosed = true;
    super.closeNormalWindow();
  }
  closeParentCountryWindow() {
    this.#parentClosed = true;
    super.closeParentCountryWindow();
  }
  closeCurrencySettingsWindow() {
    this.#currencyClosed = true;
    super.closeCurrencySettingsWindow();
  }
  stop() {
    this.#closed = true;
    this.closeNormalWindow();
    this.closeParentCountryWindow();
    this.closeCurrencySettingsWindow();
  }
}

class InstrumentReads {
  #initial;
  #expected;
  #token;
  #counts = {};
  #payer;
  #options;
  #status;
  #statusAt = 0;
  #consentsEmpty = false;
  #customerMatched = false;
  #countries = new Set();
  #methodMatched = false;
  #financial = false;
  constructor(initial, expected, deadline) {
    this.#initial = initial;
    this.#expected = expected;
    this.base = new InstrumentReadPolicy(initial, expected, deadline);
    this.#token = new URL(initial).searchParams.get('s');
    if (!this.#token) throw new Error('Fresh Checkout capability is unavailable.');
    this.base.noteParentMainDocument(initial);
    this.base.noteCurrencyMainDocument(initial);
  }
  take(role, maximum) {
    const ordinal = this.#counts[role] ?? 0;
    if (ordinal >= maximum) throw new Error('Instrument read budget ended.');
    this.#counts[role] = ordinal + 1;
    return ordinal;
  }
  get initial() {
    return this.#initial;
  }
  get payer() {
    return this.#payer;
  }
  get options() {
    return this.#options;
  }
  get countries() {
    return this.#countries;
  }
  get token() {
    return this.#token;
  }
  financialStarted() {
    this.#financial = true;
  }
  bindFrame(source) {
    if (!this.base.claimRenderFrame(source)) throw new Error('Original Drop-in proof is unavailable.');
    const url = new URL(source),
      raw = new URLSearchParams(url.hash.slice('#/elements/dropin?'.length)).get('options');
    const options = parseInstrumentJson(Buffer.from(raw));
    if (
      !object(options) ||
      !identity(options.sessionId) ||
      !identity(options.customer_id) ||
      options.intent_id ||
      options.payment_consent ||
      options.checkout_id ||
      options.checkoutSessionId ||
      options.checkout_session_token ||
      options.platformConnectedAccount ||
      options.occAuthToken ||
      options.occOptions ||
      options.morIntentId ||
      options.taxRegion ||
      options.taxCalculationIdentifier ||
      options.occ ||
      options.mor ||
      options.mode !== 'recurring' ||
      options.currency !== 'EUR' ||
      options.showConfirmButton === false
    )
      throw new Error('Instrument branch is unsupported.');
    this.#options = options;
  }
  frameDelivered() {
    this.base.markRenderFrameDelivered();
  }
  ready(source) {
    return (
      this.base.normalWindowRemaining() > 0 &&
      this.base.normalSourceMatches(source) &&
      this.#options !== undefined &&
      this.#status === 'REQUESTED' &&
      performance.now() - this.#statusAt < 6000 &&
      this.#consentsEmpty &&
      this.#customerMatched &&
      this.#methodMatched
    );
  }
  claim(meta) {
    const { url, method, type, headers, sourceRole } = meta,
      body = meta.body.length ? meta.body : null;
    const parsed = new URL(url),
      main = sourceRole === 'main',
      bound = sourceRole === 'dropin';
    let role,
      outgoing,
      limit = 65536,
      expectedHash = null;
    if (main && this.base.claimPayerRequest(url, method, type, headers, body)) {
      role = 'payer';
      outgoing = { accept: headers.accept ?? 'application/json', authorization: headers.authorization };
    } else if (
      main &&
      method === 'GET' &&
      ['xhr', 'fetch'].includes(type) &&
      body === null &&
      parsed.origin === new URL(this.#initial).origin &&
      parsed.pathname === '/billing/payer-web-api/checkout/status' &&
      !parsed.search &&
      headers.authorization === 'Bearer ' + this.#token
    ) {
      role = 'checkout_status';
      outgoing = { accept: headers.accept ?? 'application/json', authorization: headers.authorization };
    } else if (bound && (outgoing = this.base.claimMethodConfig(url, method, type, headers, body)) !== null) {
      role = 'method_config';
      limit = 262144;
    } else if (
      (main || bound) &&
      (outgoing = this.base.claimParentCountry(url, method, type, headers, body, main)) !== null
    ) {
      role = 'parent_country';
    } else if (main && (outgoing = this.base.claimCurrencySettings(url, method, type, headers, body, main))) {
      role = 'currency_settings';
    } else if (bound) {
      const claim = this.base.claimNormalRead(url, method, type, headers, body, false, true);
      if (claim) {
        role = claim.role;
        outgoing = claim.headers;
      }
    }
    if (!role && method === 'GET' && meta.body.length === 0 && (main || bound)) {
      expectedHash = this.base.assetHash(url, method, type);
      if (expectedHash !== null) {
        role = 'pinned_asset';
        limit = 2097152;
        outgoing = { accept: '*/*' };
        if (
          this.base.isControllerTarget(url, method, type) &&
          !this.base.claimControllerRequest(url, method, type)
        ) {
          throw new Error('Controller cannot be replayed.');
        }
      }
    }
    if (!role) return null;
    const ordinal = this.take(role, role === 'pinned_asset' ? 100 : role === 'checkout_status' ? 32 : 1);
    return {
      role,
      ordinal,
      method,
      url,
      body: meta.body,
      headers: outgoing,
      responseLimit: limit,
      expectedHash,
      scope: hash(this.#initial),
      security: false
    };
  }
  validate(claim, response) {
    if (response.status !== 200) throw new Error('Read status is unsupported.');
    if (claim.expectedHash !== null) {
      if (hash(response.body) !== claim.expectedHash) throw new Error('Pinned source changed.');
      return {};
    }
    if (!/^application\/json(?:\s*;|$)/iu.test(response.headers['content-type'] ?? ''))
      throw new Error('Read media is unsupported.');
    const value = parseInstrumentJson(response.body, claim.role === 'method_config' ? 262144 : 65536);
    if (claim.role === 'payer') {
      const setup = value?.setupModeDetails;
      if (
        !object(value) ||
        value.mode !== 'SETUP' ||
        value.currency !== 'EUR' ||
        value.enableAutomaticTax === true ||
        !object(setup) ||
        !identity(setup.billingCustomerExternalId) ||
        setup.billingTransactionExternalId ||
        typeof setup.externalCustomerClientSecret !== 'string' ||
        setup.externalCustomerClientSecret.length === 0 ||
        setup.externalCustomerClientSecret.length > 8192 ||
        setup.externalTransactionClientSecret
      ) {
        throw new Error('Payer branch is unsupported.');
      }
      if (this.base.observePayer(response.body).payer_observation !== 'matched')
        throw new Error('Payer is unbound.');
      this.#payer = value;
      const country = value.billingCustomerData?.address?.countryCode;
      if (typeof country === 'string' && /^[A-Z]{2}$/u.test(country)) this.#countries.add(country);
      return { payments_customer_id: setup.billingCustomerExternalId };
    }
    if (claim.role === 'method_config') {
      const result = this.base.observeMethodConfig(response.body);
      if (!this.base.mayDeliverMethodConfig() || result.method_config_card_advertised !== true) {
        throw new Error('Method inventory is unbound.');
      }
      this.#methodMatched = true;
      return {};
    }
    if (claim.role === 'checkout_status') {
      if (!object(value) || !['REQUESTED', 'PENDING', 'COMPLETED', 'EXPIRED'].includes(value.status)) {
        throw new Error('Checkout state is unbound.');
      }
      if (value.status === 'EXPIRED') {
        this.base.stop();
        throw new Error('Owned Checkout expired.');
      }
      if (value.status !== 'REQUESTED' && !this.#financial) {
        this.base.stop();
        throw new Error('Checkout transition has no original financial opportunity.');
      }
      this.#status = value.status;
      return {};
    }
    if (claim.role === 'parent_country') {
      if (!this.base.observeParentCountry(response.body)) throw new Error('Country is unbound.');
    } else if (claim.role === 'currency_settings') {
      if (!['matched', 'source_fallback'].includes(this.base.observeCurrencySettings(response.body))) {
        throw new Error('Currency settings are unbound.');
      }
    } else {
      const result = this.base.observeNormalRead(claim.role, response.body);
      if (!result.matched) throw new Error('Normal read is unbound.');
      if (claim.role === 'consent_list')
        this.#consentsEmpty = result.items === 0 && result.has_more === false && result.pagination_known;
      if (claim.role === 'customer') this.#customerMatched = true;
    }
    if (['country', 'parent_country'].includes(claim.role) && typeof value?.countryCode === 'string')
      this.#countries.add(value.countryCode);
    return {};
  }
  delivered(claim) {
    if (claim.role === 'payer') this.base.markPayerDelivered();
    if (claim.role === 'checkout_status') this.#statusAt = performance.now();
    if (['customer', 'consent_list', 'country'].includes(claim.role))
      this.base.markNormalReadDelivered(claim.role);
  }
  authority(host) {
    return (
      host === new URL(this.#initial).hostname ||
      ['www.sandbox.airwallex.com', controllerAsset.origin.slice(8)].includes(host)
    );
  }
  toJSON() {
    throw new Error('Private instrument reads cannot be serialized.');
  }
}

module.exports = { InstrumentReads, InstrumentReadPolicy };
