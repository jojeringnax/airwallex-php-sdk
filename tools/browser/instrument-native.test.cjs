'use strict';

// Opt in with an absolute installed Playwright module. Every HTTP response is a local fixture;
// the runner's production proxy still rejects every real browser connection attempt.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { runInstrument, sourceBook } = require('./hosted-instrument-workflow.cjs');
const { InstrumentServices, googleKey } = require('./instrument-services.cjs');
const { clientKey } = require('./instrument-config.cjs');
const { hash } = require('./instrument-journal.cjs');

const tooling = process.env.AIRWALLEX_INSTRUMENT_FIXTURE_PLAYWRIGHT;
const origin = 'https://checkout.sandbox.airwallex.com';
const ids = [
  '11111111-1111-4111-8111-111111111111',
  '22222222-2222-4222-8222-222222222222',
  '33333333-3333-4333-8333-333333333333',
  '44444444-4444-4444-8444-444444444444'
];
const customer = 'cus_synthetic',
  consent = 'cst_synthetic',
  session = 'SYNTHETIC_SESSION';
const google = 'https://www.recaptcha.net',
  release = 'SYNTHETIC_RELEASE',
  releaseURL = 'https://www.gstatic.com/recaptcha/releases/' + release + '/recaptcha__en.js';
const secret =
  'e30.' +
  Buffer.from(
    JSON.stringify({
      account_id: 'merchant_synthetic',
      customer_id: customer,
      captcha_policy: { required: false }
    })
  ).toString('base64url') +
  '.eA';
const options = {
  client_secret: secret,
  customer_id: customer,
  mode: 'recurring',
  currency: 'EUR',
  sessionId: session
};
const source =
  origin +
  '/#/elements/dropin?' +
  new URLSearchParams({ appVersion: 'v2.56.0', lang: 'en', options: JSON.stringify(options) });
const json = (value) => JSON.stringify(value).replace(/</gu, '\\u003c');
const html = (script) =>
  '<!doctype html><html><head><meta charset="utf-8"></head><body><span>Test mode</span><script>' +
  script +
  '</script></body></html>';

function mainProgram(failure) {
  return `(async()=>{const sid='55555555-5555-4555-8555-555555555555';
    const body={user:{userID:'seller_synthetic',statsigEnvironment:{tier:'staging'},analyticsOnlyMetadata:{}},hash:'djb2',deltasResponseRequested:false,
      full_checksum:null,statsigMetadata:{sdkVersion:'3.20.3',sdkType:'javascript-client-react',stableID:'66666666-6666-4666-8666-666666666666',sessionID:sid,fallbackUrl:null}};
    await fetch('https://featureassets.org/v1/initialize?'+new URLSearchParams({k:${json(clientKey)},se:'1',sid,st:'javascript-client-react',sv:'3.20.3',t:String(Date.now())}),
      {method:'POST',body:btoa(JSON.stringify(body)).split('').reverse().join('')});
    const frame=document.createElement('iframe');frame.style='width:900px;height:900px;border:0';
    frame.id='synthetic-dropin';frame.src=${json(source)};
    frame.onload=()=>{frame.contentWindow.postMessage({code:'update',options:{appearance:{mode:'light',variables:{colorBrand:'#000000',colorText:'#000000',colorBackground:'#ffffff'}},
      layout:{type:'accordion',alwaysShowMethodLabel:true}}},location.origin);
      frame.contentWindow.postMessage({code:'update',options:{billing:{first_name:'SDK',last_name:'Sandbox Buyer',email:'synthetic@example.test',
        phone_number:'',address:{country_code:'IT',city:'',street:'',state:'',postcode:''}},shopper_name:'SDK Sandbox Buyer',
        shopper_email:'synthetic@example.test',disablePay:false,layout:{type:'accordion',alwaysShowMethodLabel:true}},initOptions:{locale:'en'}},location.origin);};
    document.body.append(frame);addEventListener('message',async e=>{
      if(e.data?.syntheticFinished===true){await fetch('/billing/payer-web-api/checkout/complete',{method:'POST',
        headers:{'content-type':'application/json',authorization:'Bearer SYNTHETIC_CAPABILITY'},
        body:JSON.stringify({paymentSourceExternalId:${json(consent)},paymentMethodType:'card'})});}
      if(e.data?.syntheticBeforeCreate===true&&${json(failure)}==='update')frame.contentWindow.postMessage({code:'update',options:{payment_consent:{}}},location.origin);
    });})();`;
}
function dropinProgram(failure) {
  return `document.body.innerHTML='<form action="#"><label>Name<input name="name"></label><br><label>Number<input name="cardnumber"></label><br>'+
    '<label>Expiry<input name="expiry"></label><br><label>CVC<input name="cvc"></label><br><button class="Button" type="button">Save</button></form>';
    const opts=${json(options)},ids=${json(ids)},secret=opts.client_secret;
    const googleScript=document.createElement('script');googleScript.src=${json(google + '/recaptcha/enterprise.js?render=' + googleKey + '&hl=en')};document.body.append(googleScript);
    const headers=(captcha=false)=>({'content-type':'application/json','client-secret':secret,'x-api-version':captcha?'2021-11-25':'2024-06-14',
      'x-client-ip-source':'awx-checkout','awx-tracker':ids[3],...(captcha?{}:{'x-cloud-trace-context':'a'.repeat(32)})});
    const post=async(url,body,captcha=false)=>{const response=await fetch(url,{method:'POST',headers:headers(captcha),body:JSON.stringify(body)});
      if(!response.ok)throw Error('synthetic response failed');return response;};
    if(${json(failure)}==='replaced_control')document.querySelector('[name=name]').addEventListener('input',()=>{
      const original=document.querySelector('[name=cardnumber]');original.replaceWith(original.cloneNode());});
    if(${json(failure)}==='reload')document.querySelector('[name=name]').addEventListener('input',()=>location.reload());
    document.querySelector('button').onclick=async()=>{
      const card={number:document.querySelector('[name=cardnumber]').value,expiry_month:'03',expiry_year:'2030',cvc:document.querySelector('[name=cvc]').value,
        name:document.querySelector('[name=name]').value,billing:{first_name:'SDK',last_name:'Sandbox Buyer',email:'',phone_number:'',
          address:{country_code:'IT',city:'',street:'',state:'',postcode:''}}};
      if(${json(failure)}==='changed_card')card.number='4035501000000016';
      if(${json(failure)}==='unknown_frame'){const unknown=document.createElement('iframe');document.body.append(unknown);}
      if(${json(failure)}==='interactive'){const challenge=document.createElement('iframe');challenge.src='https://www.recaptcha.net/recaptcha/enterprise/bframe';document.body.append(challenge);}
      if(${json(failure)}==='update'){parent.postMessage({syntheticBeforeCreate:true},location.origin);await new Promise(r=>setTimeout(r,50));}
      if(${json(failure)}==='unknown_service')await fetch('https://unknown.fixture.invalid/opaque');
      const created=await(await post('/api/v1/pa/payment_consents/create',{customer_id:opts.customer_id,next_triggered_by:'customer',request_id:ids[0]})).json();
      await post('https://www.sandbox.airwallex.com/pa/risk/captcha/submit_token',{captcha_token:'SYNTHETIC_VENDOR_TOKEN',vendor_name:'google',interactive:false,
        action:'VERIFY_CONSENT',device_id:opts.sessionId,uri_protecting:'/payment_consents/'+created.id+'/verify',
        pa_context:{request_id:ids[1],merchant_id:'merchant_synthetic',consent_id:created.id,extra_data:{}}},true);
      const offset=new Date().getTimezoneOffset(),absolute=Math.abs(offset),remainder=absolute%60;
      const result=await(await post('/api/v1/pa/payment_consents/'+created.id+'/verify',{request_id:ids[2],payment_method:{type:'card',card},
        device_data:{device_id:opts.sessionId,screen_height:screen.height,screen_width:screen.width,screen_color_depth:screen.colorDepth,
          language:navigator.language,timezone:(offset<=0?'+':'-')+Math.floor(absolute/60)+(remainder?':'+remainder:''),
          browser:{java_enabled:navigator.javaEnabled(),javascript_enabled:true,user_agent:navigator.userAgent}},
        verification_options:{card:{}},integration_data:{type:'dropIn',origin:location.origin},
        return_url:location.origin+'/page/3ds-callback?'+new URLSearchParams({client_secret:secret,componentName:'dropIn',checkoutOrigin:location.origin,
          awxTracker:ids[3],loadingColor:getComputedStyle(document.documentElement).getPropertyValue('--color-icon-interactive').trim()}),
        captcha_data:{provider:'google',token:'SYNTHETIC_VENDOR_TOKEN',extra:{tencent_captcha_random_str:''},interactive:false,action:'VERIFY_CONSENT'}})).json();
      await fetch('/api/v1/pa/payment_intents/'+result.initial_payment_intent_id,{headers:headers()});
      parent.postMessage({syntheticFinished:true},location.origin);
    };`;
}
function fixture(directory, failure) {
  let scopeAlive = true;
  let checkpointExpired = false;
  const invoked = [],
    wire = [],
    failures = [],
    reads = {
      initial: origin + '/pay?s=SYNTHETIC_CAPABILITY',
      token: 'SYNTHETIC_CAPABILITY',
      payer: { sellerId: 'seller_synthetic' },
      options: undefined,
      countries: new Set(['IT']),
      base: {
        stop() {},
        requestScopeVerified: () => scopeAlive,
        renderRequestTarget: (value) => new URL(value).pathname === '/'
      },
      bindFrame(value) {
        this.options = JSON.parse(
          new URLSearchParams(new URL(value).hash.slice('#/elements/dropin?'.length)).get('options')
        );
      },
      frameDelivered() {},
      ready: () => true,
      claim: () => null,
      validate: () => ({}),
      delivered() {},
      authority: () => false
    };
  const policy = new InstrumentServices(reads, { loaderTarget: () => false });
  let workerReady = false;
  const services = {
    session,
    bootstrapReady: () => workerReady,
    beforeCookies() {},
    claim: (meta) => policy.claim(meta),
    validate: (...args) => policy.validate(...args),
    evidence: () => ({}),
    delivered(claim) {
      policy.delivered(claim);
      if (claim.role === 'google_connect') workerReady = true;
    }
  };
  const relay = {
    async send(claim, _deadline, live, beforeSend) {
      if (failure === 'expired' && claim.role === 'consent_create') scopeAlive = false;
      if (failure === 'profile_expired' && claim.role === 'consent_create') {
        checkpointExpired = true;
        Date.now = () => Date.parse('2026-11-15T00:00:00Z');
      }
      await beforeSend();
      assert.equal(live('admit'), true);
      const records = fs
        .readdirSync(directory)
        .map((name) => JSON.parse(fs.readFileSync(path.join(directory, name))));
      assert.ok(
        records.some(
          (record) =>
            record.phase === 'possible_send' &&
            record.role === claim.role &&
            record.ordinal === claim.ordinal &&
            record.body_sha256 === hash(claim.body)
        )
      );
      invoked.push(claim.role);
      wire.push(claim);
      const result = (body, type = 'application/json', status = 200, headers = {}) => ({
        body: Buffer.from(body),
        status,
        headers: { 'content-type': type, ...headers }
      });
      if (claim.role === 'main_document') return result(html(mainProgram(failure)), 'text/html');
      if (claim.role === 'feature_initialize')
        return result('{"has_updates":true,"feature_gates":{}}', 'application/json', 200, {
          'access-control-allow-origin': origin
        });
      if (claim.role === 'dropin_document') return result(html(dropinProgram(failure)), 'text/html');
      if (claim.role === 'google_entry')
        return result(
          'const source=' +
            json(releaseURL) +
            ';const script=document.createElement("script");script.src=source;document.body.append(script);',
          'application/javascript'
        );
      if (['google_main_release', 'google_anchor_release', 'google_worker_release'].includes(claim.role)) {
        return result(
          'if(typeof document!=="undefined"){if(location.hostname==="checkout.sandbox.airwallex.com"){const child=document.createElement("iframe");child.style="display:none";child.src=' +
            json(
              google + '/recaptcha/enterprise/anchor?k=' + googleKey + '&v=' + release + '&size=invisible'
            ) +
            ';document.body.append(child);}else{new Worker(' +
            json(google + '/recaptcha/enterprise/webworker.js?hl=en&v=' + release) +
            ');}}',
          'application/javascript'
        );
      }
      if (claim.role === 'google_anchor')
        return result('<!doctype html><script src="' + releaseURL + '"></script>', 'text/html');
      if (claim.role === 'google_worker_initial')
        return result(
          'importScripts(' +
            json(releaseURL) +
            ');fetch(' +
            json(google + '/recaptcha/enterprise/reload') +
            ',{method:"POST",body:"synthetic"});',
          'application/javascript'
        );
      if (claim.role === 'google_connect') return result('synthetic', 'text/plain');
      if (claim.role === 'consent_create')
        return result(
          json({
            id: consent,
            customer_id: customer,
            next_triggered_by: 'customer',
            status: 'REQUIRES_PAYMENT_METHOD'
          }),
          'application/json',
          201
        );
      if (claim.role === 'captcha_preflight')
        return result('', 'application/json', 204, {
          'access-control-allow-origin': origin,
          'access-control-allow-methods': 'POST',
          'access-control-allow-headers': claim.headers['access-control-request-headers']
        });
      if (claim.role === 'captcha_submit')
        return result('', 'application/json', 200, { 'access-control-allow-origin': origin });
      if (claim.role === 'consent_verify')
        return result(
          json({
            id: consent,
            customer_id: customer,
            next_triggered_by: 'customer',
            status: 'VERIFIED',
            payment_method: { id: 'pmt_synthetic', type: 'card' },
            initial_payment_intent_id: 'int_synthetic'
          }),
          'application/json',
          failure === 'response' ? 400 : 200
        );
      if (claim.role === 'verification_read')
        return result(
          json({
            id: 'int_synthetic',
            customer_id: customer,
            status: 'SUCCEEDED',
            amount: '0.01',
            captured_amount: '0.00',
            currency: 'EUR'
          })
        );
      if (claim.role === 'complete')
        return result(
          json({
            mode: 'SETUP',
            currency: 'EUR',
            sellerId: 'seller_synthetic',
            setupModeDetails: { billingCustomerExternalId: customer }
          })
        );
      throw Error('Fixture cannot dispatch unknown role');
    },
    async close() {
      return true;
    }
  };
  return {
    makeReads: () => reads,
    makeServices: () => services,
    relay,
    budget: 6000,
    invoked,
    wire,
    failures,
    expired: () => !scopeAlive || checkpointExpired,
    onFailure: (error, url) => failures.push({ error: error.message, url })
  };
}

for (const failure of [
  'none',
  'changed_card',
  'unknown_frame',
  'unknown_service',
  'update',
  'response',
  'replaced_control',
  'reload',
  'interactive',
  'expired',
  'profile_expired'
]) {
  test('native original-document fixture: ' + failure, { skip: !tooling, timeout: 20000 }, async () => {
    assert.ok(path.isAbsolute(tooling));
    const directory = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'airwallex-instrument-native-')));
    fs.chmodSync(directory, 0o700);
    const originalNow = Date.now;
    try {
      const local = fixture(directory, failure);
      const result = await runInstrument(
        {
          url: origin + '/pay?s=SYNTHETIC_CAPABILITY',
          expected: { checkout_id: 'checkout_synthetic', mode: 'SETUP', currency: 'EUR' },
          buyer_email: 'synthetic@example.test',
          journal_directory: directory,
          context_sha256: 'a'.repeat(64),
          ownership_sha256: 'b'.repeat(64),
          source_hashes: sourceBook()
        },
        require(tooling),
        local
      );
      assert.equal(result.direct_connections_forwarded, 0);
      assert.equal(result.cleanup_complete, true);
      assert.equal(
        result.result,
        failure === 'none' ? 'completed' : 'fail',
        JSON.stringify({ result, invoked: local.invoked, failures: local.failures })
      );
      if (failure === 'none') {
        assert.deepEqual(local.invoked, [
          'main_document',
          'feature_initialize',
          'dropin_document',
          'google_entry',
          'google_main_release',
          'google_anchor',
          'google_anchor_release',
          'google_worker_initial',
          'google_worker_release',
          'google_connect',
          'consent_create',
          'captcha_preflight',
          'captcha_submit',
          'consent_verify',
          'verification_read',
          'complete'
        ]);
        assert.equal(result.unresolved_actions, 0);
        assert.equal(
          JSON.parse(local.wire.find((claim) => claim.role === 'consent_verify').body).payment_method.card
            .number,
          '4035501000000008'
        );
      }
      if (
        [
          'unknown_frame',
          'unknown_service',
          'update',
          'replaced_control',
          'reload',
          'interactive',
          'expired',
          'profile_expired'
        ].includes(failure)
      )
        assert.ok(!local.invoked.includes('consent_create'));
      if (['replaced_control', 'reload'].includes(failure)) assert.equal(result.submit_attempted, false);
      if (['expired', 'profile_expired'].includes(failure)) {
        assert.equal(local.expired(), true);
        assert.equal(result.submit_attempted, true);
        assert.equal(result.possible_financial_send, true);
      }
      if (failure === 'changed_card') assert.ok(!local.invoked.includes('consent_verify'));
      if (failure === 'response') {
        assert.ok(!local.invoked.includes('complete'));
        const records = fs
          .readdirSync(directory)
          .map((name) => JSON.parse(fs.readFileSync(path.join(directory, name))));
        assert.ok(
          records.some(
            (record) =>
              record.role === 'consent_verify' &&
              record.identities?.method_id === 'pmt_synthetic' &&
              record.response_validation === 'withheld'
          )
        );
      }
    } finally {
      Date.now = originalNow;
      fs.rmSync(directory, { recursive: true, force: true });
    }
  });
}
