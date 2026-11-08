'use strict';

const http = require('node:http');
const path = require('node:path');
const { inspect } = require('./hosted-browser.cjs');

async function smoke(playwright) {
  const hits = { page: 0, asset: 0, frame: 0, redirect: 0, forbidden: 0, websocket: 0 };
  const server = http.createServer((request, response) => {
    switch (new URL(request.url, 'http://fixture.test').pathname) {
      case '/page':
        hits.page++;
        response.writeHead(200, { 'Content-Type': 'text/html' });
        response.end('<!doctype html><p>Test mode</p><script src="/asset.js"></script>');
        break;
      case '/asset.js':
        hits.asset++;
        response.writeHead(200, { 'Content-Type': 'application/javascript' }).end('window.fixtureLoaded = true;');
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
        || result.proxy_external_connections !== 0) throw new Error('Local smoke failed.');
    return { ...result, deny_before_send: true, fixture_forbidden_hits: 0, fixture_websocket_hits: 0 };
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
