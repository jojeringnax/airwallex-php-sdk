'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { InstrumentFinancial, card } = require('./instrument-financial.cjs');

const origin = 'https://checkout.sandbox.airwallex.com',
  customer = 'cus_synthetic',
  consent = 'cst_synthetic';
const ids = [
  '11111111-1111-4111-8111-111111111111',
  '22222222-2222-4222-8222-222222222222',
  '33333333-3333-4333-8333-333333333333',
  '44444444-4444-4444-8444-444444444444'
];
const facts = {
  screen_height: 800,
  screen_width: 1280,
  screen_color_depth: 24,
  language: 'en-US',
  timezone: '+0',
  java_enabled: false,
  user_agent: 'Synthetic Chrome',
  loadingColor: 'rgb(0, 0, 0)'
};
function fixture(required = false) {
  const secret =
    'e30.' +
    Buffer.from(
      JSON.stringify({
        account_id: 'merchant_synthetic',
        customer_id: customer,
        captcha_policy: { required }
      })
    ).toString('base64url') +
    '.eA';
  let live = true;
  const reads = {
    initial: origin + '/pay?s=SYNTHETIC_CAPABILITY',
    token: 'SYNTHETIC_CAPABILITY',
    options: { client_secret: secret, customer_id: customer },
    countries: new Set(['IT']),
    ready: () => true
  };
  const services = { session: 'SYNTHETIC_SESSION', bootstrapReady: () => true };
  const policy = new InstrumentFinancial(reads, services, 'synthetic@example.test', () => live, facts);
  const headers = (captcha = false) => ({
    'content-type': 'application/json',
    'client-secret': secret,
    'x-api-version': captcha ? '2021-11-25' : '2024-06-14',
    'x-client-ip-source': 'awx-checkout',
    'awx-tracker': ids[3],
    'user-agent': facts.user_agent,
    ...(captcha ? {} : { 'x-cloud-trace-context': 'a'.repeat(32) })
  });
  const request = (pathname, body, role = 'dropin', captcha = false, method = 'POST') => ({
    url: (captcha ? 'https://www.sandbox.airwallex.com' : origin) + pathname,
    method,
    type: method === 'GET' ? 'fetch' : 'xhr',
    sourceRole: role,
    headers:
      role === 'main'
        ? { 'content-type': 'application/json', authorization: 'Bearer SYNTHETIC_CAPABILITY' }
        : headers(captcha),
    body: body === null ? Buffer.alloc(0) : Buffer.from(JSON.stringify(body))
  });
  return {
    policy,
    reads,
    secret,
    request,
    headers,
    close: () => {
      live = false;
    }
  };
}
function response(body, status = 200, headers = {}) {
  return {
    status,
    headers: { 'content-type': 'application/json', ...headers },
    body: body === null ? Buffer.alloc(0) : Buffer.isBuffer(body) ? body : Buffer.from(JSON.stringify(body))
  };
}
function inputAndClick(f) {
  assert.equal(f.policy.canInput('synthetic source'), true);
  f.policy.beginInput('synthetic source');
  f.policy.inputDone();
  f.policy.submitted('synthetic source');
}
function create(f) {
  const request = f.request('/api/v1/pa/payment_consents/create', {
    customer_id: customer,
    next_triggered_by: 'customer',
    request_id: ids[0]
  });
  const claim = f.policy.claim(request);
  const result = f.policy.validate(
    claim,
    response(
      {
        id: consent,
        customer_id: customer,
        next_triggered_by: 'customer',
        status: 'REQUIRES_PAYMENT_METHOD'
      },
      201
    )
  );
  f.policy.durable(claim, result);
  return claim;
}
function captcha(f) {
  const claim = f.policy.claim(
    f.request(
      '/pa/risk/captcha/submit_token',
      {
        captcha_token: 'SYNTHETIC_VENDOR_TOKEN',
        vendor_name: 'google',
        interactive: false,
        action: 'VERIFY_CONSENT',
        device_id: 'SYNTHETIC_SESSION',
        uri_protecting: '/payment_consents/' + consent + '/verify',
        pa_context: {
          request_id: ids[1],
          merchant_id: 'merchant_synthetic',
          consent_id: consent,
          extra_data: {}
        }
      },
      'dropin',
      true
    )
  );
  const preflight = f.policy.preflightFor(claim);
  const proof = f.policy.validate(
    preflight,
    response(null, 204, {
      'access-control-allow-origin': origin,
      'access-control-allow-methods': 'POST',
      'access-control-allow-headers': preflight.headers['access-control-request-headers']
    })
  );
  f.policy.durable(preflight, proof);
  const result = f.policy.validate(claim, response(null));
  f.policy.durable(claim, result);
  return claim;
}
function verifyBody(f) {
  return {
    request_id: ids[2],
    payment_method: {
      type: 'card',
      card: {
        ...card,
        name: 'SDK Sandbox Buyer',
        billing: {
          first_name: 'SDK',
          last_name: 'Sandbox Buyer',
          phone_number: '',
          email: '',
          address: { country_code: 'IT', city: '', street: '', state: '', postcode: '' }
        }
      }
    },
    device_data: {
      device_id: 'SYNTHETIC_SESSION',
      screen_height: 800,
      screen_width: 1280,
      screen_color_depth: 24,
      language: 'en-US',
      timezone: '+0',
      browser: {
        java_enabled: false,
        javascript_enabled: true,
        user_agent: facts.user_agent
      }
    },
    verification_options: { card: {} },
    integration_data: { type: 'dropIn', origin },
    return_url:
      origin +
      '/page/3ds-callback?' +
      new URLSearchParams({
        client_secret: f.secret,
        componentName: 'dropIn',
        checkoutOrigin: origin,
        awxTracker: ids[3],
        loadingColor: facts.loadingColor
      }),
    captcha_data: {
      provider: 'google',
      token: 'SYNTHETIC_VENDOR_TOKEN',
      extra: { tencent_captcha_random_str: '' },
      interactive: false,
      action: 'VERIFY_CONSENT'
    }
  };
}
function verified(f) {
  const body = verifyBody(f),
    claim = f.policy.claim(f.request('/api/v1/pa/payment_consents/' + consent + '/verify', body));
  const proof = f.policy.validate(
    claim,
    response({
      id: consent,
      customer_id: customer,
      next_triggered_by: 'customer',
      status: 'VERIFIED',
      payment_method: { id: 'pmt_synthetic', type: 'card' },
      initial_payment_intent_id: 'int_synthetic'
    })
  );
  f.policy.durable(claim, proof);
  return claim;
}

test('normal source create/captcha/verify/observe/complete preserves exact bytes and distinct source IDs', () => {
  const f = fixture();
  inputAndClick(f);
  const created = create(f),
    submittedCaptcha = captcha(f),
    verification = verified(f);
  assert.equal(created.requestId, ids[0]);
  assert.equal(submittedCaptcha.requestId, ids[1]);
  assert.equal(verification.requestId, ids[2]);
  assert.deepEqual(JSON.parse(verification.body).verification_options, { card: {} });
  const read = f.policy.claim(
    f.request('/api/v1/pa/payment_intents/int_synthetic', null, 'dropin', false, 'GET')
  );
  const result = f.policy.validate(
    read,
    response({
      id: 'int_synthetic',
      customer_id: customer,
      status: 'SUCCEEDED',
      amount: '0.01',
      captured_amount: '0.00',
      currency: 'EUR',
      payment_consent: { next_triggered_by: 'customer', terms_of_use: {} },
      latest_payment_attempt: { payment_method: { id: 'pmt_synthetic', customer_id: customer, type: 'card' } }
    })
  );
  f.policy.durable(read, result);
  const completion = f.policy.claim(
    f.request(
      '/billing/payer-web-api/checkout/complete',
      {
        paymentSourceExternalId: consent,
        paymentMethodType: 'card'
      },
      'main'
    )
  );
  assert.equal(completion.requestId, undefined);
  const proof = f.policy.validate(
    completion,
    response({ mode: 'SETUP', currency: 'EUR', setupModeDetails: { billingCustomerExternalId: customer } })
  );
  f.policy.durable(completion, proof);
  assert.equal(f.policy.complete, true);
  assert.throws(() => f.policy.durable(completion, proof));
  assert.throws(() =>
    f.policy.claim(
      f.request(
        '/billing/payer-web-api/checkout/complete',
        {
          paymentSourceExternalId: consent,
          paymentMethodType: 'card'
        },
        'main'
      )
    )
  );
});
test('required captcha, scope closure, repeated input and pre-click mutations cannot acquire financial authority', () => {
  const required = fixture(true);
  assert.equal(required.policy.canInput('source'), false);
  const f = fixture();
  assert.equal(
    f.policy.claim(
      f.request('/api/v1/pa/payment_consents/create', {
        customer_id: customer,
        next_triggered_by: 'customer',
        request_id: ids[0]
      })
    ),
    null
  );
  f.policy.beginInput('source');
  assert.throws(() => f.policy.beginInput('source'));
  f.policy.inputDone();
  assert.throws(() => f.policy.inputDone());
  f.close();
  assert.throws(() => f.policy.submitted('source'));
});
test('a response without a durable validated ticket cannot authorize dependent delivery', () => {
  const f = fixture();
  inputAndClick(f);
  const claim = f.policy.claim(
    f.request('/api/v1/pa/payment_consents/create', {
      customer_id: customer,
      next_triggered_by: 'customer',
      request_id: ids[0]
    })
  );
  assert.throws(() => f.policy.durable(claim, { consent_id: consent }));
  assert.equal(
    f.policy.claim(f.request('/api/v1/pa/payment_consents/' + consent + '/verify', verifyBody(f))),
    null
  );
});
test('source-generated empty billing placeholders pass, while changed inputs and unknown controls fail before send', () => {
  const mutations = [
    (body) => {
      body.payment_method.card.number = '4035501000000016';
    },
    (body) => {
      body.payment_method.card.expiry_year = '3030';
    },
    (body) => {
      body.payment_method.card.billing.phone_number = 'real-person';
    },
    (body) => {
      body.payment_method.card.billing.address.street = 'real-address';
    },
    (body) => {
      body.verification_options.card.three_ds_action = 'SKIP_3DS';
    },
    (body) => {
      body.skip_risk_processing = true;
    },
    (body) => {
      body.captcha_data.interactive = true;
    },
    (body) => {
      body.captcha_data.token = 'SYNTHETIC_SESSION';
    },
    (body) => {
      body.return_url = 'https://foreign.example/page/3ds-callback';
    },
    (body) => {
      body.device_data.device_id = 'OTHER_SESSION';
    }
  ];
  for (const mutation of mutations) {
    const f = fixture();
    inputAndClick(f);
    create(f);
    captcha(f);
    const body = verifyBody(f);
    mutation(body);
    assert.throws(() =>
      f.policy.claim(f.request('/api/v1/pa/payment_consents/' + consent + '/verify', body))
    );
  }
});
test('captcha source version and CORS are separate from modern Consent requests', () => {
  const f = fixture();
  inputAndClick(f);
  create(f);
  const request = f.request(
    '/pa/risk/captcha/submit_token',
    {
      captcha_token: 'SYNTHETIC_VENDOR_TOKEN',
      vendor_name: 'google',
      interactive: false,
      action: 'VERIFY_CONSENT',
      device_id: 'SYNTHETIC_SESSION',
      uri_protecting: '/payment_consents/' + consent + '/verify',
      pa_context: {
        request_id: ids[1],
        merchant_id: 'merchant_synthetic',
        consent_id: consent,
        extra_data: {}
      }
    },
    'dropin',
    true
  );
  assert.throws(() => f.policy.claim({ ...request, headers: { ...f.headers(false) } }));
  const claim = f.policy.claim(request);
  assert.throws(() => f.policy.validate(claim, response(null)));
  const preflight = f.policy.preflightFor(claim);
  assert.throws(() =>
    f.policy.validate(
      preflight,
      response(null, 204, {
        'access-control-allow-origin': 'https://foreign.example',
        'access-control-allow-methods': 'POST',
        'access-control-allow-headers': preflight.headers['access-control-request-headers']
      })
    )
  );
});
test('next action and ambiguous identities retain inventory without a usable verification ticket', () => {
  const f = fixture();
  inputAndClick(f);
  create(f);
  captcha(f);
  const claim = f.policy.claim(
    f.request('/api/v1/pa/payment_consents/' + consent + '/verify', verifyBody(f))
  );
  const next = response({
    id: consent,
    customer_id: customer,
    next_triggered_by: 'customer',
    status: 'VERIFIED',
    payment_method: { id: 'pmt_synthetic', type: 'card' },
    initial_payment_intent_id: 'int_synthetic',
    next_action: { type: 'redirect', url: 'https://interactive.example' }
  });
  assert.throws(() => f.policy.validate(claim, next));
  assert.deepEqual(f.policy.candidates(claim, next), {
    consent_id: consent,
    payments_customer_id: customer,
    method_id: 'pmt_synthetic',
    initial_intent_id: 'int_synthetic'
  });
  assert.deepEqual(f.policy.candidates(claim, response(Buffer.from('{"id":"one","\\u0069d":"two"}'))), {});
  assert.throws(() => f.policy.durable(claim, f.policy.candidates(claim, next)));
});
test('numeric underflow, foreign ownership, capture and invalid money cannot authorize completion', () => {
  for (const mutation of ['underflow', 'foreign', 'captured', 'missing_amount']) {
    const f = fixture();
    inputAndClick(f);
    create(f);
    captcha(f);
    verified(f);
    const claim = f.policy.claim(
      f.request('/api/v1/pa/payment_intents/int_synthetic', null, 'dropin', false, 'GET')
    );
    const body = {
      id: 'int_synthetic',
      customer_id: customer,
      status: 'SUCCEEDED',
      amount: '0',
      captured_amount: '0',
      currency: 'EUR'
    };
    if (mutation === 'foreign') body.customer_id = 'foreign';
    if (mutation === 'captured') body.captured_amount = '1.23';
    if (mutation === 'missing_amount') delete body.amount;
    const bytes =
      mutation === 'underflow'
        ? Buffer.from(JSON.stringify(body).replace('"captured_amount":"0"', '"captured_amount":1e-9999'))
        : body;
    assert.throws(() => f.policy.validate(claim, response(bytes)));
    assert.throws(() =>
      f.policy.claim(
        f.request(
          '/billing/payer-web-api/checkout/complete',
          {
            paymentSourceExternalId: consent,
            paymentMethodType: 'card'
          },
          'main'
        )
      )
    );
  }
});

test('documented latest-attempt Method and Consent echoes cannot contradict the durable identity chain', () => {
  for (const addition of [
    { latest_payment_attempt: { payment_method: { id: 'pmt_foreign', type: 'card' } } },
    { latest_payment_attempt: { payment_intent_id: 'int_foreign' } },
    { payment_consent: { id: 'cst_foreign' } }
  ]) {
    const f = fixture();
    inputAndClick(f);
    create(f);
    captcha(f);
    verified(f);
    const claim = f.policy.claim(
      f.request('/api/v1/pa/payment_intents/int_synthetic', null, 'dropin', false, 'GET')
    );
    assert.throws(() =>
      f.policy.validate(
        claim,
        response({
          id: 'int_synthetic',
          customer_id: customer,
          status: 'SUCCEEDED',
          amount: '0.01',
          captured_amount: '0.00',
          currency: 'EUR',
          ...addition
        })
      )
    );
  }
});
