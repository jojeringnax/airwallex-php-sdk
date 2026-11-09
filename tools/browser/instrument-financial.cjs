'use strict';

const {
  parseInstrumentJson: parse,
  keys,
  object,
  identity,
  uuid,
  integer,
  zero,
  decimal
} = require('./instrument-json.cjs');
const { hash } = require('./instrument-journal.cjs');

const card = Object.freeze({
  number: '4035501000000008',
  expiry_month: '03',
  expiry_year: '2030',
  cvc: '737'
});
const standard = new Set([
  'accept',
  'accept-language',
  'accept-encoding',
  'user-agent',
  'origin',
  'referer',
  'cookie',
  'content-length',
  'host',
  'connection',
  'priority',
  'sec-ch-ua',
  'sec-ch-ua-mobile',
  'sec-ch-ua-platform',
  'sec-fetch-site',
  'sec-fetch-mode',
  'sec-fetch-dest',
  'cache-control',
  'pragma'
]);

function jsonMedia(response) {
  return /^application\/json(?:\s*;|$)/iu.test(response.headers['content-type'] ?? '');
}
function methodId(value) {
  const method = value.payment_method;
  if (method === undefined || method === null) return null;
  if (!object(method) || !identity(method.id) || method.type !== 'card')
    throw new Error('Card Method proof is unavailable.');
  return method.id;
}
function agreement(value, expected) {
  return (
    value.next_triggered_by === expected.next_triggered_by &&
    (value.merchant_trigger_reason ?? null) === (expected.merchant_trigger_reason ?? null)
  );
}

class InstrumentFinancial {
  #reads;
  #services;
  #buyer;
  #guard;
  #facts;
  #merchant;
  #captchaSupported;
  #inputStarted = false;
  #input = false;
  #submitted = false;
  #counts = {};
  #pendingConsent;
  #consent;
  #method;
  #intent;
  #verified = false;
  #validated = new WeakMap();
  #captcha = false;
  #captchaGrant = false;
  #pendingCaptcha;
  #intentMatched = false;
  #complete = false;
  constructor(reads, services, buyerEmail, guard, facts) {
    if (
      typeof buyerEmail !== 'string' ||
      !/^[a-z0-9-]+@example\.test$/u.test(buyerEmail) ||
      typeof guard !== 'function' ||
      !object(facts)
    )
      throw new Error('Instrument input scope is unavailable.');
    this.#reads = reads;
    this.#services = services;
    this.#buyer = buyerEmail;
    this.#guard = guard;
    this.#facts = facts;
    const token = reads.options?.client_secret;
    if (typeof token !== 'string' || token.split('.').length !== 3)
      throw new Error('Browser context is unsupported.');
    const context = parse(Buffer.from(token.split('.')[1], 'base64url'));
    if (
      !object(context) ||
      context.intent_id ||
      (context.account_id !== undefined && !identity(context.account_id)) ||
      (context.customer_id !== undefined && context.customer_id !== reads.options.customer_id)
    ) {
      throw new Error('Browser context is unbound.');
    }
    this.#merchant = context.account_id ?? '';
    if (
      (reads.options.recurringOptions?.next_triggered_by ?? 'customer') !== 'customer' ||
      reads.options.recurringOptions?.merchant_trigger_reason
    )
      throw new Error('This profile requires the source customer-triggered branch.');
    this.#captchaSupported =
      context.captcha_policy === undefined ||
      (object(context.captcha_policy) &&
        (context.captcha_policy.required === undefined || context.captcha_policy.required === false));
  }
  canInput(source) {
    return (
      this.#captchaSupported &&
      this.#guard() &&
      this.#reads.ready(source) &&
      this.#services.bootstrapReady() &&
      !this.#inputStarted
    );
  }
  beginInput(source) {
    if (!this.canInput(source)) throw new Error('Input cannot be repeated.');
    this.#inputStarted = true;
  }
  inputDone() {
    if (!this.#guard() || !this.#inputStarted || this.#input) throw new Error('Input scope ended.');
    this.#input = true;
  }
  canSubmit(source) {
    return this.#input && !this.#submitted && this.#guard() && this.#reads.ready(source);
  }
  submitted(source) {
    if (!this.canSubmit(source)) throw new Error('Submit scope is unavailable.');
    this.#submitted = true;
  }
  get captchaReady() {
    return this.#captcha;
  }
  get complete() {
    return this.#complete;
  }
  #headers(meta, role) {
    const extra = new Set([
      'content-type',
      'client-secret',
      'x-api-version',
      'x-client-ip-source',
      'awx-tracker',
      'x-device-type',
      'x-cloud-trace-context'
    ]);
    let bytes = 0;
    for (const [name, value] of Object.entries(meta.headers)) {
      if (
        (!standard.has(name) && !extra.has(name) && !(role === 'complete' || role === 'collect')) ||
        name === 'baggage' ||
        typeof value !== 'string' ||
        /[^\x20-\x7e]/u.test(value) ||
        value.length > 8192 ||
        (bytes += Buffer.byteLength(name + value)) > 16384
      )
        throw new Error('Financial headers are unsupported.');
    }
    const result = { accept: meta.headers.accept ?? 'application/json', 'content-type': 'application/json' };
    if (role === 'complete' || role === 'collect') {
      if (
        meta.headers.authorization !== 'Bearer ' + this.#reads.token ||
        Object.keys(meta.headers).some(
          (name) => !standard.has(name) && !['authorization', 'content-type'].includes(name)
        )
      ) {
        throw new Error('Checkout capability is unbound.');
      }
      result.authorization = meta.headers.authorization;
    } else {
      if (
        meta.headers['client-secret'] !== this.#reads.options.client_secret ||
        meta.headers['x-api-version'] !== (role === 'captcha_submit' ? '2021-11-25' : '2024-06-14') ||
        meta.headers['x-client-ip-source'] !== 'awx-checkout' ||
        (role === 'captcha_submit'
          ? Object.hasOwn(meta.headers, 'x-cloud-trace-context')
          : !/^[a-f0-9]{32}$/u.test(meta.headers['x-cloud-trace-context'] ?? ''))
      )
        throw new Error('Browser PA context is unbound.');
      for (const name of [
        'client-secret',
        'x-api-version',
        'x-client-ip-source',
        'x-cloud-trace-context',
        'awx-tracker',
        'x-device-type'
      ]) {
        if (meta.headers[name] !== undefined) result[name] = meta.headers[name];
      }
      if (result['awx-tracker'] !== undefined && !uuid(result['awx-tracker']))
        throw new Error('Browser tracker is unsupported.');
      if (result['x-device-type'] !== undefined && !['desktop', 'mobile'].includes(result['x-device-type']))
        throw new Error('Browser device is unsupported.');
    }
    if (meta.headers['content-type'] !== 'application/json')
      throw new Error('Financial media is unsupported.');
    if (meta.headers.origin !== undefined) {
      if (meta.headers.origin !== new URL(this.#reads.initial).origin)
        throw new Error('Financial Origin changed.');
      result.origin = meta.headers.origin;
    }
    return result;
  }
  #billing(value) {
    if (
      !keys(
        value,
        ['first_name', 'last_name', 'email', 'phone_number', 'address'],
        ['first_name', 'last_name']
      ) ||
      value.first_name !== 'SDK' ||
      value.last_name !== 'Sandbox Buyer' ||
      (value.email !== undefined && value.email !== '' && value.email !== this.#buyer) ||
      (value.phone_number !== undefined && value.phone_number !== '')
    )
      return false;
    if (value.address !== undefined) {
      if (
        !keys(value.address, ['country_code', 'city', 'street', 'state', 'postcode'], ['country_code']) ||
        !this.#reads.countries.has(value.address.country_code) ||
        Object.entries(value.address).some(([key, item]) => key !== 'country_code' && item !== '')
      )
        return false;
    }
    return true;
  }
  #device(value, meta) {
    return (
      keys(
        value,
        [
          'device_id',
          'screen_height',
          'screen_width',
          'screen_color_depth',
          'language',
          'timezone',
          'browser'
        ],
        [
          'device_id',
          'screen_height',
          'screen_width',
          'screen_color_depth',
          'language',
          'timezone',
          'browser'
        ]
      ) &&
      value.device_id === this.#services.session &&
      ['screen_height', 'screen_width', 'screen_color_depth'].every((key) =>
        integer(value[key], this.#facts[key], this.#facts[key])
      ) &&
      value.language === this.#facts.language &&
      value.timezone === this.#facts.timezone &&
      keys(
        value.browser,
        ['java_enabled', 'javascript_enabled', 'user_agent'],
        ['java_enabled', 'javascript_enabled', 'user_agent']
      ) &&
      value.browser.java_enabled === this.#facts.java_enabled &&
      value.browser.javascript_enabled === true &&
      value.browser.user_agent === meta.headers['user-agent'] &&
      value.browser.user_agent === this.#facts.user_agent
    );
  }
  #captchaBody(value) {
    return (
      keys(
        value,
        ['provider', 'token', 'extra', 'interactive', 'action'],
        ['provider', 'token', 'extra', 'interactive', 'action']
      ) &&
      value.provider === 'google' &&
      typeof value.token === 'string' &&
      value.token.length > 0 &&
      value.token.length <= 8192 &&
      value.token !== this.#services.session &&
      value.interactive === false &&
      value.action === 'VERIFY_CONSENT' &&
      keys(value.extra, ['tencent_captcha_random_str'], ['tencent_captcha_random_str']) &&
      value.extra.tencent_captcha_random_str === ''
    );
  }
  #return(value, headers) {
    if (typeof value !== 'string' || value.length > 16384) return false;
    try {
      const url = new URL(value),
        origin = new URL(this.#reads.initial).origin;
      return (
        url.origin === origin &&
        url.pathname === '/page/3ds-callback' &&
        !url.hash &&
        !url.username &&
        !url.password &&
        [...url.searchParams.keys()].every(
          (key) =>
            ['client_secret', 'componentName', 'checkoutOrigin', 'awxTracker', 'loadingColor'].includes(
              key
            ) && url.searchParams.getAll(key).length === 1
        ) &&
        url.searchParams.get('client_secret') === this.#reads.options.client_secret &&
        url.searchParams.get('componentName') === 'dropIn' &&
        url.searchParams.get('checkoutOrigin') === origin &&
        url.searchParams.get('awxTracker') === headers['awx-tracker'] &&
        (!url.searchParams.has('loadingColor') ||
          url.searchParams.get('loadingColor') === this.#facts.loadingColor)
      );
    } catch {
      return false;
    }
  }
  #verify(value, meta) {
    const options = this.#reads.options.recurringOptions;
    const allowed = [
      'payment_method',
      'device_data',
      'verification_options',
      'integration_data',
      'return_url',
      'captcha_data',
      'request_id',
      'descriptor'
    ];
    if (
      !keys(
        value,
        allowed,
        allowed.filter((key) => key !== 'descriptor')
      ) ||
      !uuid(value.request_id) ||
      (value.descriptor !== undefined &&
        (typeof options?.descriptor !== 'string' || value.descriptor !== options.descriptor)) ||
      !keys(value.payment_method, ['type', 'card'], ['type', 'card']) ||
      value.payment_method.type !== 'card' ||
      !keys(value.payment_method.card, [...Object.keys(card), 'name', 'billing'], Object.keys(card)) ||
      Object.entries(card).some(([key, item]) => value.payment_method.card[key] !== item) ||
      (value.payment_method.card.name !== undefined &&
        value.payment_method.card.name !== 'SDK Sandbox Buyer') ||
      (value.payment_method.card.billing !== undefined &&
        !this.#billing(value.payment_method.card.billing)) ||
      !keys(value.verification_options, ['card'], ['card']) ||
      !keys(value.verification_options.card, ['currency']) ||
      (value.verification_options.card.currency !== undefined &&
        value.verification_options.card.currency !== 'EUR') ||
      !keys(value.integration_data, ['type', 'origin'], ['type', 'origin']) ||
      value.integration_data.type !== 'dropIn' ||
      value.integration_data.origin !== new URL(this.#reads.initial).origin ||
      !this.#device(value.device_data, meta) ||
      !this.#captchaBody(value.captcha_data) ||
      !this.#return(value.return_url, meta.headers)
    )
      return false;
    return true;
  }
  claim(meta) {
    if (!this.#guard() || meta.body.length > 65536) throw new Error('Financial scope is closed.');
    const url = new URL(meta.url),
      origin = new URL(this.#reads.initial).origin;
    if (url.href !== meta.url || url.search || url.hash || url.username || url.password || url.port)
      return null;
    const main = meta.sourceRole === 'main',
      bound = meta.sourceRole === 'dropin';
    let role;
    if (main && url.origin === origin && meta.method === 'POST') {
      if (url.pathname === '/billing/payer-web-api/checkout/collect') role = 'collect';
      if (url.pathname === '/billing/payer-web-api/checkout/complete') role = 'complete';
    } else if (bound && this.#submitted && url.origin === origin) {
      if (meta.method === 'POST' && url.pathname === '/api/v1/pa/payment_consents/create')
        role = 'consent_create';
      if (
        meta.method === 'POST' &&
        this.#consent &&
        url.pathname === '/api/v1/pa/payment_consents/' + this.#consent + '/verify'
      )
        role = 'consent_verify';
      if (
        meta.method === 'GET' &&
        this.#intent &&
        url.pathname === '/api/v1/pa/payment_intents/' + this.#intent
      )
        role = 'verification_read';
    } else if (
      bound &&
      this.#consent &&
      url.origin === 'https://www.sandbox.airwallex.com' &&
      url.pathname === '/pa/risk/captcha/submit_token'
    ) {
      if (meta.method === 'POST') role = 'captcha_submit';
      if (meta.method === 'OPTIONS') {
        const names = (meta.headers['access-control-request-headers'] ?? '')
          .split(',')
          .map((name) => name.trim().toLowerCase());
        const allowed = [
          'client-secret',
          'content-type',
          'x-api-version',
          'x-client-ip-source',
          'awx-tracker',
          'x-device-type'
        ];
        if (
          this.#captchaGrant ||
          meta.body.length ||
          meta.headers.cookie ||
          meta.headers.authorization ||
          meta.headers.origin !== origin ||
          meta.headers['access-control-request-method'] !== 'POST' ||
          new Set(names).size !== names.length ||
          names.some((name) => !allowed.includes(name)) ||
          !['client-secret', 'content-type', 'x-api-version', 'x-client-ip-source'].every((name) =>
            names.includes(name)
          )
        ) {
          throw new Error('Natural captcha preflight is unsupported.');
        }
        return {
          role: 'captcha_preflight',
          ordinal: 0,
          method: 'OPTIONS',
          url: meta.url,
          body: Buffer.alloc(0),
          headers: {
            accept: '*/*',
            origin,
            'access-control-request-method': 'POST',
            'access-control-request-headers': names.sort().join(',')
          },
          scope: hash(this.#reads.initial),
          responseLimit: 65536,
          security: false
        };
      }
    }
    if (!role) return null;
    const value = meta.method === 'POST' ? parse(meta.body) : null,
      maximum = role === 'verification_read' ? 7 : 1;
    if (role === 'collect') {
      if (
        this.#inputStarted ||
        this.#submitted ||
        !keys(value, ['billingCustomerData'], ['billingCustomerData']) ||
        !keys(value.billingCustomerData, ['name', 'email', 'type', 'address']) ||
        Object.entries(value.billingCustomerData).some(([key, item]) =>
          key === 'name'
            ? item !== 'SDK Sandbox Buyer'
            : key === 'email'
              ? item !== this.#buyer
              : key === 'type'
                ? item !== 'INDIVIDUAL'
                : !keys(item, ['countryCode'], ['countryCode']) ||
                  !this.#reads.countries.has(item.countryCode)
        )
      ) {
        throw new Error('Final buyer collection is unsupported.');
      }
    } else if (role === 'consent_create') {
      if (
        !this.#input ||
        !keys(
          value,
          [
            'customer_id',
            'next_triggered_by',
            'request_id',
            'merchant_trigger_reason',
            'currency',
            'metadata'
          ],
          ['customer_id', 'next_triggered_by', 'request_id']
        ) ||
        value.customer_id !== this.#reads.options.customer_id ||
        !uuid(value.request_id) ||
        value.next_triggered_by !== 'customer' ||
        value.merchant_trigger_reason !== undefined ||
        (value.currency !== undefined && value.currency !== 'EUR') ||
        (value.metadata !== undefined && !keys(value.metadata, []))
      )
        throw new Error('Consent creation wire is unsupported.');
      this.#pendingConsent = value;
    } else if (role === 'captcha_submit') {
      if (
        !keys(
          value,
          [
            'captcha_token',
            'tencent_captcha_random_str',
            'vendor_name',
            'interactive',
            'action',
            'device_id',
            'uri_protecting',
            'pa_context'
          ],
          [
            'captcha_token',
            'vendor_name',
            'interactive',
            'action',
            'device_id',
            'uri_protecting',
            'pa_context'
          ]
        ) ||
        typeof value.captcha_token !== 'string' ||
        value.captcha_token.length === 0 ||
        value.captcha_token.length > 8192 ||
        value.vendor_name !== 'google' ||
        value.interactive !== false ||
        value.action !== 'VERIFY_CONSENT' ||
        value.device_id !== this.#services.session ||
        value.uri_protecting !== '/payment_consents/' + this.#consent + '/verify' ||
        (value.tencent_captcha_random_str !== undefined && value.tencent_captcha_random_str !== '') ||
        !keys(
          value.pa_context,
          ['request_id', 'merchant_id', 'consent_id', 'extra_data'],
          ['request_id', 'merchant_id', 'consent_id', 'extra_data']
        ) ||
        !uuid(value.pa_context.request_id) ||
        value.pa_context.consent_id !== this.#consent ||
        value.pa_context.merchant_id !== this.#merchant ||
        !keys(value.pa_context.extra_data, [])
      )
        throw new Error('Captcha context is unsupported.');
      this.#pendingCaptcha = value;
    } else if (role === 'consent_verify') {
      if (
        !this.#captcha ||
        !this.#verify(value, meta) ||
        value.captcha_data.token !== this.#pendingCaptcha.captcha_token
      ) {
        throw new Error('Consent verification boundary is unsupported.');
      }
    } else if (role === 'verification_read') {
      if (meta.body.length || !this.#verified) throw new Error('Verification observation is unsupported.');
    } else if (role === 'complete') {
      if (
        !this.#verified ||
        !this.#intentMatched ||
        !keys(
          value,
          ['paymentSourceExternalId', 'paymentMethodType'],
          ['paymentSourceExternalId', 'paymentMethodType']
        ) ||
        value.paymentSourceExternalId !== this.#consent ||
        value.paymentMethodType !== 'card'
      )
        throw new Error('Completion is unbound.');
    }
    const ordinal = this.#counts[role] ?? 0;
    if (ordinal >= maximum) throw new Error('Financial action cannot be replayed.');
    const headers = this.#headers(meta, role);
    this.#counts[role] = ordinal + 1;
    return {
      role,
      ordinal,
      method: meta.method,
      url: meta.url,
      body: meta.body,
      headers,
      requestId: role === 'captcha_submit' ? value.pa_context.request_id : value?.request_id,
      scope: hash(this.#reads.initial),
      responseLimit: 65536,
      security: false
    };
  }
  preflightFor(claim) {
    if (claim.role !== 'captcha_submit' || this.#captchaGrant) return null;
    const names = Object.keys(claim.headers)
      .filter((name) => !['accept', 'origin'].includes(name))
      .sort();
    return {
      role: 'captcha_preflight',
      ordinal: 0,
      method: 'OPTIONS',
      url: claim.url,
      body: Buffer.alloc(0),
      headers: {
        accept: '*/*',
        origin: new URL(this.#reads.initial).origin,
        'access-control-request-method': 'POST',
        'access-control-request-headers': names.join(',')
      },
      scope: claim.scope,
      responseLimit: 65536,
      security: false
    };
  }
  validate(claim, response) {
    const identities = Object.freeze(this.#validate(claim, response));
    this.#validated.set(claim, identities);
    return identities;
  }
  #validate(claim, response) {
    if (claim.role === 'captcha_preflight') {
      const headers = response.headers,
        names = (headers['access-control-allow-headers'] ?? '')
          .toLowerCase()
          .split(',')
          .map((item) => item.trim());
      if (
        !Number.isInteger(response.status) ||
        response.status < 200 ||
        response.status > 299 ||
        ![new URL(this.#reads.initial).origin, '*'].includes(headers['access-control-allow-origin']) ||
        !(headers['access-control-allow-methods'] ?? '')
          .split(',')
          .map((item) => item.trim())
          .includes('POST') ||
        !claim.headers['access-control-request-headers'].split(',').every((name) => names.includes(name))
      )
        throw new Error('Captcha CORS is unbound.');
      return {};
    }
    if (claim.role === 'captcha_submit' && !this.#captchaGrant)
      throw new Error('Captcha CORS was not established.');
    if (claim.role === 'captcha_submit' && response.status === 200 && response.body.length === 0) return {};
    if (
      !jsonMedia(response) ||
      ![200, ...(claim.role === 'consent_create' ? [201] : [])].includes(response.status)
    ) {
      throw new Error('Financial response status/media is unsupported.');
    }
    const value = parse(response.body);
    if (!object(value)) throw new Error('Financial response is not an object.');
    if (claim.role === 'consent_create' || claim.role === 'consent_verify') {
      if (
        !identity(value.id) ||
        value.customer_id !== this.#reads.options.customer_id ||
        !agreement(value, this.#pendingConsent) ||
        (this.#consent && value.id !== this.#consent)
      ) {
        throw new Error('Consent ownership is unbound.');
      }
      const identities = { consent_id: value.id, payments_customer_id: value.customer_id };
      const method = methodId(value);
      if (this.#method && method && this.#method !== method) throw new Error('Method identity changed.');
      if (method) identities.method_id = method;
      if (value.initial_payment_intent_id !== undefined && value.initial_payment_intent_id !== null) {
        if (!identity(value.initial_payment_intent_id)) throw new Error('Verification Intent is unbound.');
        if (this.#intent && value.initial_payment_intent_id !== this.#intent)
          throw new Error('Verification Intent changed.');
        identities.initial_intent_id = value.initial_payment_intent_id;
      }
      if (
        claim.role === 'consent_verify' &&
        (value.status !== 'VERIFIED' || value.next_action || !method || !identities.initial_intent_id)
      )
        throw new Error('Verification has no terminal instrument proof.');
      if (
        claim.role === 'consent_create' &&
        (value.next_action || !['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION'].includes(value.status))
      ) {
        throw new Error('New Consent state is unsupported.');
      }
      return identities;
    }
    if (claim.role === 'verification_read') {
      if (
        value.id !== this.#intent ||
        value.customer_id !== this.#reads.options.customer_id ||
        value.status !== 'SUCCEEDED' ||
        !decimal(value.amount) ||
        !zero(value.captured_amount) ||
        (value.payment_consent_id !== undefined && value.payment_consent_id !== this.#consent) ||
        (value.payment_method_id !== undefined && value.payment_method_id !== this.#method) ||
        typeof value.currency !== 'string' ||
        !/^[A-Z]{3}$/u.test(value.currency)
      )
        throw new Error('Verification state is unbound.');
      if (value.latest_payment_attempt !== undefined && value.latest_payment_attempt !== null) {
        const attempt = value.latest_payment_attempt;
        if (
          !object(attempt) ||
          (attempt.payment_intent_id !== undefined && attempt.payment_intent_id !== this.#intent) ||
          (attempt.customer_id !== undefined && attempt.customer_id !== this.#reads.options.customer_id) ||
          (attempt.payment_consent_id !== undefined && attempt.payment_consent_id !== this.#consent) ||
          (attempt.payment_method !== undefined &&
            (!object(attempt.payment_method) ||
              (attempt.payment_method.id !== undefined && attempt.payment_method.id !== this.#method) ||
              (attempt.payment_method.type !== undefined && attempt.payment_method.type !== 'card') ||
              (attempt.payment_method.customer_id !== undefined &&
                attempt.payment_method.customer_id !== this.#reads.options.customer_id)))
        )
          throw new Error('Verification attempt relationship changed.');
      }
      if (
        value.payment_consent !== undefined &&
        value.payment_consent !== null &&
        (!object(value.payment_consent) ||
          value.payment_consent.next_triggered_by !== 'customer' ||
          value.payment_consent.merchant_trigger_reason != null ||
          (value.payment_consent.terms_of_use != null && !object(value.payment_consent.terms_of_use)))
      )
        throw new Error('Verification agreement options changed.');
      return {};
    }
    if (claim.role === 'complete' || claim.role === 'collect') {
      if (
        value.mode !== 'SETUP' ||
        value.currency !== 'EUR' ||
        value.setupModeDetails?.billingCustomerExternalId !== this.#reads.options.customer_id
      ) {
        throw new Error('Payer acknowledgement is unbound.');
      }
      const original = this.#reads.payer;
      if (original) {
        for (const key of [
          'id',
          'checkoutId',
          'billingCustomerId',
          'billingEntityId',
          'legalEntityId',
          'linkedPaymentAccountId',
          'sellerId'
        ]) {
          if (value[key] !== original[key]) throw new Error('Original payer context changed.');
        }
        for (const key of [
          'externalCustomerClientSecret',
          'billingTransactionExternalId',
          'externalTransactionClientSecret'
        ]) {
          if (value.setupModeDetails?.[key] !== original.setupModeDetails?.[key])
            throw new Error('Original payer browser context changed.');
        }
        if (value.enableAutomaticTax === true) throw new Error('Unsupported payer tax branch.');
      }
      return { payments_customer_id: this.#reads.options.customer_id };
    }
    return {};
  }
  durable(claim, identities) {
    if (this.#validated.get(claim) !== identities) throw new Error('Financial proof was not validated.');
    this.#validated.delete(claim);
    if (claim.role === 'consent_create') {
      this.#consent = identities.consent_id;
      this.#method = identities.method_id;
      this.#intent = identities.initial_intent_id;
    }
    if (claim.role === 'captcha_preflight') this.#captchaGrant = true;
    if (claim.role === 'captcha_submit') this.#captcha = true;
    if (claim.role === 'consent_verify') {
      this.#method = identities.method_id;
      this.#intent = identities.initial_intent_id;
      this.#verified = true;
    }
    if (claim.role === 'verification_read') this.#intentMatched = true;
    if (claim.role === 'complete') this.#complete = true;
  }
  candidates(claim, response) {
    try {
      if (!['consent_create', 'consent_verify'].includes(claim.role)) return {};
      const value = parse(response.body),
        result = {};
      if (identity(value?.id)) result.consent_id = value.id;
      if (identity(value?.customer_id)) result.payments_customer_id = value.customer_id;
      if (identity(value?.payment_method?.id)) result.method_id = value.payment_method.id;
      if (identity(value?.initial_payment_intent_id))
        result.initial_intent_id = value.initial_payment_intent_id;
      return result;
    } catch {
      return {};
    }
  }
  toJSON() {
    throw new Error('Private financial policy cannot be serialized.');
  }
}

module.exports = { InstrumentFinancial, card };
