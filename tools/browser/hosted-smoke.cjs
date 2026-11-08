'use strict';

const http = require('node:http');
const path = require('node:path');
const { inspect } = require('./hosted-browser.cjs');

async function smoke(playwright) {
  const hits = { page: 0, asset: 0, frame: 0, redirect: 0, forbidden: 0, websocket: 0, payer: 0, render: 0, controller: 0 };
  let tamper = false;
  let payerReply = 'matched';
  let tamperController = false;
  let redirectController = false;
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
            if (new URL(location.href).searchParams.get('render')) {
              await bootstrap;
              if (window.fixtureControllerReady !== true) throw new Error('Fixture bootstrap missing.');
              const options = { mode: 'recurring', currency: data.currency,
                customer_id: data.setupModeDetails.billingCustomerExternalId,
                client_secret: data.setupModeDetails.externalCustomerClientSecret };
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
          '<!doctype html><p>Fixture render frame</p><script src="/render-sdk.js?ts=' + Date.now() + '"></script>');
        break;
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
      altered_controller_blocked: true, production_preload_denied: true };
  } finally { await new Promise(resolve => server.close(resolve)); }
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
module.exports = { smoke };
