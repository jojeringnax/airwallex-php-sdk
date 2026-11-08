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
      if (mode === 'redirect') { response.writeHead(302, { location: 'https://unknown.invalid/PRIVATE' }).end(); return; }
      if (mode === 'large_header') { response.writeHead(200, { 'X-Large': 'x'.repeat(17000) }).end(); return; }
      if (mode === 'large_declared') { response.writeHead(200, { 'content-length': '65537' }).flushHeaders(); return; }
      if (mode === 'aborted') { response.writeHead(200, { 'content-length': '40' }); response.write('x'); response.destroy(); return; }
      if (mode === 'trickle') {
        response.writeHead(200); const timer = setInterval(() => response.write('x'), 5);
        response.on('close', () => clearInterval(timer)); return;
      }
      response.writeHead(200, { 'content-type': 'application/json', 'access-control-allow-origin': '*' });
      response.end(mode === 'large_body' ? 'x'.repeat(65537) : '{"data":{"visitorID":"PRIVATE_VISITOR"}}');
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
