'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const { EventEmitter } = require('node:events');
const { PassThrough } = require('node:stream');
const { spawnSync } = require('node:child_process');
const { createSupervisor, ready, verifiedBinary } = require('./webhook-supervisor.cjs');
function fixture() {
  const folder = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'sdk-webhook-supervisor-')));
  fs.chmodSync(folder, 0o700); fs.writeFileSync(path.join(folder, 'cloudflared.yml'), '{}', { mode: 0o600 });
  return { folder, path: `/${'a'.repeat(64)}`, context: 'b'.repeat(64), source: 'c'.repeat(64),
    duration_ms: 1000, live: true, binary: '/SYNTHETIC_PRIVATE_RUNTIME' };
}
class FakeChild extends EventEmitter {
  constructor(lines, exits = true) {
    super(); this.stdout = new PassThrough(); this.stderr = new PassThrough(); this.signals = []; this.exits = exits;
    queueMicrotask(() => this.stderr.write(lines));
  }
  kill(signal) {
    this.signals.push(signal);
    if (this.exits) queueMicrotask(() => { this.stdout.end(); this.stderr.end(); this.emit('close', 0); });
    return true;
  }
}
const LINES = 'INF https://synthetic-private.trycloudflare.com |\nINF Starting metrics server on 127.0.0.1:12345/metrics\n';
const options = child => ({ verifyBinary: () => {}, spawn: () => child, ready: async () => true, startupMs: 100 });
async function tidy(supervisor, folder) {
  if (supervisor) await supervisor.stop(); fs.rmSync(folder, { recursive: true, force: true });
}

test('only verified binary gets exact fixed argv/minimal env; private destination never becomes public logging', async () => {
  const config = fixture(); const child = new FakeChild(LINES); let supervisor;
  try {
    let verification = false; let invocation;
    supervisor = await createSupervisor(config, { ...options(child), verifyBinary: value => { assert.equal(value, config.binary); verification = true; },
      spawn: (binary, args, opts) => { assert(verification); invocation = { binary, args, opts }; return child; } });
    assert.equal(supervisor.destination, `https://synthetic-private.trycloudflare.com${config.path}`);
    assert.deepEqual(invocation.args, ['tunnel', '--config', path.join(config.folder, 'cloudflared.yml'),
      '--no-autoupdate', '--protocol', 'http2', '--metrics', '127.0.0.1:0', '--loglevel', 'info', '--url',
      invocation.args[invocation.args.length - 1]]);
    assert.match(invocation.args.at(-1), /^http:\/\/127\.0\.0\.1:[0-9]+$/);
    assert.deepEqual(invocation.opts.env, { PATH: '/usr/bin:/bin', LANG: 'C', TMPDIR: config.folder });
    assert.deepEqual(invocation.opts.stdio, ['ignore', 'pipe', 'pipe']);
    assert.equal(fs.statSync(path.join(config.folder, 'cloudflared.log')).mode & 0o777, 0o600);
    supervisor.arm('d'.repeat(64)); assert.throws(() => supervisor.arm('e'.repeat(64)));
    assert(!JSON.stringify(supervisor.poll()).includes('synthetic-private'));
    const stopped = await supervisor.stop();
    assert.deepEqual(stopped, { receiver_closed: true, tunnel_stopped: true, logs_closed: true, failed: false });
    assert.deepEqual(child.signals, ['SIGTERM']);
  } finally { await tidy(supervisor, config.folder); }
});

test('binary and opt-in failures do not invoke a process', async () => {
  const config = fixture(); let sent = 0;
  try {
    await assert.rejects(createSupervisor({ ...config, live: false }, { spawn: () => { ++sent; } }));
    const candidate = path.join(config.folder, 'not-cloudflared'); fs.writeFileSync(candidate, 'SYNTHETIC_PRIVATE'); fs.chmodSync(candidate, 0o700);
    assert.throws(() => verifiedBinary(candidate));
    await assert.rejects(createSupervisor({ ...config, binary: candidate }, { spawn: () => { ++sent; } }));
    assert.equal(sent, 0);
  } finally { fs.rmSync(config.folder, { recursive: true, force: true }); }
});

test('foreign/multiple public origin and private log overflow cannot report readiness', async () => {
  for (const lines of ['https://api.airwallex.com/private\n', LINES + 'https://different.trycloudflare.com \n', Buffer.alloc(1048577, 120)]) {
    const config = fixture(); const child = new FakeChild(lines);
    try {
      await assert.rejects(createSupervisor(config, options(child)), /supervisor_unavailable/);
      assert(child.signals.includes('SIGTERM'));
      assert(fs.statSync(path.join(config.folder, 'cloudflared.log')).size <= 1048576);
    } finally { fs.rmSync(config.folder, { recursive: true, force: true }); }
  }
});

test('early stop while startup is pending never spawns a tunnel afterward', async () => {
  const config = fixture(); let count = 0; let closed;
  try {
    await assert.rejects(createSupervisor(config, { verifyBinary: () => {}, onStopReady: stop => { closed = stop(); },
      spawn: () => { ++count; return new FakeChild(LINES); } }), /supervisor_unavailable/);
    assert.equal(count, 0); assert.equal((await closed).receiver_closed, true);
  } finally { fs.rmSync(config.folder, { recursive: true, force: true }); }
});

test('independent global deadline closes/reaps children with no controller poll', async () => {
  const config = { ...fixture(), duration_ms: 100 }; const child = new FakeChild(LINES); let supervisor;
  try {
    supervisor = await createSupervisor(config, options(child));
    await new Promise(resolve => setTimeout(resolve, 150));
    const stopped = await supervisor.stop(); assert.equal(stopped.receiver_closed, true);
    assert.equal(stopped.tunnel_stopped, true); assert.equal(stopped.failed, true);
    assert.deepEqual(child.signals, ['SIGTERM']);
  } finally { await tidy(supervisor, config.folder); }
});

test('a child ignoring TERM is killed and reaped before cleanup can succeed', async () => {
  const config = { ...fixture(), duration_ms: 10000 }; const child = new FakeChild(LINES, false); let supervisor;
  child.kill = signal => {
    child.signals.push(signal);
    if (signal === 'SIGKILL') queueMicrotask(() => {
      child.stdout.end(); child.stderr.end(); child.emit('close', 0);
    });
    return true;
  };
  try {
    supervisor = await createSupervisor(config, options(child));
    const started = performance.now();
    const stopped = await supervisor.stop();
    assert.deepEqual(child.signals, ['SIGTERM', 'SIGKILL']);
    assert.equal(stopped.tunnel_stopped, true); assert.equal(stopped.receiver_closed, true);
    assert.equal(stopped.failed, false); assert(performance.now() - started < 5000);
    assert(!JSON.stringify(stopped).includes('SYNTHETIC_PRIVATE'));
  } finally { await tidy(supervisor, config.folder); }
});

test('a partial capture interrupted during shutdown remains a failed observation', async () => {
  const config = { ...fixture(), duration_ms: 10000 }; const child = new FakeChild(LINES); let supervisor;
  let port; let request;
  try {
    supervisor = await createSupervisor(config, { ...options(child), spawn(_binary, args) {
      port = Number(new URL(args[args.length - 1]).port); return child;
    } });
    supervisor.arm('d'.repeat(64));
    request = http.request({ host: '127.0.0.1', port, path: config.path, method: 'POST',
      headers: { 'x-timestamp': '123', 'x-signature': 'e'.repeat(64), 'Content-Length': '100' } });
    request.on('error', () => {}); request.write('SYNTHETIC_PRIVATE_PARTIAL');
    for (let tries = 0; tries < 20 && supervisor.poll().seen < 1; ++tries) {
      await new Promise(resolve => setTimeout(resolve, 10));
    }
    assert.equal(supervisor.poll().seen, 1);
    const stopped = await supervisor.stop();
    assert.equal(stopped.receiver_closed, true); assert.equal(stopped.tunnel_stopped, true);
    assert.equal(stopped.failed, true); assert(!JSON.stringify(stopped).includes('SYNTHETIC_PRIVATE'));
  } finally { if (request) request.destroy(); await tidy(supervisor, config.folder); }
});

test('failed log close is fixed failure rather than successful cleanup', async () => {
  const config = fixture(); const child = new FakeChild(LINES); let supervisor;
  const logIo = { async open(...args) {
    const file = await fs.promises.open(...args);
    return new Proxy(file, { get(target, key) {
      if (key === 'close') return async () => { await target.close(); throw new Error('SYNTHETIC_PRIVATE_CLOSE'); };
      const value = Reflect.get(target, key); return typeof value === 'function' ? value.bind(target) : value;
    } });
  } };
  try {
    supervisor = await createSupervisor(config, { ...options(child), logIo });
    const stopped = await supervisor.stop(); assert.equal(stopped.failed, true); assert.equal(stopped.logs_closed, false);
    assert(!JSON.stringify(stopped).includes('SYNTHETIC_PRIVATE'));
  } finally { await tidy(supervisor, config.folder); }
});

test('metrics check is one bounded anonymous localhost GET, no redirect follow', async () => {
  for (const [status, body] of [[200, 'ready'], [302, 'redirect'], [200, 'x'.repeat(1025)]]) {
    let calls = 0;
    const server = http.createServer((request, response) => {
      ++calls; assert.equal(request.url, '/ready'); assert.equal(request.headers.authorization, undefined);
      assert.equal(request.headers.cookie, undefined); response.writeHead(status, { Location: 'https://SYNTHETIC_PRIVATE.test' }); response.end(body);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try { assert.equal(await ready(server.address().port), status === 200 && body.length <= 1024); assert.equal(calls, 1); }
    finally { await new Promise(resolve => server.close(resolve)); }
  }
});

test('positive-progress readiness bodies still have an absolute elapsed deadline', async () => {
  let calls = 0;
  const server = http.createServer((_request, response) => {
    ++calls; response.writeHead(200); response.write('x');
    const interval = setInterval(() => response.write('x'), 30);
    response.on('close', () => clearInterval(interval));
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  try {
    const started = performance.now();
    assert.equal(await ready(server.address().port), false);
    assert(performance.now() - started < 2000); assert.equal(calls, 1);
  } finally { await new Promise(resolve => server.close(resolve)); }
});

test('real protocol rejects malformed/config-only input with empty stderr and no sensitive output', () => {
  for (const input of ['SYNTHETIC_PRIVATE\n', JSON.stringify({ live: false, binary: '/SYNTHETIC_PRIVATE' }) + '\n']) {
    const result = spawnSync(process.execPath, [path.join(__dirname, 'webhook-supervisor.cjs')],
      { input, encoding: 'utf8', timeout: 2000, env: { PATH: '/usr/bin:/bin', LANG: 'C' } });
    assert.equal(result.status, 1); assert.equal(result.stderr, ''); assert(!result.stdout.includes('SYNTHETIC_PRIVATE'));
    assert.equal(JSON.parse(result.stdout).failed, true);
  }
});

test('short private log writes complete fully, while zero progress fails before readiness', async () => {
  for (const stalled of [false, true]) {
    const config = fixture(); const child = new FakeChild(LINES); let supervisor;
    const logIo = { async open(...args) {
      const file = await fs.promises.open(...args);
      return new Proxy(file, { get(target, key) {
        if (key === 'write') return async (buffer, offset, length) => stalled
          ? { bytesWritten: 0, buffer } : target.write(buffer, offset, Math.min(3, length));
        const value = Reflect.get(target, key); return typeof value === 'function' ? value.bind(target) : value;
      } });
    } };
    try {
      if (stalled) await assert.rejects(createSupervisor(config, { ...options(child), logIo }), /supervisor_unavailable/);
      else {
        supervisor = await createSupervisor(config, { ...options(child), logIo });
        const closed = await supervisor.stop(); assert.equal(closed.failed, false);
        assert.equal(fs.readFileSync(path.join(config.folder, 'cloudflared.log'), 'utf8'), LINES);
      }
    } finally { await tidy(supervisor, config.folder); }
  }
});
