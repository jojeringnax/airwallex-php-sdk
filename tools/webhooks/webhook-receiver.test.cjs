'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const net = require('node:net');
const crypto = require('node:crypto');
const { createReceiver, atomic, privateDirectory, configValid } = require('./webhook-receiver.cjs');

const PRIVATE_PATH = `/${'a'.repeat(64)}`;
function fixture() {
  const folder = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'sdk-webhook-local-'))); fs.chmodSync(folder, 0o700);
  return { folder, path: PRIVATE_PATH, context: 'b'.repeat(64), source: 'c'.repeat(64), duration_ms: 1000 };
}
async function cleanup(receiver, folder) {
  if (receiver) await receiver.close().catch(() => {});
  fs.rmSync(folder, { recursive: true, force: true });
}
function request(port, body, headers = {}, url = PRIVATE_PATH, method = 'POST') {
  return new Promise(resolve => {
    const req = http.request({ host: '127.0.0.1', port, method, path: url,
      headers: { 'x-timestamp': '0001830297600000', 'x-signature': 'a'.repeat(64), ...headers } }, res => {
      let response = ''; res.on('data', bytes => { response += bytes; });
      res.on('end', () => resolve({ status: res.statusCode, response }));
      res.on('error', () => resolve({ status: 0 }));
    });
    req.on('error', () => resolve({ status: 0 })); req.end(body);
  });
}
function raw(port, text) {
  return new Promise(resolve => {
    const socket = net.connect(port, '127.0.0.1');
    socket.on('connect', () => socket.write(text));
    socket.on('data', () => {});
    socket.on('error', () => {}); socket.on('close', resolve);
  });
}

test('raw bytes/single header values/receipt clock are durable before empty200, without unknown headers', async () => {
  const config = fixture(); const receiver = await createReceiver(config, { clock: () => 1830297600123 });
  receiver.arm('d'.repeat(64));
  const body = Buffer.from(' {"name":"payment_intent.created","decimal":1.2300,"unicode":"€"}\n', 'utf8');
  const timestamp = '0001830297600000';
  const signature = crypto.createHmac('sha256', 'synthetic_fixture_secret').update(timestamp).update(body).digest('hex');
  try {
    const response = await request(receiver.port, body, { 'x-signature': signature,
      Authorization: 'SYNTHETIC_PRIVATE_HEADER', Cookie: 'SYNTHETIC_PRIVATE_COOKIE' });
    assert.equal(response.status, 200); assert.equal(response.response, '');
    const stored = fs.readFileSync(path.join(config.folder, 'capture-000001.body'));
    const meta = JSON.parse(fs.readFileSync(path.join(config.folder, 'capture-000001.json'), 'utf8'));
    assert.deepEqual(stored, body); assert.equal(meta.timestamp, timestamp); assert.equal(meta.signature, signature);
    assert.equal(meta.received_at_ms, 1830297600123); assert.equal(meta.registration, 'd'.repeat(64));
    assert.equal(meta.sha256, crypto.createHash('sha256').update(body).digest('hex'));
    assert.equal(fs.statSync(path.join(config.folder, 'capture-000001.body')).mode & 0o777, 0o600);
    assert.equal(fs.statSync(path.join(config.folder, 'capture-000001.json')).mode & 0o777, 0o600);
    assert(!JSON.stringify(meta).includes('SYNTHETIC_PRIVATE')); assert.equal(receiver.stats().stored, 1);
  } finally { await cleanup(receiver, config.folder); }
});

test('correct transfer decoding preserves entity bytes rather than chunk framing', async () => {
  const config = fixture(); const receiver = await createReceiver(config); receiver.arm('d'.repeat(64));
  try {
    await raw(receiver.port, `POST ${PRIVATE_PATH} HTTP/1.1\r\nHost: localhost\r\nx-timestamp: 1\r\nx-signature: ${'a'.repeat(64)}\r\nTransfer-Encoding: chunked\r\n\r\n4\r\na\u0000bc\r\n0\r\n\r\n`);
    assert.deepEqual(fs.readFileSync(path.join(config.folder, 'capture-000001.body')), Buffer.from('a\u0000bc'));
  } finally { await cleanup(receiver, config.folder); }
});

for (const [name, headers] of [
  ['duplicate timestamp', { 'x-timestamp': ['1', '2'] }], ['duplicate signature', { 'x-signature': ['a', 'b'] }],
  ['compressed', { 'content-encoding': 'gzip' }], ['length cap', { 'content-length': '65537' }],
]) test(`${name} is rejected before capture`, async () => {
  const config = fixture(); const receiver = await createReceiver(config); receiver.arm('d'.repeat(64));
  try {
    const response = await request(receiver.port, Buffer.alloc(0), headers);
    assert.notEqual(response.status, 200); assert.equal(receiver.stats().stored, 0);
    assert(!fs.existsSync(path.join(config.folder, 'capture-000001.body')));
  } finally { await cleanup(receiver, config.folder); }
});

test('unknown path/query/method/unarmed traffic never creates a raw capture', async () => {
  const config = fixture(); const receiver = await createReceiver(config);
  try {
    assert.notEqual((await request(receiver.port, Buffer.from('SYNTHETIC_PRIVATE'))).status, 200);
    receiver.arm('d'.repeat(64));
    for (const [url, method] of [['/unknown', 'POST'], [`${PRIVATE_PATH}?private=1`, 'POST'], [PRIVATE_PATH, 'GET']]) {
      assert.notEqual((await request(receiver.port, Buffer.from('SYNTHETIC_PRIVATE'), {}, url, method)).status, 200);
    }
    assert.equal(receiver.stats().stored, 0); assert.equal(receiver.stats().refused, 4);
  } finally { await cleanup(receiver, config.folder); }
});

test('CL/TE ambiguity and oversized headers are rejected by the parser without payload retention', async () => {
  const config = fixture(); const receiver = await createReceiver(config); receiver.arm('d'.repeat(64));
  try {
    await raw(receiver.port, `POST ${PRIVATE_PATH} HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n`);
    await raw(receiver.port, `POST ${PRIVATE_PATH} HTTP/1.1\r\nHost: localhost\r\nx-private: ${'p'.repeat(17000)}\r\n\r\n`);
    assert.equal(receiver.stats().stored, 0); assert.equal(receiver.stats().invalid, 2);
  } finally { await cleanup(receiver, config.folder); }
});

test('body cap and maximum16 captures are enforced with no overwrite', async () => {
  const config = fixture(); const receiver = await createReceiver(config); receiver.arm('d'.repeat(64));
  try {
    assert.notEqual((await request(receiver.port, Buffer.alloc(65537), { 'Transfer-Encoding': 'chunked' })).status, 200);
    assert.equal(receiver.stats().limit_failure, true);
    for (let index = 0; index < 15; ++index) assert.equal((await request(receiver.port, Buffer.from(`${index}`))).status, 200);
    assert.notEqual((await request(receiver.port, Buffer.from('overflow'))).status, 200);
    assert.equal(receiver.stats().stored, 15); assert.equal(fs.readFileSync(path.join(config.folder, 'capture-000002.body'), 'utf8'), '0');
  } finally { await cleanup(receiver, config.folder); }
});

test('slow positive progress is bounded independently of idle progress', async () => {
  const config = fixture(); const receiver = await createReceiver(config, { deadlineMs: 30 }); receiver.arm('d'.repeat(64));
  try {
    await new Promise(resolve => {
      const req = http.request({ host: '127.0.0.1', port: receiver.port, method: 'POST', path: PRIVATE_PATH,
        headers: { 'x-timestamp': '1', 'x-signature': 'a'.repeat(64), 'Content-Length': '100' } });
      const timer = setInterval(() => req.write('x'), 5);
      req.on('response', res => { res.resume(); res.on('end', () => { clearInterval(timer); resolve(); }); });
      req.on('error', () => { clearInterval(timer); resolve(); }); req.write('x');
    });
    assert.equal(receiver.stats().stored, 0); assert.equal(receiver.stats().stream_failure, true);
  } finally { await cleanup(receiver, config.folder); }
});

test('failed fsync/close cannot acknowledge persistence or leak private errors', async () => {
  for (const failing of ['sync', 'close']) {
    const config = fixture();
    const io = { ...fs.promises, async open(...args) {
      const file = await fs.promises.open(...args);
      return new Proxy(file, { get(target, key) {
        if (key === failing) return async () => { if (key === 'close') await target.close(); throw new Error('SYNTHETIC_PRIVATE_ERROR'); };
        const value = Reflect.get(target, key); return typeof value === 'function' ? value.bind(target) : value;
      } });
    } };
    const receiver = await createReceiver(config, { io }); receiver.arm('d'.repeat(64));
    try {
      assert.notEqual((await request(receiver.port, Buffer.from('private'))).status, 200);
      assert.equal(receiver.stats().persistence_failure, true);
    } finally { await cleanup(receiver, config.folder); }
  }
});

test('exclusive finalization and private directory constraints', async () => {
  const config = fixture();
  try {
    await atomic(config.folder, 'proof', Buffer.from('original'));
    await assert.rejects(atomic(config.folder, 'proof', Buffer.from('replacement')));
    assert.equal(fs.readFileSync(path.join(config.folder, 'proof'), 'utf8'), 'original');
    fs.symlinkSync('/no/fixture', path.join(config.folder, '.git')); assert.throws(() => privateDirectory(config.folder));
    fs.unlinkSync(path.join(config.folder, '.git')); fs.chmodSync(config.folder, 0o755);
    await assert.rejects(createReceiver(config));
  } finally { fs.rmSync(config.folder, { recursive: true, force: true }); }
});

test('closed config rejects arbitrary path/context/duration and receipt clock must be safe', async () => {
  const config = fixture();
  try {
    for (const delta of [{ path: '/arbitrary' }, { context: 'raw-account' }, { duration_ms: 300001 }]) assert.equal(configValid({ ...config, ...delta }), false);
    const receiver = await createReceiver(config, { clock: () => NaN }); receiver.arm('d'.repeat(64));
    assert.notEqual((await request(receiver.port, Buffer.from('x'))).status, 200);
    assert.equal(receiver.stats().stream_failure, true); await receiver.close();
  } finally { fs.rmSync(config.folder, { recursive: true, force: true }); }
});
