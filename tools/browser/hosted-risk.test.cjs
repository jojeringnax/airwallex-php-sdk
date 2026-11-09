'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { PassThrough } = require('node:stream');
const { createHash } = require('node:crypto');
const http = require('node:http');
const https = require('node:https');
const net = require('node:net');
const fs = require('node:fs');
const path = require('node:path');
const util = require('node:util');
const { RiskJournalClient } = require('./hosted-risk-journal.cjs');
const { HostedRiskRelay } = require('./hosted-risk-relay.cjs');

const digest = value => createHash('sha256').update(value).digest('hex');
const nonce = 'a'.repeat(64);
const claim = () => ({ role: 'visitor', ordinal: 0, url: 'https://bws.sandbox.airwallex.com/bws/v3/visit',
  body: Buffer.from('{"deviceInfo":{},"requestTime":1}'), headers: { 'content-type': 'application/json' },
  sessionHash: digest('PRIVATE_SESSION'), scope: digest('PRIVATE_DOCUMENT_SESSION') });

function journalFixture(answer = (message, reply) => reply(message)) {
  const input = new PassThrough(), output = new PassThrough(), messages = [];
  const client = new RiskJournalClient({ version: 1, nonce }, input, output);
  output.on('data', chunk => {
    const message = JSON.parse(chunk.toString());
    messages.push(message);
    answer(message, value => input.write(JSON.stringify({ version: 1, nonce, sequence: value.sequence,
      phase: value.phase, role: value.role, ordinal: value.ordinal, durable: true }) + '\n'), input);
  });
  return { client, messages, input, output };
}

test('wire hash and possible-send ACK precede invocation; observed ACK precedes result release', async () => {
  let prepared = false, resultDurable = false, invocations = 0;
  const fixture = journalFixture((message, reply) => {
    if (message.phase === 'prepare') { prepared = true; reply(message); }
    else setTimeout(() => { resultDurable = true; reply(message); }, 10);
  });
  const source = claim();
  const response = await fixture.client.run(source, async () => {
    assert.equal(prepared, true); invocations++;
    return { status: 200, body: Buffer.from('{"data":{}}') };
  }, () => true, performance.now() + 500);
  assert.equal(invocations, 1); assert.equal(resultDurable, true); assert.equal(response.status, 200);
  assert.deepEqual(fixture.messages.map(value => value.phase), ['prepare', 'observed']);
  assert.equal(fixture.messages[0].body_sha256, digest(source.body));
  assert.equal(fixture.messages[0].target_sha256, digest(source.url));
  assert.equal(Object.hasOwn(fixture.messages[0], 'body'), false);
  assert.doesNotMatch(util.inspect(fixture.client), /PRIVATE|aaaaaa/u);
  assert.throws(() => JSON.stringify(fixture.client));
  await fixture.client.close();
  assert.equal(fixture.client.evidence().risk_journal_closed, true);
  assert.equal(fixture.client.evidence().risk_journal_poisoned, false);
});

for (const fault of ['nonce', 'sequence', 'duplicate', 'partial_eof', 'no_ack']) {
  test('missing or invalid durability acknowledgement prevents any client invocation: ' + fault, async () => {
    let invocations = 0;
    const fixture = journalFixture((message, reply, input) => {
      if (fault === 'no_ack') return;
      if (fault === 'partial_eof') { input.write('{"version":'); input.end(); return; }
      if (fault === 'duplicate') {
        input.write(JSON.stringify({ version: 1, nonce, sequence: message.sequence, phase: message.phase,
          role: message.role, ordinal: message.ordinal, durable: true }).replace('"version":1', '"version":1,"version":1') + '\n');
      } else if (fault === 'nonce') input.write(JSON.stringify({ version: 1, nonce: 'b'.repeat(64),
        sequence: message.sequence, phase: message.phase, role: message.role, ordinal: message.ordinal, durable: true }) + '\n');
      else reply({ ...message, sequence: message.sequence + 1 });
    });
    await assert.rejects(fixture.client.run(claim(), async () => { invocations++; }, () => true, performance.now() + 30));
    assert.equal(invocations, 0);
    assert.equal(fixture.client.evidence().risk_journal_poisoned, true);
    await fixture.client.close();
  });
}

test('transport ambiguity and withheld response are durable once and suppress queued sends', async () => {
  for (const failure of ['send', 'response']) {
    const fixture = journalFixture();
    let invocations = 0;
    await assert.rejects(fixture.client.run(claim(), async () => {
      invocations++;
      if (failure === 'send') throw new Error('PRIVATE_URL_COOKIE_BODY');
      return { status: 200, body: Buffer.from('PRIVATE_UNEXPECTED') };
    }, () => false, performance.now() + 500), error => !String(error).includes('PRIVATE'));
    await assert.rejects(fixture.client.run(claim(), async () => { invocations++; }, () => true, performance.now() + 500));
    assert.equal(invocations, 1);
    assert.equal(fixture.messages.at(-1).phase, failure === 'send' ? 'failed' : 'observed');
    if (failure === 'response') assert.equal(fixture.messages.at(-1).response_validation, 'withheld');
    await fixture.client.close();
  }
});

test('bounded queue and shutdown cannot release an unsent proposal', async () => {
  const fixture = journalFixture();
  let invocations = 0;
  fixture.output.removeAllListeners('data');
  const first = fixture.client.run(claim(), async () => { invocations++; }, () => true, performance.now() + 500).catch(() => {});
  const second = fixture.client.run(claim(), async () => { invocations++; }, () => true, performance.now() + 500).catch(() => {});
  await assert.rejects(fixture.client.run(claim(), async () => { invocations++; }, () => true, performance.now() + 500));
  await fixture.client.close();
  await Promise.all([first, second]);
  assert.equal(invocations, 0);
});

async function tlsFixture(mode = 'matched') {
  // Public, synthetic localhost-only certificate/key fixtures; no operator key is used.
  const root = path.join(__dirname, '../../tests/fixtures/hosted-risk');
  const cert = fs.readFileSync(path.join(root, 'localhost-fixture.crt'));
  const key = fs.readFileSync(path.join(root, 'localhost-fixture.key'));
  const sockets = new Set(), requests = [];
  const server = https.createServer({ key, cert }, (request, response) => {
    const chunks = [];
    request.on('data', chunk => chunks.push(chunk));
    request.on('end', () => {
      requests.push({ method: request.method, path: request.url, headers: request.headers, body: Buffer.concat(chunks) });
      if (mode === 'loader_large_declared') { response.writeHead(200, { 'content-length': '262145' }).flushHeaders(); return; }
      if (mode === 'loader_large') { response.writeHead(200).end('x'.repeat(262145)); return; }
      if (mode === 'loader_encoding') { response.writeHead(200, { 'content-encoding': 'gzip' }).end('private'); return; }
      if (mode === 'redirect') { response.writeHead(302, { location: 'https://unknown.invalid/PRIVATE' }).end(); return; }
      if (mode === 'large_header') { response.writeHead(200, { 'X-Large': 'x'.repeat(17000) }).end(); return; }
      if (mode === 'large_declared') { response.writeHead(200, { 'content-length': '65537' }).flushHeaders(); return; }
      if (mode === 'aborted') { response.writeHead(200, { 'content-length': '40' }); response.write('x'); response.destroy(); return; }
      if (mode === 'trickle') {
        response.writeHead(200); const timer = setInterval(() => response.write('x'), 5);
        response.on('close', () => clearInterval(timer)); return;
      }
      const eventOrdinal = requests.filter(value => value.path.startsWith('/bws/v1/')).length;
      const respond = () => {
      response.writeHead(mode === 'instrument_events' && eventOrdinal === 2 ? 400 : 200,
        { 'content-type': 'application/json', 'access-control-allow-origin': '*',
        ...(mode.startsWith('instrument_') ? { 'access-control-allow-methods': 'POST',
          'access-control-allow-headers': 'content-type' } : {}) });
      response.end(mode === 'large_body' ? 'x'.repeat(65537) : '{"data":{"visitorID":"PRIVATE_VISITOR"}}');
      };
      if (mode === 'instrument_events' && eventOrdinal === 1) setTimeout(respond, 30);
      else respond();
    });
  });
  server.on('connection', socket => { sockets.add(socket); socket.on('close', () => sockets.delete(socket)); });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;
  const proxy = http.createServer();
  const tunnels = new Set();
  proxy.on('connect', (request, socket, head) => {
    if (request.url !== '127.0.0.1:' + port) { socket.destroy(); return; }
    tunnels.add(socket); socket.on('close', () => tunnels.delete(socket));
    const upstream = net.connect(port, '127.0.0.1', () => {
      socket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
      if (head.length) upstream.write(head);
      socket.pipe(upstream); upstream.pipe(socket);
    });
    tunnels.add(upstream); upstream.on('close', () => tunnels.delete(upstream));
    upstream.on('error', () => socket.destroy()); socket.on('error', () => upstream.destroy());
    socket.on('close', () => upstream.destroy());
  });
  await new Promise(resolve => proxy.listen(0, '127.0.0.1', resolve));
  return { origin: 'https://127.0.0.1:' + port, proxy: 'http://127.0.0.1:' + proxy.address().port, cert, requests,
    async close() {
      for (const socket of [...sockets, ...tunnels]) socket.destroy();
      await Promise.all([new Promise(resolve => proxy.close(resolve)), new Promise(resolve => server.close(resolve))]);
    } };
}

const visitorHeaders = { accept: 'application/json', 'content-type': 'application/json',
  origin: 'https://checkout.sandbox.airwallex.com', referer: 'https://checkout.sandbox.airwallex.com/' };

test('real local TLS verifies the fixture chain and forwards exact bytes once without cookies or API auth', async () => {
  const fixture = await tlsFixture();
  const relay = new HostedRiskRelay(fixture.proxy, { origin: fixture.origin, ca: fixture.cert });
  try {
    const body = claim().body;
    const response = await relay.send(fixture.origin + '/bws/v3/visit', 'POST', 'visitor', visitorHeaders, body, performance.now() + 1000);
    assert.equal(response.status, 200); assert.equal(response.body.length, response.responseBytes);
    assert.equal(fixture.requests.length, 1); assert.deepEqual(fixture.requests[0].body, body);
    assert.equal(fixture.requests[0].headers.cookie, undefined);
    assert.equal(fixture.requests[0].headers.authorization, undefined);
    assert.equal(await relay.close(), true);
  } finally { await relay.close(); await fixture.close(); }
});

for (const mode of ['large_header', 'large_declared', 'large_body', 'aborted', 'trickle']) {
  test('streaming response limits and absolute deadline stop the request without replay: ' + mode, async () => {
    const fixture = await tlsFixture(mode);
    const relay = new HostedRiskRelay(fixture.proxy, { origin: fixture.origin, ca: fixture.cert });
    try {
      await assert.rejects(relay.send(fixture.origin + '/bws/v3/visit', 'POST', 'visitor', visitorHeaders,
        claim().body, performance.now() + (mode === 'trickle' ? 50 : 1000)), error => !String(error).includes('PRIVATE'));
      await assert.rejects(relay.send(fixture.origin + '/bws/v3/visit', 'POST', 'visitor', visitorHeaders,
        claim().body, performance.now() + 1000));
      assert.equal(fixture.requests.length, 1);
    } finally { await relay.close(); await fixture.close(); }
  });
}

test('redirect is observed as one bounded first-hop response and never followed', async () => {
  const fixture = await tlsFixture('redirect');
  const relay = new HostedRiskRelay(fixture.proxy, { origin: fixture.origin, ca: fixture.cert });
  try {
    const response = await relay.send(fixture.origin + '/bws/v3/visit', 'POST', 'visitor', visitorHeaders, claim().body, performance.now() + 1000);
    assert.equal(response.status, 302); assert.equal(fixture.requests.length, 1);
  } finally { await relay.close(); await fixture.close(); }
});

const { HostedRiskPolicy, fingerprintAsset } = require('./hosted-risk-policy.cjs');
const sourceDocument = 'https://checkout.sandbox.airwallex.com/#/elements/dropin?PRIVATE_OPTIONS';
const privateSession = 'synthetic_bound_session';
function policyFixture(enabled = true) {
  const body = Buffer.from('window.syntheticRiskLoader = true;');
  const parent = {
    renderEnabled: () => true, bindingEvidence: () => ({ payer_payload_delivered: true }),
    normalEvidence: () => ({ normal_reads_requested: true }), normalWindowRemaining: () => 1000,
    normalSourceMatches: value => value === sourceDocument,
    riskSessionMatches: value => value === privateSession,
  };
  const policy = new HostedRiskPolicy(parent, enabled, null, () => Date.parse('2026-11-08T00:00:00Z'));
  const url = fingerprintAsset.origin + fingerprintAsset.path
    + '?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false';
  const proof = { document: sourceDocument, role: 'dropin', session: privateSession, id: 'airwallex-fraud-api',
    orderSession: privateSession, disableTracking: null, merchant: null, tenant: null, project: null };
  const load = () => policy.claimLoader(url, 'GET', 'script', proof);
  // Unit fixture pins synthetic bytes, never repins the real public build.
  const local = new HostedRiskPolicy(parent, enabled, { origin: 'http://127.0.0.1:1234',
    bwsOrigin: 'https://127.0.0.1:1235', loaderHash: digest(body) });
  return { policy, parent, url, proof, load, body, local };
}
function readyPolicy() {
  const fixture = policyFixture();
  const localUrl = 'http://127.0.0.1:1234/fingerprint.js?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false';
  assert.ok(fixture.local.claimLoader(localUrl, 'GET', 'script', fixture.proof));
  assert.equal(fixture.local.verifyLoader(fixture.body), true);
  return fixture;
}
function eventBody(overrides = {}) {
  return Buffer.from(JSON.stringify([{ eventId: 'b'.repeat(40), sessionId: privateSession,
    window: { document: { url: 'https://checkout.sandbox.airwallex.com/' } },
    event: { type: 'pageShow', createdAtUTC: 1 }, fromMainWindow: true, ...overrides }]));
}
const policyVisitorHeaders = { origin: 'https://checkout.sandbox.airwallex.com', 'content-type': 'application/json' };
const visitorBody = Buffer.from('{"deviceInfo":{},"requestTime":1}');
const eventHeaders = { origin: 'https://checkout.sandbox.airwallex.com', 'content-type': 'text/plain;charset=UTF-8' };

test('risk permission is explicit and binds loader role, session, source attributes and dated whole script hash', () => {
  assert.equal(policyFixture(false).load(), null);
  const { policy, load, body, parent, proof, url } = policyFixture();
  assert.ok(load());
  assert.equal(load(), null);
  assert.equal(policy.verifyLoader(body), false); // Real pin never accepts synthetic bytes.
  for (const change of [ { session: 'OTHER' }, { orderSession: 'OTHER' }, { id: 'other' },
    { disableTracking: '' }, { tenant: 'pa' }, { merchant: 'unbound' }, { document: 'https://checkout.airwallex.com/' } ]) {
    const candidate = new HostedRiskPolicy(parent, true);
    assert.equal(candidate.claimLoader(url, 'GET', 'script', { ...proof, ...change }), null);
  }
  const fixture = readyPolicy();
  assert.equal(fixture.local.evidence().risk_loader_verified, true);
  assert.equal(fixture.local.evidence().risk_financial_ready, false);
  assert.equal(fixture.local.evidence().risk_graph_complete, false);
  assert.equal(fixture.local.authority('static.airwallex.com', 443), false);
  assert.throws(() => JSON.stringify(fixture.local));
});

test('expired/changed loader and unknown query/path/type never become admitted scripts', () => {
  for (const change of [ '/changed.js', '?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=true',
    '?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false&extra=1' ]) {
    const fixture = policyFixture();
    const url = change[0] === '/' ? new URL(change, fingerprintAsset.origin).href
      : fingerprintAsset.origin + fingerprintAsset.path + change;
    assert.equal(fixture.policy.claimLoader(url, 'GET', 'script', fixture.proof), null);
  }
  const fixture = policyFixture();
  const expired = new HostedRiskPolicy(fixture.parent, true, null, () => fingerprintAsset.expires);
  assert.equal(expired.claimLoader(fixture.url, 'GET', 'script', fixture.proof), null);
  assert.equal(fixture.policy.claimLoader(fixture.url, 'GET', 'document', fixture.proof), null);
});

test('visitor body waits for a real CORS grant and fixed source response shape; no grant is invented', () => {
  for (const valid of [true, false]) {
    const { local } = readyPolicy();
    const claim = local.claim('https://127.0.0.1:1235/bws/v3/visit', 'POST', 'fetch', policyVisitorHeaders,
      visitorBody, sourceDocument, 'dropin');
    assert.ok(claim);
    const result = { status: 200, fields: [{ name: 'content-type', value: 'application/json;charset=UTF-8' },
      { name: 'access-control-allow-origin', value: policyVisitorHeaders.origin }],
      body: Buffer.from('{"data":{"visitorID":"PRIVATE_VISITOR","visitorToken":"PRIVATE_TOKEN"}}') };
    assert.equal(local.validateResponse(claim, result), false);
    const preflight = local.preflightFor(claim);
    assert.ok(preflight); assert.equal(preflight.body.length, 0);
    assert.equal(preflight.headers['content-type'], undefined);
    assert.equal(local.validateResponse(preflight, { status: 204, fields: [
      { name: 'access-control-allow-origin', value: valid ? policyVisitorHeaders.origin : 'https://other.test' },
      { name: 'access-control-allow-methods', value: 'POST' },
      { name: 'access-control-allow-headers', value: 'content-type' }], body: Buffer.alloc(0) }), valid);
    assert.equal(local.validateResponse(claim, result), valid);
    assert.equal(local.preflightFor(claim), null);
  }
});

test('only newly source-created cookie identities can leave; application cookies are projected away', () => {
  const { local } = readyPolicy();
  const riskCookie = { name: 'AWX_RISK_SID', value: privateSession,
    domain: 'checkout.sandbox.airwallex.com', path: '/', httpOnly: false };
  assert.equal(local.bindCookies([riskCookie], [riskCookie]), false);
  assert.equal(local.bindCookies([], [{ ...riskCookie, value: 'OTHER_SESSION' }]), false);
  assert.equal(local.bindCookies([], [{ ...riskCookie, domain: '.evil.test' }]), false);
  assert.equal(local.bindCookies([], [riskCookie]), true);
  const headers = local.cookieProjection({ ...eventHeaders, cookie: 'application=PRIVATE_SESSION; AWX_RISK_SID=' + privateSession });
  assert.equal(headers.cookie, 'AWX_RISK_SID=' + privateSession);
  assert.ok(local.claim('https://127.0.0.1:1235/bws/v1/' + privateSession, 'POST', 'ping', headers,
    eventBody(), sourceDocument, 'dropin'));
  assert.equal(local.claim('https://127.0.0.1:1235/bws/v1/' + privateSession, 'POST', 'ping',
    { ...eventHeaders, cookie: 'AWX_RISK_SID=OTHER_SESSION' }, eventBody(), sourceDocument, 'dropin'), null);
});

test('source event identity, original document, finite bytes/counts and no Beacon fallback replay are enforced', () => {
  const { local } = readyPolicy();
  const target = 'https://127.0.0.1:1235/bws/v1/' + privateSession;
  for (const override of [{ sessionId: 'OTHER' }, { accountId: 'FOREIGN' }, { fromMainWindow: false },
    { window: { document: { url: 'https://other.test/' } } }, { eventId: null }]) {
    assert.equal(local.claim(target, 'POST', 'ping', eventHeaders, eventBody(override), sourceDocument, 'dropin'), null);
  }
  const first = eventBody();
  assert.ok(local.claim(target, 'POST', 'ping', eventHeaders, first, sourceDocument, 'dropin'));
  assert.equal(local.claim(target, 'POST', 'fetch', eventHeaders, first, sourceDocument, 'dropin'), null);
  for (let index = 1; index < 8; index++) {
    assert.ok(local.claim(target, 'POST', 'ping', eventHeaders,
      eventBody({ eventId: index.toString(16).padStart(40, '0') }), sourceDocument, 'dropin'));
  }
  assert.equal(local.claim(target, 'POST', 'ping', eventHeaders, eventBody({ eventId: 'c'.repeat(40) }), sourceDocument, 'dropin'), null);
  for (const url of ['https://api.sandbox.airwallex.com/api/v1/pa/payment_consents/create',
    'https://bws.airwallex.com/bws/v1/' + privateSession, target + '?extra=1',
    'https://127.0.0.1:1235/bws/v1/OTHER', 'https://127.0.0.1:1235/bws/v3/verify']) {
    assert.equal(local.claim(url, 'POST', 'ping', eventHeaders, eventBody(), sourceDocument, 'dropin'), null);
  }
});

test('cumulative event bytes and duplicate JSON aliases cannot bypass finite policy', () => {
  const { local } = readyPolicy();
  const target = 'https://127.0.0.1:1235/bws/v1/' + privateSession;
  for (let index = 0; index < 4; index++) {
    const body = eventBody({ eventId: index.toString(16).padStart(40, '0'), event: { type: 'pageShow', fixturePadding: 'x'.repeat(60000) } });
    assert.ok(body.length < 65536);
    assert.ok(local.claim(target, 'POST', 'ping', eventHeaders, body, sourceDocument, 'dropin'));
  }
  assert.equal(local.claim(target, 'POST', 'ping', eventHeaders,
    eventBody({ eventId: 'd'.repeat(40), event: { type: 'pageShow', fixturePadding: 'x'.repeat(60000) } }), sourceDocument, 'dropin'), null);
  const fresh = readyPolicy().local;
  const duplicate = Buffer.from(eventBody().toString().replace('"sessionId":', '"sessionId":"OTHER","sessionId":'));
  assert.equal(fresh.claim(target, 'POST', 'ping', eventHeaders, duplicate, sourceDocument, 'dropin'), null);
  const escaped = Buffer.from(eventBody().toString().replace('"sessionId":', '"sessionId":"OTHER","session\\u0049d":'));
  assert.equal(fresh.claim(target, 'POST', 'ping', eventHeaders, escaped, sourceDocument, 'dropin'), null);
});

test('late loader context construction is retained, disposed, and cannot send after shutdown', async () => {
  const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');
  const fixture = policyFixture();
  let release, constructed = false, gets = 0, disposed = false, aborted = 0;
  const journal = { evidence: () => ({}), close: async () => {}, run: async () => { throw new Error('Unexpected telemetry'); } };
  const observer = new HostedRiskObserver(fixture.parent, journal, { request: { newContext: () => {
    constructed = true;
    return new Promise(resolve => { release = () => resolve({ get: async () => { gets++; }, dispose: async () => { disposed = true; } }); });
  } } }, { origin: 'http://127.0.0.1:1234', bwsOrigin: 'https://127.0.0.1:1235', loaderHash: digest(fixture.body), ca: Buffer.from('fixture') });
  observer.start('http://127.0.0.1:1236');
  const frame = { isDetached: () => false, url: () => sourceDocument,
    evaluate: async () => ({ id: 'airwallex-fraud-api', orderSession: privateSession,
      tenant: null, project: null, disableTracking: null }) };
  const request = { frame: () => frame, url: () => 'http://127.0.0.1:1234/fingerprint.js?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false',
    method: () => 'GET', resourceType: () => 'script' };
  const task = observer.handle({ request: () => request, abort: async () => { aborted++; } }, { cookies: async () => [] }, null, frame, () => false);
  while (!constructed) await new Promise(resolve => setImmediate(resolve));
  const closing = observer.close();
  release(); await Promise.all([task, closing]);
  assert.equal(gets, 0); assert.equal(disposed, true); assert.equal(aborted, 1);
});

test('private journal descriptors are not inherited by a separately spawned child', async () => {
  const { spawn } = require('node:child_process');
  const program = `const fs=require('node:fs'); const {spawnSync}=require('node:child_process');
    const identities=[3,4].map(fd=>{const s=fs.fstatSync(fd);return {dev:s.dev,ino:s.ino};});
    const own=identities.length===2;
    const child=spawnSync(process.execPath,['-e', 'const fs=require("node:fs");const expected='+JSON.stringify(identities)+';let inherited=false;for(const fd of [3,4]){try{const s=fs.fstatSync(fd);inherited ||= expected.some(e=>s.dev===e.dev&&s.ino===e.ino);}catch{}}process.stdout.write(JSON.stringify({inherited}));'],{stdio:['ignore','pipe','pipe']});
    process.stdout.write(JSON.stringify({own,child:JSON.parse(child.stdout.toString()).inherited,emptyStderr:child.stderr.length===0}));`;
  const child = spawn(process.execPath, ['-e', program], { stdio: ['ignore', 'pipe', 'pipe', 'pipe', 'pipe'] });
  const output = [], errors = [];
  child.stdout.on('data', value => output.push(value)); child.stderr.on('data', value => errors.push(value));
  const exit = await new Promise(resolve => child.on('close', resolve));
  assert.equal(exit, 0); assert.equal(Buffer.concat(errors).length, 0);
  assert.deepEqual(JSON.parse(Buffer.concat(output).toString()), { own: true, child: false, emptyStderr: true });
});

test('a document change during the durability wait prevents even a telemetry client invocation', async () => {
  const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');
  const fixture = policyFixture();
  let current = sourceDocument, aborts = 0, prepares = 0, publicDisposed = false;
  const frame = { isDetached: () => false, url: () => current,
    evaluate: async () => ({ id: 'airwallex-fraud-api', orderSession: privateSession,
      tenant: null, project: null, disableTracking: null }) };
  const journal = { evidence: () => ({}), close: async () => {},
    run: async (_claim, invoke) => { prepares++; current = 'about:blank'; return invoke(); } };
  const publicClient = { dispose: async () => { publicDisposed = true; }, get: async () => ({
    status: () => 200, body: async () => fixture.body, headers: () => ({ 'content-type': 'application/javascript' }), dispose: async () => {},
  }) };
  const observer = new HostedRiskObserver(fixture.parent, journal,
    { request: { newContext: async () => publicClient } }, { origin: 'http://127.0.0.1:1234',
      bwsOrigin: 'https://127.0.0.1:1235', loaderHash: digest(fixture.body), ca: Buffer.from('fixture') });
  observer.start('http://127.0.0.1:1236');
  const loader = { frame: () => frame, url: () => 'http://127.0.0.1:1234/fingerprint.js?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false',
    method: () => 'GET', resourceType: () => 'script' };
  assert.equal(await observer.handle({ request: () => loader, fulfill: async () => {}, abort: async () => { aborts++; } },
    { cookies: async () => [] }, null, frame, () => false), true);
  const request = { frame: () => frame, url: () => 'https://127.0.0.1:1235/bws/v1/' + privateSession,
    method: () => 'POST', resourceType: () => 'ping', allHeaders: async () => eventHeaders, postDataBuffer: eventBody };
  assert.equal(await observer.handle({ request: () => request, fulfill: async () => { assert.fail('Changed document received a body'); },
    abort: async () => { aborts++; } }, { cookies: async () => [] }, null, frame, () => false), true);
  assert.equal(prepares, 1); assert.equal(aborts, 1);
  assert.equal(observer.evidence().risk_event_client_invocations, 0);
  assert.equal(observer.evidence().risk_telemetry_poisoned, true);
  await observer.close(); assert.equal(publicDisposed, true);
});

test('a source-shaped three-event burst waits inside existing finite slots without an artificial third-request poison', async () => {
  const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');
  const upstream = await tlsFixture();
  const fixture = policyFixture();
  const durable = journalFixture((message, reply) => {
    if (message.phase === 'prepare' && message.ordinal === 0) setTimeout(() => reply(message), 30);
    else reply(message);
  });
  const publicClient = { dispose: async () => {}, get: async () => ({ status: () => 200,
    body: async () => fixture.body, headers: () => ({}), dispose: async () => {} }) };
  const observer = new HostedRiskObserver(fixture.parent, durable.client,
    { request: { newContext: async () => publicClient } }, { origin: 'http://127.0.0.1:1234',
      bwsOrigin: upstream.origin, loaderHash: digest(fixture.body), ca: upstream.cert });
  observer.start(upstream.proxy);
  const frame = { isDetached: () => false, url: () => sourceDocument, evaluate: async () => ({
    id: 'airwallex-fraud-api', orderSession: privateSession, tenant: null, project: null, disableTracking: null }) };
  const loader = { frame: () => frame, url: () => 'http://127.0.0.1:1234/fingerprint.js?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false',
    method: () => 'GET', resourceType: () => 'script' };
  try {
    await observer.handle({ request: () => loader, fulfill: async () => {}, abort: async () => assert.fail('Loader rejected') },
      { cookies: async () => [] }, null, frame, () => false);
    let delivered = 0, aborted = 0;
    const bodies = [0, 1, 2].map(index => eventBody({ eventId: index.toString(16).padStart(40, '0') }));
    await Promise.all(bodies.map(body => observer.handle({
      request: () => ({ frame: () => frame, url: () => upstream.origin + '/bws/v1/' + privateSession,
        method: () => 'POST', resourceType: () => 'ping', allHeaders: async () => eventHeaders, postDataBuffer: () => body }),
      fulfill: async () => { delivered++; }, abort: async () => { aborted++; },
    }, { cookies: async () => [] }, null, frame, () => false)));
    assert.equal(aborted, 0); assert.equal(delivered, 3); assert.equal(upstream.requests.length, 3);
    assert.deepEqual(upstream.requests.map(request => request.body), bodies);
    assert.deepEqual(durable.messages.filter(value => value.phase === 'prepare').map(value => value.ordinal), [0, 1, 2]);
    assert.equal(observer.evidence().risk_event_client_invocations, 3);
    assert.equal(observer.evidence().risk_telemetry_poisoned, false);
  } finally { await observer.close(); await upstream.close(); }
});

test('loader URI userinfo is rejected before constructing a public context or invoking GET', async () => {
  const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');
  const fixture = policyFixture();
  assert.equal(fixture.policy.claimLoader(fixture.url.replace('https://', 'https://SYNTHETIC_USER:SYNTHETIC_PASSWORD@'),
    'GET', 'script', fixture.proof), null);
  let constructions = 0, gets = 0, aborted = 0;
  const observer = new HostedRiskObserver(fixture.parent,
    { evidence: () => ({}), close: async () => {} }, { request: { newContext: async () => {
      constructions++; return { get: async () => { gets++; }, dispose: async () => {} };
    } } }, { origin: 'http://127.0.0.1:1234', bwsOrigin: 'https://127.0.0.1:1235', loaderHash: digest(fixture.body), ca: Buffer.from('fixture') });
  observer.start('http://127.0.0.1:1236');
  const frame = { isDetached: () => false, url: () => sourceDocument, evaluate: async () => ({
    id: 'airwallex-fraud-api', orderSession: privateSession, tenant: null, project: null, disableTracking: null }) };
  const request = { frame: () => frame, url: () => 'http://SYNTHETIC_USER:SYNTHETIC_PASSWORD@127.0.0.1:1234/fingerprint.js?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false',
    method: () => 'GET', resourceType: () => 'script' };
  assert.equal(await observer.handle({ request: () => request, abort: async () => { aborted++; } },
    { cookies: async () => [] }, null, frame, () => false), true);
  assert.equal(constructions, 0); assert.equal(gets, 0); assert.equal(aborted, 1);
  assert.equal(await observer.close(), true);
});

test('never-progress public context fails cleanup at the original bound; late completion is disposed once without GET', async () => {
  const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');
  const fixture = policyFixture();
  fixture.parent.normalWindowRemaining = () => 25;
  let release, constructing = false, disposed = 0, gets = 0, aborted = 0;
  const observer = new HostedRiskObserver(fixture.parent,
    { evidence: () => ({}), close: async () => {} }, { request: { newContext: () => {
      constructing = true;
      return new Promise(resolve => { release = () => resolve({ get: async () => { gets++; }, dispose: async () => { disposed++; } }); });
    } } }, { origin: 'http://127.0.0.1:1234', bwsOrigin: 'https://127.0.0.1:1235', loaderHash: digest(fixture.body), ca: Buffer.from('fixture') });
  observer.start('http://127.0.0.1:1236');
  const frame = { isDetached: () => false, url: () => sourceDocument, evaluate: async () => ({
    id: 'airwallex-fraud-api', orderSession: privateSession, tenant: null, project: null, disableTracking: null }) };
  const request = { frame: () => frame, url: () => 'http://127.0.0.1:1234/fingerprint.js?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false',
    method: () => 'GET', resourceType: () => 'script' };
  const task = observer.handle({ request: () => request, abort: async () => { aborted++; } },
    { cookies: async () => [] }, null, frame, () => false);
  while (!constructing) await new Promise(resolve => setImmediate(resolve));
  const began = performance.now();
  assert.equal(await observer.close(), false);
  assert.ok(performance.now() - began < 500);
  assert.equal(await task, true); assert.equal(aborted, 1); assert.equal(gets, 0); assert.equal(disposed, 0);
  release();
  for (let index = 0; index < 5; index++) await new Promise(resolve => setImmediate(resolve));
  assert.equal(disposed, 1); assert.equal(gets, 0);
  assert.equal(observer.evidence().risk_telemetry_poisoned, true);
});

for (const fault of ['body', 'response_dispose', 'abort']) {
  test('the whole loader handler and its cleanup finish at the original bound despite no-progress ' + fault, async () => {
    const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');
    const fixture = policyFixture();
    fixture.parent.normalWindowRemaining = () => 30;
    let entered = false, release, clientDisposed = false, responseDisposals = 0, gets = 0, fulfilled = 0;
    const response = { status: () => fault === 'abort' ? 403 : 200,
      body: async () => {
        if (fault !== 'body') return fixture.body;
        entered = true; return new Promise(resolve => { release = () => resolve(fixture.body); });
      }, headers: () => ({}), dispose: async () => {
        responseDisposals++;
        if (fault !== 'response_dispose') return;
        entered = true; return new Promise(resolve => { release = resolve; });
      } };
    const observer = new HostedRiskObserver(fixture.parent,
      { evidence: () => ({}), close: async () => {} }, { request: { newContext: async () => ({
        get: async () => { gets++; return response; }, dispose: async () => { clientDisposed = true; },
      }) } }, { origin: 'http://127.0.0.1:1234', bwsOrigin: 'https://127.0.0.1:1235', loaderHash: digest(fixture.body), ca: Buffer.from('fixture') });
    observer.start('http://127.0.0.1:1236');
    const frame = { isDetached: () => false, url: () => sourceDocument, evaluate: async () => ({
      id: 'airwallex-fraud-api', orderSession: privateSession, tenant: null, project: null, disableTracking: null }) };
    const request = { frame: () => frame, url: () => 'http://127.0.0.1:1234/fingerprint.js?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false',
      method: () => 'GET', resourceType: () => 'script' };
    const task = observer.handle({ request: () => request, fulfill: async () => { fulfilled++; }, abort: async () => {
      if (fault !== 'abort') return;
      entered = true; return new Promise(resolve => { release = resolve; });
    } }, { cookies: async () => [] }, null, frame, () => false);
    while (!entered) await new Promise(resolve => setImmediate(resolve));
    const began = performance.now();
    assert.equal(await observer.close(), false);
    assert.ok(performance.now() - began < 500);
    assert.equal(await task, true); assert.equal(gets, 1); assert.equal(clientDisposed, true);
    assert.equal(fulfilled, fault === 'response_dispose' ? 1 : 0);
    release();
    for (let index = 0; index < 5; index++) await new Promise(resolve => setImmediate(resolve));
    assert.equal(responseDisposals, 1); assert.equal(gets, 1);
    assert.equal(observer.evidence().risk_telemetry_poisoned, true);
  });
}

test('fixed field snapshot closes loader GET admission even when context construction started earlier', async () => {
  const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');
  const fixture = policyFixture();
  let release, constructing = false, admissionClosed = false, gets = 0, disposed = 0, aborted = 0;
  const observer = new HostedRiskObserver(fixture.parent,
    { evidence: () => ({}), close: async () => {} }, { request: { newContext: () => {
      constructing = true; return new Promise(resolve => { release = () => resolve({
        get: async () => { gets++; }, dispose: async () => { disposed++; },
      }); });
    } } }, { origin: 'http://127.0.0.1:1234', bwsOrigin: 'https://127.0.0.1:1235', loaderHash: digest(fixture.body), ca: Buffer.from('fixture') });
  observer.start('http://127.0.0.1:1236');
  const frame = { isDetached: () => false, url: () => sourceDocument, evaluate: async () => ({
    id: 'airwallex-fraud-api', orderSession: privateSession, tenant: null, project: null, disableTracking: null }) };
  const request = { frame: () => frame, url: () => 'http://127.0.0.1:1234/fingerprint.js?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false',
    method: () => 'GET', resourceType: () => 'script' };
  const task = observer.handle({ request: () => request, abort: async () => { aborted++; } },
    { cookies: async () => [] }, null, frame, () => false, () => admissionClosed);
  while (!constructing) await new Promise(resolve => setImmediate(resolve));
  admissionClosed = true; release();
  assert.equal(await task, true); assert.equal(gets, 0); assert.equal(aborted, 1);
  assert.equal(await observer.close(), true); assert.equal(disposed, 1);
});

for (const alreadyInvoked of [false, true]) {
  test('fixed snapshot distinguishes queued risk work from an already invoked response: ' + alreadyInvoked, async () => {
    const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');
    const upstream = await tlsFixture();
    const fixture = policyFixture();
    let admissionClosed = false, fulfilled = 0, aborted = 0;
    const journal = { evidence: () => ({}), close: async () => {}, run: async (claim, invoke, validate) => {
      if (!alreadyInvoked) admissionClosed = true; // The durability wait crossed the fixed snapshot.
      const response = await invoke();
      admissionClosed = true; // Already sent: retain the original response-handling window.
      assert.equal(validate(response), true);
      return response;
    } };
    const observer = new HostedRiskObserver(fixture.parent, journal, { request: { newContext: async () => ({
      get: async () => ({ status: () => 200, body: async () => fixture.body, headers: () => ({}), dispose: async () => {} }),
      dispose: async () => {},
    }) } }, { origin: 'http://127.0.0.1:1234', bwsOrigin: upstream.origin, loaderHash: digest(fixture.body), ca: upstream.cert });
    observer.start(upstream.proxy);
    const frame = { isDetached: () => false, url: () => sourceDocument, evaluate: async () => ({
      id: 'airwallex-fraud-api', orderSession: privateSession, tenant: null, project: null, disableTracking: null }) };
    const loader = { frame: () => frame, url: () => 'http://127.0.0.1:1234/fingerprint.js?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false',
      method: () => 'GET', resourceType: () => 'script' };
    try {
      await observer.handle({ request: () => loader, fulfill: async () => {}, abort: async () => assert.fail('Loader rejected') },
        { cookies: async () => [] }, null, frame, () => false, () => admissionClosed);
      const request = { frame: () => frame, url: () => upstream.origin + '/bws/v1/' + privateSession,
        method: () => 'POST', resourceType: () => 'ping', allHeaders: async () => eventHeaders, postDataBuffer: eventBody };
      await observer.handle({ request: () => request, fulfill: async () => { fulfilled++; }, abort: async () => { aborted++; } },
        { cookies: async () => [] }, null, frame, () => false, () => admissionClosed);
      assert.equal(upstream.requests.length, alreadyInvoked ? 1 : 0);
      assert.equal(fulfilled, alreadyInvoked ? 1 : 0); assert.equal(aborted, alreadyInvoked ? 0 : 1);
      assert.equal(observer.evidence().risk_event_client_invocations, alreadyInvoked ? 1 : 0);
      assert.equal(await observer.handle({ request: () => request }, { cookies: async () => [] }, null, frame,
        () => false, () => admissionClosed), false); // No replacement admission.
    } finally { await observer.close(); await upstream.close(); }
  });
}

test('expired whole-case deadline refuses a loader before constructing a context', async () => {
  const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');
  const fixture = policyFixture();
  fixture.parent.normalWindowRemaining = () => 5000;
  let constructions = 0;
  const observer = new HostedRiskObserver(fixture.parent,
    { evidence: () => ({}), close: async () => {} },
    { request: { newContext: async () => { constructions++; assert.fail('Expired case constructed context'); } } },
    null, performance.now() - 1);
  const frame = { isDetached: () => false, url: () => sourceDocument };
  const request = { frame: () => frame };
  assert.equal(await observer.handle({ request: () => request }, {}, null, frame, () => false), false);
  assert.equal(constructions, 0);
  await observer.close();
});

test('whole-case bound cuts off stalled construction while the normal window still has time', async () => {
  const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');
  const fixture = policyFixture();
  fixture.parent.normalWindowRemaining = () => 5000;
  let constructing = false, release, gets = 0, disposed = 0;
  const observer = new HostedRiskObserver(fixture.parent,
    { evidence: () => ({}), close: async () => {} }, { request: { newContext: () => {
      constructing = true;
      return new Promise(resolve => { release = () => resolve({
        get: async () => { gets++; }, dispose: async () => { disposed++; },
      }); });
    } } }, { origin: 'http://127.0.0.1:1234', bwsOrigin: 'https://127.0.0.1:1235',
      loaderHash: digest(fixture.body), ca: Buffer.from('fixture') }, performance.now() + 100);
  observer.start('http://127.0.0.1:1236');
  const frame = { isDetached: () => false, url: () => sourceDocument, evaluate: async () => ({
    id: 'airwallex-fraud-api', orderSession: privateSession, tenant: null, project: null, disableTracking: null }) };
  const request = { frame: () => frame,
    url: () => 'http://127.0.0.1:1234/fingerprint.js?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false',
    method: () => 'GET', resourceType: () => 'script' };
  const began = performance.now();
  const task = observer.handle({ request: () => request, abort: async () => {} },
    { cookies: async () => [] }, null, frame, () => false);
  assert.equal(await task, true);
  assert.equal(constructing, true); assert.equal(gets, 0);
  assert.ok(performance.now() - began < 1000);
  assert.equal(await observer.close(), false);
  release();
  for (let index = 0; index < 5; index++) await new Promise(resolve => setImmediate(resolve));
  assert.equal(gets, 0); assert.equal(disposed, 1);
});

for (const delayedAck of [false, true]) {
  test('whole-case deadline bounds telemetry even with a later normal deadline: delayed ACK ' + delayedAck, async () => {
    const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');
    const upstream = await tlsFixture('trickle');
    const fixture = policyFixture();
    fixture.parent.normalWindowRemaining = () => 5000;
    let fulfilled = 0, aborted = 0;
    const journal = { evidence: () => ({}), close: async () => {}, run: async (_claim, invoke) => {
      if (delayedAck) await new Promise(resolve => setTimeout(resolve, 250));
      return invoke();
    } };
    const observer = new HostedRiskObserver(fixture.parent, journal, { request: { newContext: async () => ({
      get: async () => ({ status: () => 200, body: async () => fixture.body, headers: () => ({}), dispose: async () => {} }),
      dispose: async () => {},
    }) } }, { origin: 'http://127.0.0.1:1234', bwsOrigin: upstream.origin,
      loaderHash: digest(fixture.body), ca: upstream.cert }, performance.now() + 200);
    observer.start(upstream.proxy);
    const frame = { isDetached: () => false, url: () => sourceDocument, evaluate: async () => ({
      id: 'airwallex-fraud-api', orderSession: privateSession, tenant: null, project: null, disableTracking: null }) };
    const loader = { frame: () => frame,
      url: () => 'http://127.0.0.1:1234/fingerprint.js?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false',
      method: () => 'GET', resourceType: () => 'script' };
    try {
      await observer.handle({ request: () => loader, fulfill: async () => {}, abort: async () => assert.fail('Loader failed') },
        { cookies: async () => [] }, null, frame, () => false);
      const request = { frame: () => frame, url: () => upstream.origin + '/bws/v1/' + privateSession,
        method: () => 'POST', resourceType: () => 'ping', allHeaders: async () => eventHeaders, postDataBuffer: eventBody };
      const began = performance.now();
      await observer.handle({ request: () => request, fulfill: async () => { fulfilled++; }, abort: async () => { aborted++; } },
        { cookies: async () => [] }, null, frame, () => false);
      assert.ok(performance.now() - began < 1000);
      assert.equal(observer.evidence().risk_event_client_invocations, delayedAck ? 0 : 1);
      assert.equal(upstream.requests.length, delayedAck ? 0 : 1);
      assert.equal(fulfilled, 0); assert.equal(aborted, 1);
    } finally { await observer.close(); await upstream.close(); }
  });
}


for (const mode of ['matched', 'redirect', 'loader_large_declared', 'loader_large', 'loader_encoding', 'trickle', 'aborted']) {
  test('instrument-only pinned loader uses bounded identity streaming and never follows: ' + mode, async () => {
    const upstream = await tlsFixture(mode);
    const relay = new HostedRiskRelay(upstream.proxy, { origin: upstream.origin, ca: upstream.cert }, true);
    const target = upstream.origin + '/fingerprint.js?sessionId=synthetic&hasScriptId=false&disable3rdTracking=false';
    try {
      const operation = relay.send(target, 'GET', 'loader', { accept: '*/*' }, Buffer.alloc(0), performance.now() + 100, () => true);
      if (['matched', 'redirect'].includes(mode)) {
        const response = await operation;
        assert.equal(response.status, mode === 'matched' ? 200 : 302);
      } else await assert.rejects(operation, error => !String(error).includes('PRIVATE'));
      assert.equal(upstream.requests.length, 1);
      assert.equal(upstream.requests[0].method, 'GET');
      assert.equal(upstream.requests[0].path, '/fingerprint.js?sessionId=synthetic&hasScriptId=false&disable3rdTracking=false');
      assert.equal(upstream.requests[0].headers.cookie, undefined);
      assert.equal(upstream.requests[0].headers.referer, undefined);
      assert.equal(upstream.requests[0].headers.authorization, undefined);
      await assert.rejects(relay.send(target, 'GET', 'loader', { accept: '*/*' }, Buffer.alloc(0), performance.now() + 100, () => true));
      assert.equal(upstream.requests.length, 1);
    } finally { await relay.close(); await upstream.close(); }
  });
}

test('legacy relay has no loader role or new anonymous GET authority', async () => {
  const relay = new HostedRiskRelay('http://127.0.0.1:9');
  await assert.rejects(relay.send('https://static.sandbox.airwallex.com/webapp/fraud/device-fingerprint/index.js'
    + '?sessionId=synthetic&hasScriptId=false&disable3rdTracking=false', 'GET', 'loader',
    { accept: '*/*' }, Buffer.alloc(0), performance.now() + 100, () => true));
  await relay.close();
});

test('instrument observer journals loader before release, then preserves preflight/visitor/event sequencing over local TLS', async () => {
  const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');
  const upstream = await tlsFixture('instrument_pair'), fixture = policyFixture(), durable = journalFixture();
  const original = HostedRiskRelay.prototype.send;
  let loaderInvocations = 0, loaderDeliveries = 0, visitorDeliveries = 0;
  HostedRiskRelay.prototype.send = async function (url, method, role, headers, body, deadline, allowed) {
    if (role !== 'loader') return original.call(this, url, method, role, headers, body, deadline, allowed);
    assert.equal(method, 'GET'); assert.deepEqual(headers, { accept: '*/*' }); assert.equal(body.length, 0);
    assert.equal(allowed(), true);
    assert.deepEqual(durable.messages.map(value => value.phase), ['prepare']);
    assert.equal(durable.messages[0].role, 'loader'); loaderInvocations++;
    return { status: 200, fields: [{ name: 'content-type', value: 'application/javascript' }], body: fixture.body };
  };
  const observer = new HostedRiskObserver(fixture.parent, durable.client, {}, { origin: 'http://127.0.0.1:1234',
    bwsOrigin: upstream.origin, loaderHash: digest(fixture.body), ca: upstream.cert }, performance.now() + 5000, true);
  observer.start(upstream.proxy);
  const frame = { isDetached: () => false, url: () => sourceDocument, evaluate: async () => ({
    id: 'airwallex-fraud-api', orderSession: privateSession, tenant: null, project: null, disableTracking: null }) };
  try {
    await observer.handle({ request: () => ({ frame: () => frame,
      url: () => 'http://127.0.0.1:1234/fingerprint.js?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false',
      method: () => 'GET', resourceType: () => 'script' }),
      fulfill: async () => { assert.equal(durable.messages.at(-1).phase, 'observed'); loaderDeliveries++; },
      abort: async () => assert.fail('Instrument loader withheld') }, { cookies: async () => [] }, null, frame, () => false);
    for (const role of ['visitor', 'event']) {
      const body = role === 'visitor' ? claim().body : eventBody();
      const fields = role === 'visitor' ? visitorHeaders : eventHeaders;
      await observer.handle({ request: () => ({ frame: () => frame,
        url: () => upstream.origin + (role === 'visitor' ? '/bws/v3/visit' : '/bws/v1/' + privateSession),
        method: () => 'POST', resourceType: () => role === 'visitor' ? 'fetch' : 'ping',
        allHeaders: async () => fields, postDataBuffer: () => body }),
        fulfill: async () => { visitorDeliveries++; }, abort: async () => assert.fail('Instrument BWS withheld') },
      { cookies: async () => [] }, null, frame, () => false);
    }
    assert.equal(loaderInvocations, 1); assert.equal(loaderDeliveries, 1); assert.equal(visitorDeliveries, 2);
    assert.deepEqual(durable.messages.filter(value => value.phase === 'prepare').map(value => value.role),
      ['loader', 'preflight', 'visitor', 'event']);
    assert.deepEqual(durable.messages.map(value => value.sequence), [1, 2, 3, 4, 5, 6, 7, 8]);
    assert.equal(upstream.requests.length, 3);
    assert.equal(observer.evidence().risk_telemetry_poisoned, false);
    for (const role of ['preflight', 'visitor', 'event']) assert.equal(observer.evidence()['security_' + role + '_validation'], 'matched');
  } finally { HostedRiskRelay.prototype.send = original; await observer.close(); await upstream.close(); }
});

test('eight concurrent real-journal event admissions retain first withheld result and never send queued work after poison', async () => {
  const { HostedRiskObserver } = require('./hosted-risk-observer.cjs');
  const upstream = await tlsFixture('instrument_events'), fixture = policyFixture(), durable = journalFixture();
  const original = HostedRiskRelay.prototype.send;
  HostedRiskRelay.prototype.send = async function (url, method, role, headers, body, deadline, allowed) {
    if (role !== 'loader') return original.call(this, url, method, role, headers, body, deadline, allowed);
    assert.equal(allowed(), true);
    return { status: 200, fields: [], body: fixture.body };
  };
  const observer = new HostedRiskObserver(fixture.parent, durable.client, {}, { origin: 'http://127.0.0.1:1234',
    bwsOrigin: upstream.origin, loaderHash: digest(fixture.body), ca: upstream.cert }, performance.now() + 5000, true);
  observer.start(upstream.proxy);
  const frame = { isDetached: () => false, url: () => sourceDocument, evaluate: async () => ({
    id: 'airwallex-fraud-api', orderSession: privateSession, tenant: null, project: null, disableTracking: null }) };
  let delivered = 0, aborted = 0;
  try {
    await observer.handle({ request: () => ({ frame: () => frame,
      url: () => 'http://127.0.0.1:1234/fingerprint.js?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false',
      method: () => 'GET', resourceType: () => 'script' }), fulfill: async () => {},
      abort: async () => assert.fail('Synthetic loader withheld') }, { cookies: async () => [] }, null, frame, () => false);
    await Promise.all(Array.from({ length: 8 }, (_, index) => observer.handle({ request: () => ({ frame: () => frame,
      url: () => upstream.origin + '/bws/v1/' + privateSession, method: () => 'POST', resourceType: () => 'ping',
      allHeaders: async () => eventHeaders, postDataBuffer: () => eventBody({ eventId: (index + 1).toString(16).padStart(40, '0') }) }),
      fulfill: async () => { delivered++; }, abort: async () => { aborted++; } },
    { cookies: async () => [] }, null, frame, () => false)));
    const evidence = observer.evidence();
    assert.equal(evidence.risk_event_attempts, 8); assert.equal(evidence.risk_event_client_invocations, 2);
    assert.equal(evidence.risk_event_delivered, 1); assert.equal(delivered, 1); assert.equal(aborted, 7);
    assert.equal(upstream.requests.length, 2); assert.equal(evidence.security_event_validation, 'withheld');
    assert.equal(evidence.security_telemetry_first_failure_role, 'event');
    assert.equal(evidence.security_telemetry_first_failure_phase, 'response_validation');
    assert.deepEqual(durable.messages.filter(value => value.role === 'event').map(value => value.phase),
      ['prepare', 'observed', 'prepare', 'observed']);
    assert.equal(durable.messages.at(-1).response_validation, 'withheld');
    assert.equal(evidence.risk_journal_poisoned, true);
    assert.equal(await observer.close(), false); // The existing bounded failed response marks cleanup unsuccessful.
    assert.equal(observer.evidence().security_event_validation, 'withheld');
    assert.doesNotMatch(JSON.stringify(evidence), /PRIVATE|synthetic_bound_session/u);
  } finally { HostedRiskRelay.prototype.send = original; await observer.close(); await upstream.close(); }
});

// Immutable pre-diagnostic flow: property/clock/ACK/guard order is the compatibility oracle.
const referenceTelemetryFlow = String.raw`    let delivered = false;
    let queued = false;
    try {
      // Exactly the admitted visitor plus eight finite events may wait; sends remain serial.
      if (this.#waiting >= 9) { this.#policy.poison('budget'); return true; }
      this.#waiting++;
      queued = true;
      const task = this.#chain.then(async () => {
        if (this.#closed || closing() || admissionClosed() || !this.#original(frame, main, bound) || !this.#policy.live()) {
          this.#policy.poison('document_changed'); throw new Error('Risk scope is unavailable.');
        }
        const deadline = Math.min(this.#caseDeadline, this.#deadline, performance.now() + this.#parent.normalWindowRemaining());
        const forward = async selected => this.#journal.run(selected, async () => {
          if (this.#closed || closing() || admissionClosed() || !this.#original(frame, main, bound) || !this.#policy.live()) {
            throw new Error('Risk scope is unavailable.');
          }
          this.#policy.invoke(selected.role);
          const response = await this.#relay.send(selected.url, selected.role === 'preflight' ? 'OPTIONS' : 'POST',
            selected.role, selected.headers, selected.body, deadline, this.#instrumentSecurity
              ? () => !this.#closed && !closing() && !admissionClosed() && this.#original(frame, main, bound) : null);
          this.#policy.status(selected.role, response.status);
          return response;
        }, response => this.#policy.validateResponse(selected, response), deadline);
        // Browser route interception omits automatic CORS OPTIONS: perform the exact grant check before the body.
        if (claim.role === 'visitor') {
          const preflight = this.#policy.preflightFor(claim);
          if (preflight !== null) await forward(preflight);
        }
        const response = await forward(claim);
        if (this.#closed || closing() || !this.#original(frame, main, bound) || !this.#policy.live()) {
          throw new Error('Risk scope is unavailable.');
        }
        await this.#value(route.fulfill({ status: response.status,
          headers: this.#policy.responseHeaders(response), body: response.body }));
        delivered = true;
        this.#policy.delivered(claim.role);
      });
      this.#chain = task.catch(() => {});
      await this.#value(task);
    } catch { this.#policy.poison('journal'); }
    finally {
      if (queued) this.#waiting--;
      if (!delivered && !await this.#bounded(Promise.resolve().then(() => route.abort('blockedbyclient')))) {
        this.#cleanupFailed = true; this.#policy.poison('shutdown');
      }
    }
    return true;
  }
`;
const referenceTelemetryClose = String.raw`  async close() {
    this.#closed = true;
    // New-profile teardown can reap completed work inside the case reserve; it grants no send/release.
    const bounded = !this.#instrumentSecurity ? value => this.#bounded(value) : async value => {
      let timer;
      try { return await Promise.race([Promise.resolve(value).then(result => result !== false, () => false),
        new Promise(resolve => { timer = setTimeout(() => resolve(false),
          Math.max(0, Math.min(1000, this.#caseDeadline - performance.now()))); })]); }
      finally { clearTimeout(timer); }
    };
    let clean = this.#relay === undefined || this.#relay === null || await bounded(this.#relay.close());
    clean = await bounded(this.#chain) && clean;
    clean = await bounded(Promise.all([...this.#handlers])) && clean;
    clean = await bounded(Promise.all([...this.#responseDisposals.values()]).then(values => values.every(Boolean))) && clean;
    if (this.#publicClient) {
      clean = await bounded(this.#publicClient.then(client => this.#disposePublic(client))) && clean;
    }
    if (this.#journal) clean = await bounded(this.#journal.close()) && clean;
    if (!clean || this.#cleanupFailed) this.#policy.poison('shutdown');
    return clean && !this.#cleanupFailed;
  }
`;

function diagnosticObserver(overrides, reference = false, mutate = value => value) {
  const Module = require('node:module'), filename = path.join(__dirname, 'hosted-risk-observer.cjs');
  let source = fs.readFileSync(filename, 'utf8');
  if (reference) {
    const a = source.indexOf('    let delivered = false;\n    let queued = false;'), b = source.indexOf('\n  async drainSent()', a);
    source = source.slice(0, a) + referenceTelemetryFlow + source.slice(b);
    const c = source.indexOf('  async close()'), d = source.indexOf('\n  toJSON()', c);
    source = source.slice(0, c) + referenceTelemetryClose + source.slice(d);
  }
  source = source.replace("'use strict';", "'use strict';\nconst performance = require('fixture:clock');");
  source = source.replace(/this\.#cleanupFailed(?!\s*=)/gu, 'this.fixtureCleanup()');
  source = source.replace('  toJSON() {', '  fixtureCleanup() { performance.cleanup(); return this.#cleanupFailed; }\n  toJSON() {');
  source = source.replace('  toJSON() {', `  fixtureValidation(values) {
    const operation = {role:'event',phase:'response_validation'};
    return values.map(value => {this.#validated(operation,value);return this.evidence();});
  }
  toJSON() {`);
  source = mutate(source);
  const loaded = new Module(filename, module); loaded.filename = filename; loaded.paths = Module._nodeModulePaths(__dirname);
  const normal = loaded.require.bind(loaded);
  loaded.require = name => Object.hasOwn(overrides, name) ? overrides[name] : normal(name);
  loaded._compile(source, filename); return loaded.exports.HostedRiskObserver;
}

async function telemetryScenario(fault = '', reference = false, instrument = true, mutate = value => value) {
  const trace = [], values = policyFixture(); let closing = false, roleReads = 0, errorReads = 0;
  const privateError = new Error();
  for (const field of ['name', 'message', 'stack', 'cause', 'PRIVATE']) Object.defineProperty(privateError, field, {
    get() { errorReads++; return 'SYNTHETIC_PRIVATE_ERROR'; },
  });
  const parent = new Proxy(values.parent, { get(target, key) {
    const value = target[key]; return typeof value === 'function' ? (...args) => {
      trace.push('parent.' + key); return value(...args);
    } : value;
  } });
  class Policy extends HostedRiskPolicy {
    constructor(...args) {
      super(...args);
      return new Proxy(this, { get(target, key) {
        const value = target[key];
        return typeof value !== 'function' ? value : (...args) => {
          trace.push('policy.' + key);
          if (key === 'validateResponse' && fault === 'validator_exception') throw privateError;
          if (key === 'preflightFor' && fault === 'preflight_selection') throw privateError;
          const result = Reflect.apply(value, target, args);
          if (key !== 'claim' || !result) return result;
          if (fault === 'queued') closing = true;
          return new Proxy(result, { get(claim, field) {
            trace.push('claim.' + String(field));
            if (field === 'role') roleReads++;
            return claim[field];
          } });
        };
      } });
    }
  }
  class Relay {
    constructor() {} // Fixed synthetic relay only; native TLS coverage is separate below/inside smoke.
    async send(_url, _method, role, _headers, _body, _deadline, allowed) {
      trace.push('relay.' + role);
      if (allowed) assert.equal(allowed(), true);
      if (role === 'loader') return { status: 200, fields: [], body: values.body };
      if (fault === 'relay') throw privateError;
      return { status: 200, body: Buffer.from('{"data":{"visitorID":"SYNTHETIC_PRIVATE"}}'), fields: [
        { name: 'access-control-allow-origin', value: '*' }, { name: 'content-type', value: 'application/json' },
        ...(fault === 'cors' ? [] : [{ name: 'access-control-allow-methods', value: 'POST' },
          { name: 'access-control-allow-headers', value: 'content-type' }]),
      ] };
    }
    async close() {
      trace.push('relay.close');
      if (fault === 'shutdown_pending') return new Promise(resolve => setTimeout(() => resolve(true), 60));
      return fault !== 'relay_close';
    }
  }
  const journal = { evidence: () => ({}), close: async () => { trace.push('journal.close'); return fault !== 'journal_close'; },
    async run(selected, invoke, validate) {
      trace.push('prepare');
      if (selected.role !== 'loader' && fault === 'prepare') throw privateError;
      if (selected.role !== 'loader' && fault === 'invoke') closing = true;
      let response;
      try { response = await invoke(); } catch (error) { trace.push('failed'); throw error; }
      let matched = false;
      try { matched = validate(response) === true; } catch { /* Match the production journal's validator boundary. */ }
      trace.push('observed.' + String(matched));
      if (selected.role !== 'loader' && fault === 'result_ack') throw privateError;
      if (!matched) throw privateError;
      if (selected.role === 'preflight' && fault === 'after_preflight') closing = true;
      return response;
    } };
  const Observer = diagnosticObserver({ './hosted-risk-policy.cjs': { HostedRiskPolicy: Policy, riskCookieNames: new Set() },
    './hosted-risk-relay.cjs': { HostedRiskRelay: Relay }, 'fixture:clock': { now: () => { trace.push('clock'); return 0; }, cleanup: () => trace.push('cleanup.read') } }, reference, mutate);
  const observer = new Observer(parent, journal, { request: { newContext: async () => ({
    get: async () => ({ status: () => 200, body: async () => values.body, headers: () => ({}), dispose: async () => {} }),
    dispose: async () => {},
  }) } }, { origin: 'http://127.0.0.1:1234', bwsOrigin: 'https://127.0.0.1:1235', loaderHash: digest(values.body), ca: Buffer.from('fixture') }, fault === 'shutdown_pending' ? 25 : 10000, instrument);
  observer.start('http://127.0.0.1:1236');
  const frame = { isDetached: () => { trace.push('attached'); return false; }, url: () => { trace.push('frame.url'); return sourceDocument; },
    evaluate: async () => ({ id: 'airwallex-fraud-api', orderSession: privateSession, tenant: null, project: null, disableTracking: null }) };
  await observer.handle({ request: () => ({ frame: () => frame,
    url: () => 'http://127.0.0.1:1234/fingerprint.js?sessionId=' + privateSession + '&hasScriptId=false&disable3rdTracking=false',
    method: () => 'GET', resourceType: () => 'script' }), fulfill: async () => {}, abort: async () => {} },
  { cookies: async () => [] }, null, frame, () => closing);
  trace.length = 0;
  await observer.handle({ request: () => ({ frame: () => frame, url: () => 'https://127.0.0.1:1235/bws/v3/visit',
    method: () => 'POST', resourceType: () => 'fetch', allHeaders: async () => visitorHeaders, postDataBuffer: () => claim().body }),
  fulfill: async () => { trace.push('fulfill'); if (fault === 'fulfill') throw privateError; },
  abort: async () => { trace.push('abort'); if (fault === 'abort') throw privateError; } },
  { cookies: async () => [] }, null, frame, () => { trace.push('closing'); return closing; });
  const beforeClose = observer.evidence();
  const clean = await observer.close(), evidence = observer.evidence();
  assert.equal(errorReads, 0);
  return { trace, beforeClose, evidence, clean, roleReads, observer };
}

const withoutTelemetryDiagnostics = value => Object.fromEntries(Object.entries(value)
  .filter(([key]) => !key.startsWith('security_telemetry_') && !/^security_(preflight|visitor|event)_validation$/u.test(key)));
for (const fault of ['', 'queued', 'prepare', 'invoke', 'cors', 'validator_exception', 'preflight_selection', 'result_ack', 'after_preflight', 'relay', 'fulfill', 'relay_close', 'journal_close']) {
  test('telemetry diagnostics preserve immutable property/clock/control trace: ' + (fault || 'matched'), async () => {
    const before = await telemetryScenario(fault, true), after = await telemetryScenario(fault);
    assert.deepEqual(after.trace, before.trace);
    assert.equal(after.clean, before.clean);
    assert.deepEqual(withoutTelemetryDiagnostics(after.evidence), before.evidence);
    if (fault === 'queued') {
      assert.equal(after.roleReads, 0);
      assert.equal(Object.hasOwn(after.evidence, 'security_telemetry_first_failure_role'), false);
      assert.equal(after.evidence.security_telemetry_first_failure_gate, 'caller_closing');
    }
    assert.doesNotMatch(JSON.stringify(after.evidence), /SYNTHETIC_PRIVATE|PRIVATE_OPTIONS|synthetic_bound_session/u);
  });
}

test('preflight200 distinguishes grant withholding from matched internal delivery0, without a visitor retry', async () => {
  const pass = (await telemetryScenario()).evidence, fail = (await telemetryScenario('cors')).evidence;
  assert.equal(pass.risk_preflight_http_status, 200); assert.equal(pass.risk_preflight_delivered, 0);
  assert.equal(pass.security_preflight_validation, 'matched'); assert.equal(pass.security_visitor_validation, 'matched');
  assert.equal(fail.risk_preflight_http_status, 200); assert.equal(fail.security_preflight_validation, 'withheld');
  assert.equal(fail.risk_visitor_client_invocations, 0);
  assert.equal(fail.security_telemetry_first_failure_role, 'preflight');
  assert.equal(fail.security_telemetry_first_failure_phase, 'response_validation');
});

test('validation and coarse ACK intervals do not become storage or later cleanup causes', async () => {
  const thrown = (await telemetryScenario('validator_exception')).evidence;
  assert.equal(thrown.security_preflight_validation, 'exception');
  assert.equal(thrown.security_telemetry_first_failure_phase, 'response_validation');
  const ack = (await telemetryScenario('result_ack')).evidence;
  assert.equal(ack.security_preflight_validation, 'matched');
  assert.equal(ack.security_telemetry_first_failure_phase, 'result_ack');
  const closed = (await telemetryScenario('after_preflight')).evidence;
  assert.equal(closed.security_preflight_validation, 'matched');
  assert.equal(closed.security_telemetry_first_failure_role, 'visitor');
  assert.equal(closed.security_telemetry_first_failure_phase, 'invoke_guard');
  assert.equal(closed.risk_visitor_client_invocations, 0);
});

test('per-role validation keeps first nonmatched across eight memo observations without reopening a journal', async () => {
  for (const sequence of [
    ['matched', 'withheld', 'matched', 'exception', 'matched', 'withheld', 'matched', 'exception'],
    ['matched', 'exception', 'withheld', 'matched'], ['matched', 'matched'],
  ]) {
    const { observer } = await telemetryScenario();
    const states = observer.fixtureValidation(sequence);
    const first = sequence.findIndex(value => value !== 'matched');
    for (let index = 0; index < states.length; index++) {
      assert.equal(states[index].security_event_validation, first >= 0 && index >= first ? sequence[first] : 'matched');
    }
  }
});

test('legacy telemetry raw evidence stays byte-identical even when private callbacks fail', async () => {
  for (const fault of ['', 'queued', 'cors', 'result_ack', 'relay_close']) {
    const before = await telemetryScenario(fault, true, false), after = await telemetryScenario(fault, false, false);
    assert.equal(JSON.stringify(after.evidence), JSON.stringify(before.evidence));
    assert.deepEqual(after.trace, before.trace);
  }
});

for (const [name, before, after] of [
  ['latest result overwrites first nonmatch', "if (this.#validation[key] === undefined || this.#validation[key] === 'matched')", 'if (true)'],
  ['first matched hides later failure', "if (this.#validation[key] === undefined || this.#validation[key] === 'matched')", 'if (this.#validation[key] === undefined)'],
]) {
  test('a compiled validation-latch mutant fails the mixed-sequence acceptance: ' + name, async () => {
    const f = await telemetryScenario('', false, true, source => { assert.ok(source.includes(before)); return source.replace(before, after); });
    assert.throws(() => {
      const states = f.observer.fixtureValidation(['matched', 'withheld', 'matched', 'exception']);
      assert.equal(states[1].security_event_validation, 'withheld');
      assert.equal(states[3].security_event_validation, 'withheld');
    }, { code: 'ERR_ASSERTION' });
  });
}

test('a compiled early role-read mutant fails queued-failure privacy and ordering acceptance', async () => {
  const f = await telemetryScenario('queued', false, true, source => {
    const before = "const operation = { phase: 'queued_guard' };";
    assert.ok(source.includes(before)); return source.replace(before, "const operation = { phase: 'queued_guard', role: claim.role };");
  });
  assert.throws(() => assert.equal(f.roleReads, 0), { code: 'ERR_ASSERTION' });
});

test('shutdown memo respects original short-circuit and never probes an unreached prior-cleanup flag', async () => {
  const failed = await telemetryScenario('relay_close');
  assert.equal(failed.evidence.security_telemetry_shutdown_failure_stage, 'relay_close');
  assert.equal(failed.trace.filter(value => value === 'cleanup.read').length, 0);
  const prior = await telemetryScenario('fulfill');
  assert.equal(prior.evidence.security_telemetry_shutdown_failure_stage, 'prior_cleanup_failed');
  assert.equal(prior.trace.filter(value => value === 'cleanup.read').length, 2);
});

test('unreached phase remains absent and later close cannot backfill it', async () => {
  const f = await telemetryScenario('preflight_selection');
  for (const key of ['phase', 'gate', 'role']) assert.equal(Object.hasOwn(f.evidence, 'security_telemetry_first_failure_' + key), false);
  assert.equal(f.evidence.security_telemetry_shutdown_failure_stage, 'prior_cleanup_failed');
});

test('existing bounded shutdown reports stalled relay await without a new send or a larger bound', async () => {
  const started = performance.now(), f = await telemetryScenario('shutdown_pending');
  assert.equal(f.clean, false);
  assert.equal(f.evidence.security_telemetry_shutdown_failure_stage, 'relay_close');
  assert.equal(f.trace.filter(value => value === 'relay.visitor').length, 1);
  assert.ok(performance.now() - started < 200);
  await new Promise(resolve => setTimeout(resolve, 65)); // Settle the synthetic late close before returning.
});

test('compiled Error-property reader is rejected by the zero-read privacy assertion', async () => {
  await assert.rejects(telemetryScenario('validator_exception', false, true, source => {
    const original = "this.#validated(part, 'exception');";
    assert.ok(source.includes(original));
    return source.replace(original, "void error.message; this.#validated(part, 'exception');");
  }), { code: 'ERR_ASSERTION' });
});

test('HTTP-only validation and overwritten-first-failure mutants fail the reached-outcome acceptance', async () => {
  for (const [before, after, expected] of [
    ['const matched = this.#policy.validateResponse(selected, response);',
      'const matched = (this.#policy.validateResponse(selected, response), response.status === 200);',
      evidence => assert.equal(evidence.security_preflight_validation, 'withheld')],
    ['!this.#instrumentSecurity || this.#telemetryFailure !== null', '!this.#instrumentSecurity',
      evidence => assert.equal(evidence.security_telemetry_first_failure_phase, 'response_validation')],
  ]) {
    const f = await telemetryScenario('cors', false, true, source => {
      assert.ok(source.includes(before)); return source.replace(before, after);
    });
    assert.throws(() => expected(f.evidence), { code: 'ERR_ASSERTION' });
  }
});

test('late-invocation and invented-cleanup mutants fail the original closed-scope assertions', async () => {
  const late = await telemetryScenario('after_preflight', false, true, source => {
    const before = "|| this.#telemetryReject(part, closing(), 'caller_closing')";
    assert.ok(source.includes(before)); return source.replace(before, '|| false');
  });
  assert.throws(() => assert.equal(late.evidence.risk_visitor_client_invocations, 0), { code: 'ERR_ASSERTION' });
  const cleanup = await telemetryScenario('relay_close', false, true, source => {
    const before = 'return clean && !this.fixtureCleanup();';
    assert.ok(source.includes(before)); return source.replace(before, 'return true;');
  });
  assert.throws(() => assert.equal(cleanup.clean, false), { code: 'ERR_ASSERTION' });
});

test('coherent selected-profile raw production outputs stay below8192 before PHP filtering', t => {
  const { boundDocumentWorkerFixture, sanitizeBoundDocumentFixture } = require('./hosted-smoke.cjs');
  const policy = fs.readFileSync(path.join(__dirname, 'hosted-policy.cjs'), 'utf8');
  const names = source => [...source.matchAll(/'([^']+)'/gu)].map(value => value[1]);
  const render = names(policy.match(/const renderCheckNames = Object.freeze\(\[([\s\S]*?)\]\);/u)[1]);
  const reasonSource = policy.match(/const readDenialReasons = Object.freeze\(\{([\s\S]*?)\n\}\);/u)[1];
  const reasons = Object.fromEntries([...reasonSource.matchAll(/(\w+): Object.freeze\(\[([\s\S]*?)\]\)/gu)]
    .map(match => [match[1], Object.fromEntries(names(match[2]).map(name => [name, 1000000]))]));
  assert.equal(Object.values(reasons).reduce((total, values) => total + Object.keys(values).length, 0), 56);
  const normal = { normal_read_inspection_completed: true, normal_read_window_closed: true,
    normal_checkout_status_poisoned: true, normal_consent_list_items: 100,
    normal_consent_list_pagination_known: true, normal_consent_list_has_more: false,
    normal_checkout_status_state: 'REQUESTED', normal_read_failure: {
      role: 'checkout_status', phase: 'normal_response_dispose', deadline_fired: false, shutdown_started: false,
      browser_context_close_started: false, browser_context_closed: false, request_context_dispose_started: false,
      request_context_disposed: false, window_closed: false, window_remaining_ms: 1000,
      elapsed_ms: 60000, request_timeout_ms: 8000, request_elapsed_ms: 60000,
    } };
  for (const [role, cap] of [['customer', 1], ['consent_list', 1], ['country', 1], ['checkout_status', 3]]) {
    for (const count of ['attempts', 'sends', 'delivered']) normal['normal_' + role + '_' + count] = cap;
    normal['normal_' + role + '_observation'] = 'matched'; normal['normal_' + role + '_http_status'] = 200;
  }
  // A completed normal/method snapshot precedes the final scoped stop. Generic diagnostic counters use
  // their closed saturation ceiling; role counts respect1/3/8, and the last event is not page-delivered.
  const risk = { instrument_security_requested: true, instrument_ready: false, financial_permission: false,
    security_graph_complete: false, security_loader_candidate: true, security_loader_promoted: true,
    security_loader_wait_reason: 'duplicate', security_source_first_stop: 'bound_navigated',
    security_loader_validation: 'matched', security_same_document_requested: true,
    security_same_document_retained: true, security_same_document_reason: 'retained_once',
    security_bound_navigation_original_relation: 'fragment_only_change',
    security_bound_navigation_previous_relation: 'fragment_only_change',
    security_bound_document_reference: 'captured_before_stop', security_bound_document_continuity: 'same',
    security_bound_document_cleanup: 'complete', risk_bootstrap_requested: true, risk_graph_complete: false,
    risk_financial_ready: false, risk_loader_attempted: true, risk_loader_verified: true,
    risk_telemetry_poisoned: true, risk_journal_poisoned: false, risk_journal_closed: true,
    risk_partial_reason: 'unknown_dependency', risk_journal_prepared: 11, risk_journal_results: 11,
    risk_denied_dependencies: Object.fromEntries(['risk_document', 'captcha', 'forter', 'sardine', 'monitoring', 'other'].map(name => [name, 1000000])),
    security_preflight_validation: 'matched', security_visitor_validation: 'matched', security_event_validation: 'matched',
    security_telemetry_first_failure_phase: 'before_delivery',
    security_telemetry_first_failure_gate: 'original_source_unavailable', security_telemetry_first_failure_role: 'event',
    security_telemetry_shutdown_failure_stage: 'prior_cleanup_failed' };
  for (const [role, cap] of [['visitor', 1], ['preflight', 1], ['event', 8]]) {
    risk['risk_' + role + '_attempts'] = cap; risk['risk_' + role + '_client_invocations'] = cap;
    risk['risk_' + role + '_delivered'] = role === 'preflight' ? 0 : role === 'event' ? 7 : 1;
    risk['risk_' + role + '_http_status'] = 200;
  }
  const rawFacts = { candidateOrigin: 'sandbox', methodSendStarted: true, useProductionResult: true, browserVersion: '999.9999.99999.9999', scopeStop: 'bound_navigated', firstFailure: 'normal_response_dispose',
    result: { navigation_passed: true, test_mode_visible: true, sandbox_origin_verified: false,
      checkout_token_matches: true, payer_request_scope_verified: true, browser_version: '999.9999.99999.9999' },
    counts: Object.fromEntries(['blocked_requests', 'blocked_websockets', 'returns_intercepted',
      'verified_static_assets', 'integrity_rejections', 'verified_render_assets', 'controller_replays_blocked'].map(name => [name, 1000000])),
    proxy: { rejected: 1000000, connected: 1000000, externalConnected: 0 },
    binding: { checkout_token_matches: true, payer_request_scope_verified: true, payer_get_attempted: true,
      payer_customer_mapping_verified: false, payer_payload_delivered: true, financial_routing_verified: false,
      render_requested: true, render_frame_binding_available: true },
    payer: { payer_observation: 'matched', payer_response_observed: true, payer_mode_matches: true,
      payer_currency_matches: true, payer_http_status: 200 },
    normal, normalPolicy: { normal_reads_requested: true },
    method: { method_config_response_observed: true, method_config_payload_delivered: true,
      method_config_http_status: 200, method_config_observation: 'matched', method_config_items: 200,
      method_config_pagination_known: true, method_config_has_more: false, method_config_card_advertised: true },
    methods: { method_config_requested: true, method_config_get_attempted: true, read_denial_reasons: reasons,
      read_denial_counts_capped: true, denied_read_families: Object.fromEntries(['method_config', 'country', 'customer', 'consent_list', 'checkout_status', 'other_pa'].map(name => [name, 1000000])) },
    render: { render_frame_checks: Object.fromEntries(render.map(name => [name, ['qualified', 'fulfilled'].includes(name) ? 1 : 1000000])) },
    readiness: { card_readiness_observed: true }, attachment: { readiness_attachment_field: 'cardnumber',
      readiness_frame_detached_before_count: false, readiness_frame_detached_after_count: false } };
  Object.assign(rawFacts.counts, { bound_render_frames: 1, controller_asset_requests: 1, verified_controller_assets: 1, controller_http_status: 200 });
  for (const field of ['cardnumber', 'expiry', 'cvc']) for (const flag of ['unique', 'visible', 'enabled']) rawFacts.readiness[field + '_' + flag] = false;
  Object.assign(rawFacts.result, rawFacts.binding, rawFacts.payer, rawFacts.method, rawFacts.readiness, rawFacts.attachment, { method_config_send_started: true });
  const main = {}, page = { on() {}, mainFrame: () => main };
  const worker = boundDocumentWorkerFixture(page, performance.now() + 40000, { rawFacts, journalFacts: risk });
  const bound = value => { const bytes = Buffer.byteLength(JSON.stringify(value)); assert.ok(bytes <= 8192, 'RAW child output exceeds8192'); return bytes; };
  for (const failure of [false, true]) {
    const raw = worker.output(failure);
    assert.equal(raw.security_event_validation, 'matched'); assert.equal(raw.read_denial_reasons.customer.header_trace, 1000000);
    const bytes = bound(raw); assert.ok(bytes > 6000); // This measures the actual production expressions, not only PHP-safe output.
    t.diagnostic((failure ? 'failure' : 'success') + '_raw_bytes=' + bytes);
    const safe = sanitizeBoundDocumentFixture(raw, true);
    assert.equal(safe.security_telemetry_first_failure_gate, 'original_source_unavailable');
    // A conservative size envelope also includes mutually exclusive loader-failure fields and longer
    // closed labels. It is not presented as another coherent observation or passed off as provider data.
    const envelope = { ...raw, security_loader_validation: 'not_reached',
      security_loader_first_failure_phase: 'response_validation', security_loader_first_failure_gate: 'scope_window_not_positive',
      security_loader_scope_stop_at_failure: 'admission_loader_window_elapsed',
      security_source_first_stop: 'admission_loader_window_elapsed', security_bound_document_cleanup: 'unavailable',
      security_bound_document_continuity: 'not_captured', security_telemetry_first_failure_phase: 'response_validation' };
    t.diagnostic((failure ? 'failure' : 'success') + '_conservative_envelope_bytes=' + bound(envelope));
    const padded = { ...raw, SYNTHETIC_PRIVATE_UNUSED: 'x'.repeat(8192) };
    assert.throws(() => bound(padded), { code: 'ERR_ASSERTION' });
    assert.equal(Object.hasOwn(sanitizeBoundDocumentFixture(padded, true), 'SYNTHETIC_PRIVATE_UNUSED'), false);
  }
});
