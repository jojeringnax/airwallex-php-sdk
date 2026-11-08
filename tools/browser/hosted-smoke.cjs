'use strict';

const http = require('node:http');
const path = require('node:path');
const { inspect } = require('./hosted-browser.cjs');

async function smoke(playwright) {
  const hits = { page: 0, asset: 0, frame: 0, redirect: 0, forbidden: 0, websocket: 0, payer: 0, render: 0, controller: 0, method: 0, customer: 0, consent: 0, country: 0, status: 0, reloadPages: 0 };
  let tamper = false;
  let payerReply = 'matched';
  let tamperController = false;
  let redirectController = false;
  let methodReply = 'matched';
  let normalStatus = 200;
  const server = http.createServer((request, response) => {
    switch (new URL(request.url, 'http://fixture.test').pathname) {
      case '/page':
        hits.page++;
        response.writeHead(200, { 'Content-Type': 'text/html' });
        response.end('<!doctype html><p>Test mode</p><script src="/asset.js"></script>');
        break;
      case '/asset.js':
        hits.asset++;
        response.writeHead(200, { 'Content-Type': 'application/javascript' })
          .end(tamper ? 'window.fixtureLoaded = "altered";' : 'window.fixtureLoaded = true;');
        break;
      case '/payer-page':
        hits.reloadPages++;
        response.writeHead(200, { 'Content-Type': 'text/html', 'Set-Cookie': 'synthetic_cookie=PRIVATE_COOKIE; Path=/' });
        response.end(`<!doctype html><p>Fixture</p><script>
          const headers = { Authorization: 'Bearer ' + new URL(location.href).searchParams.get('s') };
          const getter = '/billing/payer-web-api/checkout?ts=' + Date.now();
          window.payerDelivered = false;
          const bootstrap = new URL(location.href).searchParams.has('render') ? new Promise((resolve, reject) => {
            const preload = document.createElement('link'); preload.rel = 'modulepreload';
            preload.href = 'https://static.airwallex.com/widgets/sdk-controller/sdk/v1/index.js?ts=' + Date.now();
            document.head.append(preload); // The public loader's production performance hint remains blocked.
            const module = document.createElement('script'); module.type = 'module';
            module.src = '/controller-sdk.js?ts=' + Date.now();
            module.onload = resolve; module.onerror = reject; document.head.append(module);
          }) : Promise.resolve();
          bootstrap.catch(() => {});
          fetch(getter, { headers }).then(r => r.json()).then(async data => {
            window.payerDelivered = true;
            if (new URL(location.href).searchParams.has('poll')) {
              window.pollsStarted = 0; window.firstPayerDelivery = performance.now();
              setInterval(async () => {
                window.pollsStarted++;
                try { const status = await fetch('/billing/payer-web-api/checkout/status', { headers });
                  if (!status.ok) throw new Error(); await status.json();
                } catch { location.reload(); }
              }, 5000);
            }
            if (new URL(location.href).searchParams.get('render')) {
              await bootstrap;
              if (window.fixtureControllerReady !== true) throw new Error('Fixture bootstrap missing.');
              const options = { mode: 'recurring', currency: data.currency,
                customer_id: data.setupModeDetails.billingCustomerExternalId,
                client_secret: data.setupModeDetails.externalCustomerClientSecret,
                observeConfig: new URL(location.href).searchParams.has('config'),
                observeNormal: new URL(location.href).searchParams.has('normal'),
                detachFixture: new URL(location.href).searchParams.has('detach') };
              if (new URL(location.href).searchParams.get('wrongframe')) options.customer_id = 'PRIVATE_OTHER';
              const frame = document.createElement('iframe');
              frame.src = location.origin + '/render-frame#/elements/dropin?' + new URLSearchParams({
                options: JSON.stringify(options), lang: 'en', appVersion: 'v2.56.0' });
              document.body.append(frame);
            }
          }).catch(async () => {
            await Promise.all([
              fetch(getter, { headers }).catch(() => {}),
              fetch('/billing/payer-web-api/checkout/status', { headers }).catch(() => {}),
              fetch('/billing/payer-web-api/checkout/collect', { method: 'POST', headers }).catch(() => {}),
              fetch('/billing/payer-web-api/checkout/complete', { method: 'POST', headers }).catch(() => {}),
            ]);
            window.payerRejected = true;
          });
        </script>`);
        break;
      case '/billing/payer-web-api/checkout':
        hits.payer++;
        if (request.method !== 'GET' || !/^\?ts=[0-9]{13}$/u.test(new URL(request.url, 'http://fixture.test').search)
            || request.headers.authorization !== 'Bearer ' + token || request.headers.referer || request.headers.cookie) {
          hits.forbidden++;
          response.writeHead(500).end();
        } else if (payerReply === 'redirect_rejected') {
          response.writeHead(302, { Location: '/forbidden?PRIVATE_REDIRECT' }).end();
        } else if (payerReply === 'http_rejected') {
          response.writeHead(503).end('PRIVATE_PROVIDER_ERROR');
        } else {
          response.writeHead(200, { 'Content-Type': 'application/json' });
          response.end(payerReply === 'invalid_response' ? 'PRIVATE_MALFORMED'
            : payerReply === 'oversized' ? 'x'.repeat(65537)
            : JSON.stringify({ mode: payerReply === 'wrong_mode' ? 'PAYMENT' : 'SETUP',
              currency: payerReply === 'wrong_currency' ? 'USD' : 'EUR',
              setupModeDetails: { billingCustomerExternalId: 'PRIVATE_EXTERNAL_CUSTOMER',
                externalCustomerClientSecret: 'PRIVATE_CLIENT_SECRET' } }));
        }
        break;
      case '/controller-sdk.js':
        hits.controller++;
        if (request.method !== 'GET' || !/^\?ts=[0-9]{13}$/u.test(new URL(request.url, 'http://fixture.test').search)
            || request.headers.authorization || request.headers.referer || request.headers.cookie) {
          hits.forbidden++; response.writeHead(500).end();
        } else if (redirectController) {
          response.writeHead(302, { Location: '/forbidden?PRIVATE' }).end();
        } else {
          response.writeHead(200, { 'Content-Type': 'application/javascript', 'Access-Control-Allow-Origin': '*',
            'Set-Cookie': 'synthetic_controller=PRIVATE_CONTROLLER_COOKIE; Path=/' }).end(tamperController
            ? 'window.fixtureControllerReady = "PRIVATE_ALTERED";' : 'window.fixtureControllerReady = true;');
        }
        break;
      case '/render-frame':
        hits.render++;
        response.writeHead(200, { 'Content-Type': 'text/html' }).end(
          `<!doctype html><p>Fixture render frame</p><script src="/render-sdk.js?ts=${Date.now()}"></script><script>
            const options = JSON.parse(new URLSearchParams(location.hash.split('?')[1]).get('options'));
            if (options.observeConfig) {
              const headers = { 'client-secret': options.client_secret, 'content-type': 'application/json',
                'x-api-version': '2024-06-14', 'x-client-ip-source': 'awx-checkout', 'awx-tracker': 'SYNTHETIC_TRACKER',
                'x-cloud-trace-context': '0123456789abcdef0123456789abcdef' };
              const target = '/api/v1/pa/config/payment_method_types?' + new URLSearchParams({ page_num: 0,
                page_size: 200, active: true, transaction_currency: options.currency, __verification_methods: true });
              fetch(target, { headers }).then(r => r.json()).then(data => {
                if (!Array.isArray(data.items)) return;
                for (const name of ['cardnumber', 'expiry', 'cvc']) {
                  const input = document.createElement('input'); input.name = name;
                  if (name === 'cvc') input.disabled = true;
                  document.body.append(input);
                }
                if (options.detachFixture) setTimeout(() => {
                  const old = window.frameElement; old.replaceWith(old.cloneNode(false));
                }, 1000);
                if (options.observeNormal) {
                  return fetch('/api/v1/pa/customers/' + options.customer_id, { headers }).then(r => r.json())
                    .then(data => { if (data.id !== options.customer_id) throw new Error();
                      return fetch('/api/v1/pa/payment_consents?' + new URLSearchParams({
                        customer_id: options.customer_id, status: 'VERIFIED', page_size: 100 }), { headers }); })
                    .then(r => r.json()).then(data => { if (data.items.length !== 0) throw new Error();
                      return fetch('/country'); }).then(r => r.json())
                    .then(data => { window.normalDataMatched = data.countryCode === 'DE'; });
                }
                return Promise.all([target, '/country', '/api/v1/pa/customers/PRIVATE',
                  '/api/v1/pa/payment_consents', '/api/v1/pa/PRIVATE', '/billing/payer-web-api/checkout/status']
                  .map(url => fetch(url, { headers }).catch(() => {})));
              }).catch(() => {});
            }
          </script>`);
        break;
      case '/api/v1/pa/config/payment_method_types':
        hits.method++;
        if (request.method !== 'GET' || request.headers['client-secret'] !== 'PRIVATE_CLIENT_SECRET'
            || request.headers['x-api-version'] !== '2024-06-14' || request.headers['x-client-ip-source'] !== 'awx-checkout'
            || request.headers['x-cloud-trace-context'] !== '0123456789abcdef0123456789abcdef'
            || request.headers.authorization || request.headers.cookie || request.headers.referer) {
          hits.forbidden++; response.writeHead(500).end();
        } else if (methodReply === 'redirect') {
          response.writeHead(302, { Location: '/forbidden?PRIVATE' }).end();
        } else {
          response.writeHead(200, { 'Content-Type': 'application/json',
            'Set-Cookie': 'synthetic_method=PRIVATE_COOKIE; Path=/' }).end(JSON.stringify({
              items: [{ name: 'card', transaction_mode: 'recurring', transaction_currencies: ['EUR'] }],
            })); // Missing pagination is deliberately not converted into a complete-list claim.
        }
        break;
      case '/api/v1/pa/customers/PRIVATE_EXTERNAL_CUSTOMER':
      case '/api/v1/pa/payment_consents':
      case '/country':
      case '/billing/payer-web-api/checkout/status': {
        const url = new URL(request.url, 'http://fixture.test');
        const status = url.pathname.endsWith('/status');
        const country = url.pathname === '/country';
        const consent = url.pathname.endsWith('/payment_consents');
        hits[status ? 'status' : country ? 'country' : consent ? 'consent' : 'customer']++;
        const pa = !status && !country;
        if (request.method !== 'GET' || request.headers.cookie || request.headers.referer
            || (status ? request.headers.authorization !== 'Bearer ' + token : request.headers.authorization)
            || (pa ? request.headers['client-secret'] !== 'PRIVATE_CLIENT_SECRET'
              || request.headers['x-api-version'] !== '2024-06-14'
              || request.headers['x-cloud-trace-context'] !== '0123456789abcdef0123456789abcdef'
              : request.headers['client-secret'] || request.headers['x-api-version'])
            || (consent ? url.search !== '?customer_id=PRIVATE_EXTERNAL_CUSTOMER&status=VERIFIED&page_size=100'
              : url.search !== '')) {
          hits.forbidden++; response.writeHead(500).end();
        } else if (status && normalStatus !== 200) {
          response.writeHead(normalStatus, { Location: '/forbidden?PRIVATE_NORMAL' }).end('PRIVATE_ERROR');
        } else {
          response.writeHead(200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*',
            'Set-Cookie': 'synthetic_normal_' + (status ? 'status' : country ? 'country' : consent ? 'consent' : 'customer')
              + '=PRIVATE_COOKIE; Path=/' });
          response.end(JSON.stringify(status ? { status: 'ACTIVE' } : country ? { countryCode: 'DE' }
            : consent ? { items: [] } : { id: 'PRIVATE_EXTERNAL_CUSTOMER' }));
        }
        break;
      }
      case '/render-sdk.js':
        response.writeHead(200, { 'Content-Type': 'application/javascript' }).end('window.fixtureRendered = true;');
        break;
      case '/frame':
        hits.frame++;
        response.writeHead(200, { 'Content-Type': 'text/html' }).end('<!doctype html><p>Fixture frame</p>');
        break;
      case '/redirect':
        hits.redirect++;
        response.writeHead(302, { Location: 'https://api.airwallex.com/never-send-this-fixture' }).end();
        break;
      case '/redirect-same':
        hits.redirect++;
        response.writeHead(302, { Location: '/forbidden?SYNTHETIC_PRIVATE' }).end();
        break;
      case '/redirect-other':
        hits.redirect++;
        response.writeHead(302, { Location: 'https://static.airwallex.com/components/sdk/v1/index.js' }).end();
        break;
      default:
        hits.forbidden++;
        response.writeHead(500).end();
    }
  });
  server.on('upgrade', (_request, socket) => { hits.websocket++; socket.destroy(); });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const address = server.address();
  if (!address || typeof address === 'string') throw new Error('Local smoke failed.');
  const origin = `http://127.0.0.1:${address.port}`;
  const expected = { checkout_id: 'SYNTHETIC_CHECKOUT', mode: 'SETUP', currency: 'EUR' };
  const token = Buffer.from('{"alg":"SYNTHETIC"}').toString('base64url') + '.'
    + Buffer.from(JSON.stringify({ resource_id: 'checkout:SYNTHETIC_CHECKOUT', exp: 4070908800 })).toString('base64url')
    + '.SYNTHETIC_SIGNATURE';
  try {
    const result = await inspect(origin + '/page?s=SYNTHETIC_SECRET', playwright, origin, async (page, context) => {
      if (await page.evaluate(() => window.fixtureLoaded) !== true) throw new Error('Local smoke failed.');
      await page.evaluate(async () => {
        for (const make of [
          () => new Worker('/worker.js'),
          () => new Worker(URL.createObjectURL(new Blob(['self.postMessage(true)']))),
          () => new SharedWorker('/worker.js'),
        ]) {
          let blocked = false;
          try { make(); } catch { blocked = true; }
          if (!blocked) throw new Error('Local worker guard failed.');
        }
        await Promise.all([
          fetch('/forbidden').catch(() => {}),
          fetch('https://api.airwallex.com/never-send-this-fixture', { method: 'POST' }).catch(() => {}),
          fetch('https://unknown.invalid/never-send-this-fixture').catch(() => {}),
          navigator.serviceWorker.register('/service-worker.js').catch(() => {}),
          new Promise(resolve => {
            const socket = new WebSocket(`ws://${location.host}/forbidden-websocket`);
            socket.onclose = () => resolve(); socket.onerror = () => resolve();
          }),
          new Promise(resolve => {
            const socket = new WebSocket('wss://api.airwallex.com/forbidden-websocket');
            socket.onclose = () => resolve(); socket.onerror = () => resolve();
          }),
          new Promise(resolve => {
            const frame = document.createElement('iframe'); frame.onload = resolve;
            frame.src = '/frame'; document.body.append(frame);
          }),
        ]);
        window.open('https://api.airwallex.com/forbidden-popup', '_blank');
      });
      for (const redirectPath of ['/redirect', '/redirect-same', '/redirect-other']) {
        const redirected = await context.newPage();
        let rejected = false;
        try { await redirected.goto(origin + redirectPath); } catch { rejected = true; }
        if (!rejected) throw new Error('Local smoke failed.');
      }
      const returned = await context.newPage();
      await returned.goto('https://example.com/?private=SYNTHETIC_SECRET');
      if (await returned.title() !== 'Returned') throw new Error('Local smoke failed.');
    });
    if (!result.test_mode_visible || result.blocked_requests < 4 || result.blocked_websockets !== 2
        || result.returns_intercepted !== 1 || hits.page !== 1 || hits.asset !== 1 || hits.frame !== 1
        || hits.redirect !== 3 || hits.forbidden !== 0 || hits.websocket !== 0
        || result.proxy_external_connections !== 0 || result.verified_static_assets !== 1) {
      throw new Error('Local smoke failed.');
    }
    tamper = true;
    let rejected = false;
    let alteredExecuted = false;
    try {
      await inspect(origin + '/page?s=SYNTHETIC_SECRET', playwright, origin, async page => {
        alteredExecuted = await page.evaluate(() => window.fixtureLoaded !== undefined);
      });
    } catch { rejected = true; }
    if (!rejected || alteredExecuted || hits.forbidden !== 0) throw new Error('Local integrity guard failed.');
    let payerChecks = 0;
    for (const reply of ['matched', 'wrong_mode', 'wrong_currency', 'invalid_response', 'oversized',
      'redirect_rejected', 'http_rejected']) {
      payerReply = reply;
      const before = hits.payer;
      const observed = await inspect(origin + '/payer-page?s=' + token, playwright, origin, async (page, context) => {
        await page.waitForFunction(() => window.payerRejected === true);
        if (await page.evaluate(() => window.payerDelivered) !== false) throw new Error('Local payer body escaped.');
        if (!(await context.cookies()).some(cookie => cookie.name === 'synthetic_cookie')) {
          throw new Error('Local cookie fixture did not populate its browser context.');
        }
      }, expected);
      const outcome = ['wrong_mode', 'wrong_currency'].includes(reply) ? 'mode_or_currency_mismatch'
        : reply === 'oversized' ? 'invalid_response' : reply;
      if (hits.payer !== before + 1 || observed.payer_observation !== outcome
          || !observed.payer_get_attempted || !observed.payer_response_observed
          || observed.payer_payload_delivered || observed.payer_customer_mapping_verified
          || observed.financial_routing_verified || observed.blocked_requests < 4
          || observed.payer_http_status !== (reply === 'http_rejected' ? 503 : reply === 'redirect_rejected' ? 302 : 200)
          || observed.proxy_external_connections !== 0 || hits.forbidden !== 0) {
        throw new Error('Local payer scope guard failed.');
      }
      payerChecks++;
    }
    payerReply = 'matched';
    const renderHits = hits.render;
    const rendered = await inspect(origin + '/payer-page?s=' + token + '&render=1', playwright, origin, async (page, context) => {
      if (await page.evaluate(() => window.payerDelivered) !== true) throw new Error('Local render delivery failed.');
      if ((await context.cookies()).some(cookie => cookie.name === 'synthetic_controller')) {
        throw new Error('Local public module cookie was retained.');
      }
      const frame = page.frames().find(candidate => candidate !== page.mainFrame());
      if (!frame || await frame.evaluate(() => window.fixtureRendered) !== true) throw new Error('Local render frame failed.');
      await page.evaluate(async () => {
        await new Promise(resolve => {
          const repeated = document.createElement('script'); repeated.type = 'module';
          repeated.src = '/controller-sdk.js?ts=' + Date.now();
          repeated.onload = () => resolve(); repeated.onerror = () => resolve(); document.head.append(repeated);
        });
      });
      await frame.evaluate(async () => {
        await Promise.all([
          fetch('/api/v1/pa/payment_consents/create', { method: 'POST' }).catch(() => {}),
          fetch('/billing/payer-web-api/checkout/status').catch(() => {}),
          fetch('/billing/payer-web-api/checkout/complete', { method: 'POST' }).catch(() => {}),
        ]);
      });
    }, expected, true);
    if (rendered.controller_asset_requests !== 1 || rendered.verified_controller_assets !== 1
        || rendered.controller_http_status !== 200 || rendered.controller_replays_blocked !== 1
        || hits.controller !== 1) throw new Error('Local public controller boundary failed.');
    if (!rendered.payer_payload_delivered || rendered.bound_render_frames !== 1 || rendered.verified_render_assets !== 1
        || rendered.financial_routing_verified || hits.render !== renderHits + 1 || hits.forbidden !== 0
        || rendered.proxy_external_connections !== 0 || rendered.render_frame_checks.qualified !== 1
        || rendered.render_frame_checks.fulfilled !== 1) throw new Error('Local render boundary failed.');
    const wrongFrame = await inspect(origin + '/payer-page?s=' + token + '&render=1&wrongframe=1',
      playwright, origin, null, expected, true);
    if (wrongFrame.bound_render_frames !== 0 || hits.render !== renderHits + 1 || hits.forbidden !== 0
        || wrongFrame.render_frame_checks.document_candidates !== 1
        || wrongFrame.render_frame_checks.customer_mismatch !== 1 || wrongFrame.render_frame_checks.qualified !== 0) {
      throw new Error('Local mismatched render frame escaped.');
    }
    const methodBefore = hits.method;
    const configured = await inspect(origin + '/payer-page?s=' + token + '&render=1&config=1',
      playwright, origin, async (_page, context) => {
        if ((await context.cookies()).some(cookie => cookie.name === 'synthetic_method')) {
          throw new Error('Local method cookie escaped its isolated request context.');
        }
      }, expected, true, true);
    if (hits.method !== methodBefore + 1 || !configured.method_config_get_attempted
        || configured.method_config_http_status !== 200 || !configured.method_config_payload_delivered
        || configured.method_config_observation !== 'matched' || configured.method_config_items !== 1
        || configured.method_config_pagination_known || !configured.method_config_card_advertised
        || !configured.card_readiness_observed || !configured.cardnumber_unique || !configured.cardnumber_visible
        || !configured.cardnumber_enabled || !configured.expiry_visible || !configured.expiry_enabled
        || !configured.cvc_visible || configured.cvc_enabled || hits.forbidden !== 0
        || Object.values(configured.denied_read_families).some(count => count !== 1)) {
      throw new Error('Local bound method-config/readiness boundary failed.');
    }
    const detached = await inspect(origin + '/payer-page?s=' + token + '&render=1&config=1&detach=1',
      playwright, origin, null, expected, true, true);
    if (detached.method_config_observation !== 'matched' || detached.method_config_http_status !== 200
        || !detached.method_config_card_advertised || !detached.method_config_payload_delivered
        || detached.card_readiness_observed || detached.card_readiness_unavailable_reason !== 'frame_detached'
        || detached.readiness_frame_detached !== true || detached.readiness_detach_stage !== 'before_count'
        || detached.bound_render_frames !== 1 || detached.render_frame_checks.limit_reached !== 1
        || Object.hasOwn(detached, 'cardnumber_unique') || Object.hasOwn(detached, 'cardnumber_visible')
        || Object.hasOwn(detached, 'cardnumber_enabled') || hits.forbidden !== 0) {
      throw new Error('Local detached-frame availability evidence was lost.');
    }
    const deniedConfig = await inspect(origin + '/payer-page?s=' + token + '&render=1&config=1',
      playwright, origin, null, expected, true);
    if (hits.method !== methodBefore + 2 || Object.hasOwn(deniedConfig, 'method_config_requested')) {
      throw new Error('Local default rendering admitted a method read.');
    }
    methodReply = 'redirect';
    const redirectedConfig = await inspect(origin + '/payer-page?s=' + token + '&render=1&config=1',
      playwright, origin, null, expected, true, true);
    if (hits.method !== methodBefore + 3 || redirectedConfig.method_config_http_status !== 302
        || redirectedConfig.method_config_observation !== 'redirect_rejected'
        || redirectedConfig.method_config_payload_delivered || redirectedConfig.cardnumber_unique
        || hits.forbidden !== 0) throw new Error('Local method redirect escaped.');
    methodReply = 'matched';
    const normalBefore = { ...hits };
    const ordinary = await inspect(origin + '/payer-page?s=' + token + '&render=1&config=1&normal=1&poll=1',
      playwright, origin, async (page, context) => {
        const frame = page.frames().find(candidate => candidate !== page.mainFrame());
        if (!frame || frame.isDetached() || await frame.evaluate(() => window.normalDataMatched) !== true
            || await page.evaluate(() => window.pollsStarted) !== 1
            || await page.evaluate(() => performance.now() - window.firstPayerDelivery >= 10000)) {
          throw new Error('Local normal reads did not finish before the later five-second poll.');
        }
        if ((await context.cookies()).some(cookie => cookie.name.startsWith('synthetic_normal_'))) {
          throw new Error('Local normal read response cookie crossed into browser storage.');
        }
        await frame.evaluate(async () => {
          await Promise.all(['/api/v1/pa/payment_consents/create', '/billing/payer-web-api/checkout/complete']
            .map(url => fetch(url, { method: 'POST' }).catch(() => {})));
        });
      }, expected, true, true, true);
    if (!ordinary.normal_read_inspection_completed || !ordinary.cardnumber_visible || !ordinary.expiry_enabled
        || ordinary.cvc_enabled || !ordinary.normal_read_window_closed || ordinary.normal_checkout_status_poisoned
        || ordinary.normal_checkout_status_attempts !== 1 || ordinary.normal_checkout_status_sends !== 1
        || ordinary.normal_checkout_status_delivered !== 1 || ordinary.normal_consent_list_items !== 0
        || ordinary.normal_consent_list_pagination_known || ordinary.normal_consent_list_has_more
        || hits.reloadPages !== normalBefore.reloadPages + 1 || hits.status !== normalBefore.status + 1
        || hits.customer !== normalBefore.customer + 1 || hits.consent !== normalBefore.consent + 1
        || hits.country !== normalBefore.country + 1 || hits.forbidden !== 0 || ordinary.proxy_external_connections !== 0) {
      throw new Error('Local once-only normal dependency or cookie boundary failed.');
    }
    // Same real five-second poll with the opt-in absent: its denied status read reloads and detaches the old frame.
    const statusBefore = hits.status;
    const missingReads = await inspect(origin + '/payer-page?s=' + token + '&render=1&config=1&normal=1&poll=1',
      playwright, origin, null, expected, true, true);
    if (hits.status !== statusBefore || missingReads.card_readiness_observed
        || missingReads.card_readiness_unavailable_reason !== 'frame_detached') {
      throw new Error('Local five-second denied status did not exercise the source reload boundary.');
    }
    normalStatus = 302;
    const rejectedNormal = await inspect(origin + '/payer-page?s=' + token + '&render=1&config=1&normal=1&poll=1',
      playwright, origin, null, expected, true, true, true);
    if (rejectedNormal.normal_checkout_status_sends !== 1 || !rejectedNormal.normal_checkout_status_poisoned
        || rejectedNormal.normal_checkout_status_delivered !== 0 || hits.status !== statusBefore + 1
        || hits.forbidden !== 0 || rejectedNormal.proxy_external_connections !== 0) {
      throw new Error('Local normal status redirect/retry poison boundary failed.');
    }
    normalStatus = 200;
    // Both failure probes keep the payer mismatched, so no private body or frame can be delivered.
    payerReply = 'wrong_mode'; tamperController = true;
    let controllerRejected = false;
    try { await inspect(origin + '/payer-page?s=' + token + '&render=1', playwright, origin, null, expected, true); }
    catch (error) { controllerRejected = error.message === 'Browser preflight failed safely.'; }
    if (!controllerRejected || hits.forbidden !== 0) throw new Error('Local altered controller executed.');
    tamperController = false; redirectController = true;
    const redirect = await inspect(origin + '/payer-page?s=' + token + '&render=1',
      playwright, origin, null, expected, true);
    if (redirect.controller_asset_requests !== 1 || redirect.verified_controller_assets !== 0
        || redirect.controller_http_status !== 302
        || redirect.bound_render_frames !== 0 || redirect.payer_payload_delivered || hits.forbidden !== 0
        || redirect.proxy_external_connections !== 0) throw new Error('Local controller redirect escaped.');
    return { ...result, deny_before_send: true, fixture_forbidden_hits: 0, fixture_websocket_hits: 0,
      altered_script_blocked: true, payer_binding_checks: payerChecks, payer_payloads_delivered: 0,
      payer_cookie_forwarding_blocked: true, bound_render_fixture_passed: true, mismatched_render_frame_blocked: true, fixed_render_diagnostics_verified: true,
      public_controller_verified: true, controller_cookie_suppressed: true, controller_redirect_blocked: true,
      altered_controller_blocked: true, production_preload_denied: true, method_config_verified: true,
      method_config_cookie_suppressed: true, method_config_redirect_blocked: true, structural_card_readiness_verified: true, detached_frame_unavailability_verified: true, normal_reads_verified: true, normal_cookie_isolation_verified: true,
      normal_poll_window_verified: true, normal_status_poison_verified: true, ...await riskSmoke(playwright) };
  } finally { await new Promise(resolve => server.close(resolve)); }
}

async function riskSmoke(playwright) {
  const https = require('node:https');
  const fs = require('node:fs');
  const { PassThrough } = require('node:stream');
  const { createHash } = require('node:crypto');
  const { egressProxy } = require('./hosted-policy.cjs');
  const { RiskJournalClient } = require('./hosted-risk-journal.cjs');
  const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');
  const fixturePath = path.join(__dirname, '../../tests/fixtures/hosted-risk');
  const cert = fs.readFileSync(path.join(fixturePath, 'localhost-fixture.crt'));
  const key = fs.readFileSync(path.join(fixturePath, 'localhost-fixture.key'));
  const sockets = new Set(), requests = [];
  let origin, bwsOrigin, tamper = false;
  const session = 'synthetic_bound_session';
  const bws = https.createServer({ cert, key }, (request, response) => {
    const chunks = [];
    request.on('data', chunk => chunks.push(chunk));
    request.on('end', () => {
      requests.push({ method: request.method, path: request.url, headers: request.headers, body: Buffer.concat(chunks) });
      const cors = { 'access-control-allow-origin': origin, 'access-control-allow-methods': 'POST',
        'access-control-allow-headers': 'content-type', 'set-cookie': 'UNTRUSTED_RESPONSE=PRIVATE_RESPONSE; Secure; SameSite=None' };
      if (request.method === 'OPTIONS') response.writeHead(204, cors).end();
      else if (request.url === '/bws/v3/visit') response.writeHead(200, { ...cors, 'content-type': 'application/json' })
        .end('{"data":{"visitorID":"PRIVATE_VISITOR","visitorToken":"PRIVATE_TOKEN"}}');
      else response.writeHead(204, cors).end();
    });
  });
  const syntheticLoader = () => `document.cookie = 'AWX_RISK_SID=${session}; Path=/';
    const item = {eventId:'${'b'.repeat(40)}',sessionId:'${session}',tenant:'pa',projectName:'element-js',
      window:{document:{url:location.origin+location.pathname}},event:{type:'pageShow'},fromMainWindow:true};
    navigator.sendBeacon('${bwsOrigin}/bws/v1/${session}',JSON.stringify([item]));
    fetch('${bwsOrigin}/bws/v3/visit',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({deviceInfo:{},requestTime:1})}).then(r=>r.json()).catch(()=>{});
    fetch('/api/v1/pa/payment_consents/create',{method:'POST',body:'PRIVATE_BLOCKED'}).catch(()=>{});
    const forbidden = document.createElement('iframe'); forbidden.src='/risk-iframe.html'; document.body.append(forbidden);`;
  const server = http.createServer((request, response) => {
    if (request.url === '/page') response.writeHead(200, { 'content-type': 'text/html',
      'set-cookie': 'application=PRIVATE_APPLICATION; Path=/' }).end(`<!doctype html><body><script
      id="airwallex-fraud-api" data-order-session-id="${session}" data-tenant="pa" data-project-name="element-js"
      src="/fingerprint.js?sessionId=${session}&hasScriptId=false&disable3rdTracking=false"></script>`);
    else if (request.url.startsWith('/fingerprint.js?')) response.writeHead(200, { 'content-type': 'application/javascript' })
      .end(tamper ? 'window.syntheticTamper = true;' : syntheticLoader());
    else { response.writeHead(500).end(); throw new Error('Forbidden local risk fixture was reached.'); }
  });
  for (const fixture of [server, bws]) fixture.on('connection', socket => {
    sockets.add(socket); socket.on('error', () => {}); socket.on('close', () => sockets.delete(socket));
  });
  await Promise.all([new Promise(resolve => server.listen(0, '127.0.0.1', resolve)),
    new Promise(resolve => bws.listen(0, '127.0.0.1', resolve))]);
  origin = 'http://127.0.0.1:' + server.address().port;
  bwsOrigin = 'https://127.0.0.1:' + bws.address().port;
  let browser, context, proxy, observer;
  let phase = 'configuration';
  let localDetails = {};
  try {
    for (const mode of ['matched', 'tampered', 'disabled']) {
      const enabled = mode !== 'disabled';
      tamper = mode === 'tampered';
      const before = requests.length, messages = [];
      const deadline = performance.now() + 8000;
      const document = origin + '/page';
      const parent = { renderEnabled: () => true, bindingEvidence: () => ({ payer_payload_delivered: true }),
        normalEvidence: () => ({ normal_reads_requested: true }), normalWindowRemaining: () => Math.max(0, deadline - performance.now()),
        normalSourceMatches: value => value === document, riskSessionMatches: value => value === session };
      const input = new PassThrough(), output = new PassThrough(), nonce = 'a'.repeat(64);
      const journal = enabled ? new RiskJournalClient({ version: 1, nonce }, input, output) : null;
      output.on('data', chunk => {
        const message = JSON.parse(chunk.toString()); messages.push(message);
        input.write(JSON.stringify({ version: 1, nonce, sequence: message.sequence, phase: message.phase,
          role: message.role, ordinal: message.ordinal, durable: true }) + '\n');
      });
      phase = 'observer';
      observer = new HostedRiskObserver(parent, journal, playwright, { origin, bwsOrigin, ca: cert,
        loaderHash: createHash('sha256').update(syntheticLoader()).digest('hex') });
      phase = 'proxy';
      proxy = await egressProxy({ allowsHttp: value => new URL(value).origin === origin,
        allowsAuthority: (host, port) => (host === '127.0.0.1' && String(port) === new URL(origin).port)
          || observer.authority(host, port) });
      observer.start(proxy.server);
      phase = 'browser';
      browser = await playwright.chromium.launch({ channel: 'chrome', headless: true,
        proxy: { server: proxy.server, bypass: '' }, args: ['--proxy-bypass-list=<-loopback>', '--disable-quic'] });
      phase = 'context';
      context = await browser.newContext({ ignoreHTTPSErrors: false, serviceWorkers: 'block' });
      let page, closing = false, blocked = 0;
      const pending = new Set();
      await context.route('**/*', route => {
        const action = (async () => {
          if (await observer.handle(route, context, page?.mainFrame(), undefined, () => closing)) return;
          if (route.request().url() === document && route.request().method() === 'GET') {
            const response = await route.fetch({ maxRedirects: 0, maxRetries: 0 });
            try { await route.fulfill({ response }); } finally { await response.dispose(); }
          } else { blocked++; await route.abort('blockedbyclient'); }
        })();
        const safe = action.catch(() => { throw new Error('Local risk route failed.'); });
        pending.add(safe); void safe.finally(() => pending.delete(safe)); return safe;
      });
      await context.routeWebSocket(/.*/, socket => socket.close());
      phase = 'page';
      page = await context.newPage();
      phase = 'navigation';
      await page.goto(document, { waitUntil: 'domcontentloaded', timeout: 5000 });
      phase = 'observation';
      const until = performance.now() + 1500;
      while (enabled && requests.length - before < 3 && performance.now() < until) await new Promise(resolve => setTimeout(resolve, 10));
      await new Promise(resolve => setTimeout(resolve, 100));
      if (mode === 'matched') {
        const observed = requests.slice(before);
        if (observed.length !== 3 || observed.filter(value => value.method === 'OPTIONS').length !== 1
            || observed.filter(value => value.path === '/bws/v3/visit' && value.method === 'POST').length !== 1
            || observed.some(value => value.headers.authorization || value.headers['client-secret']
              || (value.headers.cookie ?? '').includes('PRIVATE_APPLICATION'))
            || messages.length !== 6 || blocked < 2
            || observer.evidence().risk_visitor_delivered !== 1 || observer.evidence().risk_event_delivered !== 1
            || (await context.cookies()).some(cookie => cookie.name === 'UNTRUSTED_RESPONSE')) {
          localDetails = { messages: messages.length, blocked,
            application_cookie_forwarded: observed.some(value => (value.headers.cookie ?? '').includes('PRIVATE_APPLICATION')),
            response_cookie_retained: (await context.cookies()).some(cookie => cookie.name === 'UNTRUSTED_RESPONSE'),
            preflights: observed.filter(value => value.method === 'OPTIONS').length,
            visitor_posts: observed.filter(value => value.path === '/bws/v3/visit' && value.method === 'POST').length };
          throw new Error('Local risk scope or durability check failed.');
        }
      } else if (requests.length !== before || messages.length !== 0
          || await page.evaluate(() => window.syntheticTamper === true)) throw new Error('Default or tampered risk denial failed.');
      phase = 'close';
      closing = true;
      if (!await observer.close()) throw new Error('Local risk relay did not close.');
      await context.close(); await browser.close(); await Promise.all([...pending]); await proxy.close();
      context = browser = proxy = observer = null;
    }
    return { risk_bootstrap_local_verified: true, risk_cookie_isolation_verified: true,
      risk_cors_grant_verified: true, risk_default_denial_verified: true, risk_financial_denial_verified: true };
  } catch {
    const failure = new Error('Local risk smoke failed safely.');
    failure.fixedLocalEvidence = { phase, requests: requests.length, ...localDetails, ...observer?.evidence() };
    throw failure;
  } finally {
    if (observer) await observer.close();
    if (context) await context.close();
    if (browser) await browser.close();
    if (proxy) await proxy.close();
    for (const socket of sockets) socket.destroy();
    await Promise.all([new Promise(resolve => server.close(resolve)), new Promise(resolve => bws.close(resolve))]);
  }
}

if (require.main === module) {
  (async () => {
    if (process.argv.length !== 3 || !path.isAbsolute(process.argv[2])) throw new Error('Local smoke failed.');
    const result = await smoke(require(process.argv[2]));
    process.stdout.write(JSON.stringify(result) + '\n');
  })().catch(() => {
    process.stdout.write('{"result":"fail","reason":"local_browser_smoke_failed","card_entry_enabled":false}\n');
    process.exitCode = 1;
  });
}
module.exports = { smoke, riskSmoke };
