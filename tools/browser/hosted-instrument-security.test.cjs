'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { InstrumentSecurityScope, PendingLoader } = require('./hosted-pending-loader.cjs');
const { publicAddress, publicConnectionTarget, sameAddress } = require('./hosted-policy.cjs');
const util = require('node:util');

function fixture() {
  let clock = 0, bound = false, detached = false, generation = 0, sends = 0, acks = 0, aborts = 0;
  const parent = { renderEnabled: () => true, bindingEvidence: () => ({ payer_payload_delivered: true }),
    normalEvidence: () => ({ normal_reads_requested: true }), normalSourceMatches: value => value === 'PRIVATE_DOCUMENT',
    riskSessionMatches: value => bound && value === 'PRIVATE_SESSION' };
  const scope = new InstrumentSecurityScope(parent, 40000, () => clock);
  const frame = { url: () => 'PRIVATE_DOCUMENT', isDetached: () => detached };
  const risk = { loaderTarget: value => value.startsWith('https://static.sandbox.airwallex.com/webapp/fraud/device-fingerprint/index.js?'),
    handle: async () => { acks++; sends++; return true; } };
  const pending = new PendingLoader(risk, scope);
  const request = { frame: () => frame, url: () => 'https://static.sandbox.airwallex.com/webapp/fraud/device-fingerprint/index.js?sessionId=PRIVATE_SESSION&hasScriptId=false&disable3rdTracking=false',
    method: () => 'GET', resourceType: () => 'script' };
  const route = { request: () => request, abort: async () => { aborts++; } };
  const handle = () => pending.handle(route, {}, () => ({ main: frame, bound: bound ? {} : undefined }),
    () => false, () => generation);
  return { parent, scope, pending, request, handle, bind: () => { bound = true; },
    detach: () => { detached = true; }, navigate: () => { generation++; }, clock: value => { clock = value; },
    counts: () => ({ sends, acks, aborts }) };
}

test('new profile has independent immutable 40/30/38/15 bounds and permanent closure', () => {
  const f = fixture();
  assert.equal(f.scope.admissionRemaining(), 30000);
  f.clock(12000); f.scope.enter();
  assert.equal(f.scope.admissionRemaining(), 15000);
  f.clock(25000); f.scope.enter(); // No timer reset.
  assert.equal(f.scope.admissionRemaining(), 2000);
  f.clock(27000); assert.equal(f.scope.normalWindowRemaining(), 0);
  f.scope.close(); f.clock(0); assert.equal(f.scope.admissionRemaining(), 0);
  assert.deepEqual(f.scope.evidence(), { instrument_security_requested: true, instrument_ready: false,
    financial_permission: false, security_graph_complete: false });
  assert.throws(() => JSON.stringify(f.scope));
  assert.doesNotMatch(util.inspect(f.scope), /PRIVATE_SESSION|PRIVATE_DOCUMENT/u);
});

test('early original loader waits privately with zero ACK/send until later exact frame/session proof', async () => {
  const f = fixture(), task = f.handle();
  await new Promise(resolve => setTimeout(resolve, 25));
  assert.deepEqual(f.counts(), { sends: 0, acks: 0, aborts: 0 });
  f.bind(); assert.equal(await task, true);
  assert.deepEqual(f.counts(), { sends: 1, acks: 1, aborts: 0 });
  assert.equal(f.pending.evidence().security_loader_promoted, true);
  assert.throws(() => JSON.stringify(f.pending));
});

for (const fault of ['detach', 'same_url_reload', 'cutoff', 'duplicate', 'wrong_session']) {
  test('unbound original candidate cannot be replaced or sent after ' + fault, async () => {
    const f = fixture();
    if (fault === 'wrong_session') f.request.url = () => 'https://static.sandbox.airwallex.com/webapp/fraud/device-fingerprint/index.js?sessionId=OTHER&hasScriptId=false&disable3rdTracking=false';
    const task = f.handle();
    await new Promise(resolve => setTimeout(resolve, 15));
    if (fault === 'detach') f.detach();
    if (fault === 'same_url_reload') f.navigate();
    if (fault === 'cutoff') f.clock(30001);
    if (fault === 'duplicate') await f.handle();
    f.bind(); await task;
    assert.equal(f.counts().sends, 0); assert.equal(f.counts().acks, 0);
    assert.ok(f.counts().aborts >= 1);
    await f.handle(); assert.equal(f.counts().sends, 0);
  });
}

for (const value of ['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '192.0.2.1',
  '198.18.0.1', '224.0.0.1', '255.255.255.255', '::1', '::ffff:8.8.8.8', 'fe80::1', 'fc00::1', '2001:db8::1', '2002::1']) {
  test('public-only connection policy rejects nonpublic/mapped address ' + value, () => assert.equal(publicAddress(value), false));
}

test('DNS admission rejects an entire mixed answer set and pins a single permitted address', async () => {
  let calls = 0;
  await assert.rejects(publicConnectionTarget('static.sandbox.airwallex.com', async () => {
    calls++; return [{ address: '8.8.8.8', family: 4 }, { address: '127.0.0.1', family: 4 }];
  }));
  const selected = await publicConnectionTarget('static.sandbox.airwallex.com', async () => {
    calls++; return [{ address: '8.8.8.8', family: 4 }, { address: '1.1.1.1', family: 4 }];
  });
  assert.deepEqual(selected, { address: '8.8.8.8', family: 4 }); assert.equal(calls, 2);
  assert.equal(sameAddress('8.8.8.8', selected.address), true);
  assert.equal(sameAddress('127.0.0.1', selected.address), false);
  assert.equal(sameAddress('::ffff:8.8.8.8', selected.address), false);
  for (const host of ['127.0.0.1', '[::1]', 'PRIVATE_USER@airwallex.com', 'airwallex.com/']) {
    await assert.rejects(publicConnectionTarget(host, async () => assert.fail('Forbidden DNS invoked')));
  }
});

test('the actual proxy connection pins the selected public address and checks its connected peer before CONNECT ACK', async () => {
  const net = require('node:net'), dns = require('node:dns/promises'), http = require('node:http');
  const { PassThrough } = require('node:stream');
  const { egressProxy } = require('./hosted-policy.cjs');
  const originalLookup = dns.lookup, originalConnect = net.connect;
  let connected = [], peer = '127.0.0.1';
  const proxy = await egressProxy({ allowsAuthority: (host, port) => host === 'static.sandbox.airwallex.com'
    && String(port) === '443', allowsHttp: () => false }, true);
  try {
    dns.lookup = async () => [{ address: '8.8.8.8', family: 4 }];
    net.connect = (options, callback) => {
      connected.push(options); const socket = new PassThrough();
      Object.defineProperty(socket, 'remoteAddress', { value: peer });
      setImmediate(callback); return socket;
    };
    const probe = () => new Promise(resolve => {
      const request = http.request(proxy.server, { method: 'CONNECT', path: 'static.sandbox.airwallex.com:443' });
      request.on('error', () => resolve(false));
      request.on('connect', (response, socket) => { socket.destroy(); resolve(response.statusCode === 200); });
      request.end();
    });
    assert.equal(await probe(), false);
    peer = '8.8.8.8'; assert.equal(await probe(), true);
    assert.deepEqual(connected, [{ host: '8.8.8.8', port: 443, family: 4 }, { host: '8.8.8.8', port: 443, family: 4 }]);
    assert.equal(proxy.counts.connected, 1);
    assert.equal(proxy.counts.externalConnected, 1);
  } finally { dns.lookup = originalLookup; net.connect = originalConnect; await proxy.close(); }
});

test('admission closes at 30 seconds while retained responses remain bounded by the 38-second release latch', () => {
  const f = fixture(); f.clock(25000); f.scope.enter();
  assert.equal(f.scope.admissionRemaining(), 5000);
  assert.equal(f.scope.normalWindowRemaining(), 13000);
  f.clock(30000); assert.equal(f.scope.admissionRemaining(), 0);
  assert.equal(f.scope.normalWindowRemaining(), 8000);
  f.clock(38000); assert.equal(f.scope.normalWindowRemaining(), 0);
  f.clock(40000); assert.equal(f.scope.normalWindowRemaining(), 0);
});
