'use strict';

// Development-only raw capture. The PHP controller owns credentials and all API mutations.
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const crypto = require('node:crypto');
const { performance } = require('node:perf_hooks');

function privateDirectory(folder) {
  if (typeof folder !== 'string' || !path.isAbsolute(folder) || fs.realpathSync(folder) !== folder) throw new Error('private_state');
  const stat = fs.lstatSync(folder);
  if (!stat.isDirectory() || stat.isSymbolicLink() || (stat.mode & 0o777) !== 0o700) throw new Error('private_state');
  for (let item = folder; ; item = path.dirname(item)) {
    try { fs.lstatSync(path.join(item, '.git')); throw new Error('private_state'); }
    catch (error) { if (error.code !== 'ENOENT') throw new Error('private_state'); }
    if (path.dirname(item) === item) break;
  }
  return { dev: stat.dev, ino: stat.ino };
}

async function atomic(folder, name, bytes, io = fs.promises) {
  privateDirectory(folder);
  const final = path.join(folder, name);
  const temporary = `${final}.writing`;
  const file = await io.open(temporary, 'wx', 0o600);
  try { await file.writeFile(bytes); await file.sync(); } finally { await file.close(); }
  await io.link(temporary, final); // Exclusive finalization; never replace another capture.
  await io.unlink(temporary);
  const directory = await io.open(folder, 'r');
  try { await directory.sync(); } finally { await directory.close(); }
}

function configValid(config) {
  return config && typeof config === 'object' && !Array.isArray(config)
    && typeof config.folder === 'string' && typeof config.path === 'string'
    && /^\/[a-f0-9]{64}$/.test(config.path) && /^[a-f0-9]{64}$/.test(config.context)
    && /^[a-f0-9]{64}$/.test(config.source) && Number.isSafeInteger(config.duration_ms)
    && config.duration_ms >= 100 && config.duration_ms <= 300000;
}

async function createReceiver(config, options = {}) {
  if (!configValid(config)) throw new Error('configuration');
  const identity = privateDirectory(config.folder);
  const io = options.io || fs.promises;
  const clock = options.clock || Date.now;
  const deadlineMs = options.deadlineMs || 5000;
  if (!Number.isInteger(deadlineMs) || deadlineMs < 1 || deadlineMs > 5000) throw new Error('configuration');
  const stats = { seen: 0, stored: 0, refused: 0, invalid: 0, stream_failure: false,
    persistence_failure: false, limit_failure: false, closed: false };
  let armed = null;
  let active = 0;
  let bytesStored = 0;
  let next = 0;
  let closing = false;
  const pending = new Set();
  const sockets = new Set();
  const headerTimers = new Map();
  function respond(response, status) {
    if (response.destroyed || response.headersSent) return;
    response.writeHead(status, { 'Content-Length': '0', 'Cache-Control': 'no-store', Connection: 'close' });
    response.end();
  }
  const server = http.createServer({ maxHeaderSize: 16384, requestTimeout: 5000,
    headersTimeout: 5000, keepAliveTimeout: 1, highWaterMark: 16384 }, (request, response) => {
    clearTimeout(headerTimers.get(request.socket));
    headerTimers.delete(request.socket);
    response.on('error', () => { stats.stream_failure = true; });
    request.on('error', () => { stats.stream_failure = true; });
    if (closing || !armed || request.method !== 'POST' || request.url !== config.path) {
      ++stats.refused; respond(response, 404); request.destroy(); return;
    }
    const headers = new Map();
    for (let i = 0; i < request.rawHeaders.length; i += 2) {
      const key = request.rawHeaders[i].toLowerCase();
      if (!headers.has(key)) headers.set(key, []);
      headers.get(key).push(request.rawHeaders[i + 1]);
    }
    const single = key => headers.has(key) && headers.get(key).length === 1 ? headers.get(key)[0] : null;
    const timestamp = single('x-timestamp');
    const signature = single('x-signature');
    const length = single('content-length');
    const transfer = single('transfer-encoding');
    const encoding = single('content-encoding');
    if (typeof timestamp !== 'string' || timestamp.length > 64 || typeof signature !== 'string'
      || signature.length > 256 || (headers.has('content-length') && length === null)
      || (headers.has('transfer-encoding') && transfer === null) || (length !== null && transfer !== null)
      || (length !== null && (!/^(?:0|[1-9][0-9]{0,5})$/.test(length) || Number(length) > 65536))
      || (transfer !== null && transfer.toLowerCase() !== 'chunked')
      || (headers.has('content-encoding') && (encoding === null || encoding.toLowerCase() !== 'identity'))) {
      ++stats.invalid; respond(response, 400); request.destroy(); return;
    }
    if (active >= 2 || next >= 16 || bytesStored >= 2097152) {
      stats.limit_failure = true; respond(response, 429); request.destroy(); return;
    }
    const received = clock();
    const started = performance.now();
    if (!Number.isSafeInteger(received) || received < 0) {
      stats.stream_failure = true; respond(response, 500); request.destroy(); return;
    }
    ++active;
    ++stats.seen;
    const ordinal = ++next;
    const binding = armed;
    let chunks = [];
    let size = 0;
    let failed = false;
    let released = false;
    const release = () => { if (!released) { released = true; --active; } };
    const fail = () => {
      if (failed) return;
      failed = true; clearTimeout(timer); chunks = []; stats.stream_failure = true;
      respond(response, 400); request.destroy(); release();
    };
    const timer = setTimeout(fail, deadlineMs);
    request.setTimeout(2000, fail);
    request.on('aborted', fail);
    request.on('close', () => { if (!request.complete) fail(); });
    request.on('data', chunk => {
      if (failed) return;
      if (!Buffer.isBuffer(chunk) || size + chunk.length > 65536 || bytesStored + size + chunk.length > 2097152) {
        stats.limit_failure = true; fail(); return;
      }
      size += chunk.length; chunks.push(chunk);
    });
    request.on('end', () => {
      if (failed) return;
      if (length !== null && Number(length) !== size) { fail(); return; }
      const body = Buffer.concat(chunks, size); chunks = [];
      const job = (async () => {
        const current = privateDirectory(config.folder);
        if (current.dev !== identity.dev || current.ino !== identity.ino) throw new Error('private_state');
        const stem = `capture-${String(ordinal).padStart(6, '0')}`;
        await atomic(config.folder, `${stem}.body`, body, io);
        const metadata = { format: 1, timestamp, signature, received_at_ms: received,
          length: body.length, sha256: crypto.createHash('sha256').update(body).digest('hex'),
          context: config.context, source: config.source, registration: binding };
        await atomic(config.folder, `${stem}.json`, JSON.stringify(metadata), io);
        bytesStored += body.length;
        ++stats.stored;
        if (failed || closing || performance.now() - started >= deadlineMs) throw new Error('capture_deadline');
        respond(response, 200); // Receipt only. PHP verifies before parsing or processing the bytes.
      })().catch(() => {
        stats.persistence_failure = true; respond(response, 500);
      }).finally(() => { clearTimeout(timer); pending.delete(job); release(); });
      pending.add(job);
    });
  });
  server.maxConnections = 4; // Two active handlers plus bounded refused/closing sockets.
  server.on('connection', socket => {
    sockets.add(socket);
    headerTimers.set(socket, setTimeout(() => { stats.stream_failure = true; socket.destroy(); }, deadlineMs));
    socket.on('error', () => {});
    socket.on('close', () => { clearTimeout(headerTimers.get(socket)); headerTimers.delete(socket); sockets.delete(socket); });
    socket.setTimeout(5000, () => socket.destroy());
  });
  server.on('clientError', (_error, socket) => {
    ++stats.invalid; socket.on('error', () => {}); socket.destroy();
  });
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => { server.removeListener('error', reject); resolve(); });
  });
  server.on('error', () => { stats.stream_failure = true; });
  const address = server.address();
  if (!address || typeof address === 'string') throw new Error('listen');
  return {
    port: address.port,
    arm(value) { if (armed !== null || !/^[a-f0-9]{64}$/.test(value)) throw new Error('arm'); armed = value; },
    stats() { return { ...stats }; },
    async close() {
      closing = true;
      for (const socket of sockets) socket.destroy();
      await new Promise(resolve => server.close(() => resolve()));
      await Promise.allSettled([...pending]);
      stats.closed = true;
      await atomic(config.folder, 'receiver-stats.json', JSON.stringify(stats), io);
      return { ...stats };
    },
  };
}

module.exports = { privateDirectory, atomic, configValid, createReceiver };
