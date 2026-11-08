'use strict';
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const { performance } = require('node:perf_hooks');
const { createReceiver, privateDirectory } = require('./webhook-receiver.cjs');
const BINARY_SHA256 = '2136431d5eb467bf447d2ee514b713e192719b143aec1d9e09bf7dc290d5bb12';

function verifiedBinary(binary) {
  if (typeof binary !== 'string' || !path.isAbsolute(binary) || fs.realpathSync(binary) !== binary) throw new Error('runtime');
  const stat = fs.lstatSync(binary);
  if (!stat.isFile() || stat.isSymbolicLink() || stat.size > 100000000 || (stat.mode & 0o111) === 0) throw new Error('runtime');
  if (crypto.createHash('sha256').update(fs.readFileSync(binary)).digest('hex') !== BINARY_SHA256) throw new Error('runtime');
}
function ready(port) {
  return new Promise(resolve => {
    let done = false;
    let deadline;
    const finish = value => { if (!done) { done = true; clearTimeout(deadline); resolve(value); } };
    const request = http.get({ host: '127.0.0.1', port, path: '/ready', timeout: 1000, agent: false }, response => {
      let count = 0;
      response.on('data', bytes => { count += bytes.length; if (count > 1024) { response.destroy(); finish(false); } });
      response.on('error', () => finish(false));
      response.on('end', () => finish(count <= 1024 && response.statusCode === 200));
    });
    request.on('error', () => finish(false));
    request.on('timeout', () => { request.destroy(); finish(false); });
    deadline = setTimeout(() => { request.destroy(); finish(false); }, 1000);
  });
}
function sleep(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }

async function createSupervisor(config, options = {}) {
  if (!config || config.live !== true) throw new Error('explicit_opt_in_required');
  (options.verifyBinary || verifiedBinary)(config.binary);
  privateDirectory(config.folder);
  const receiver = await createReceiver(config, options.receiverOptions || {});
  let failed = false;
  let logBytes = 0;
  let logDropped = 0;
  let line = '';
  let hostname = null;
  let metricsPort = null;
  let child = null;
  let childExited = false;
  let stopPromise = null;
  let timer;
  let childClosed;
  let log;
  let queue = Promise.resolve();
  const status = () => ({ ...receiver.stats(), supervisor_failed: failed,
    log_limit_failure: logDropped > 0, tunnel_exited: childExited });
  const stop = () => {
    if (stopPromise) return stopPromise;
    stopPromise = (async () => {
      clearTimeout(timer);
      let receiverClosed = false; let tunnelStopped = !child; let logsClosed = false;
      if (child && !childExited) {
        try { child.kill('SIGTERM'); } catch { failed = true; }
        await Promise.race([childClosed, sleep(2000)]);
        if (!childExited) {
          try { child.kill('SIGKILL'); } catch { failed = true; }
          await Promise.race([childClosed, sleep(1000)]);
        }
      }
      tunnelStopped = !child || childExited;
      try {
        const closed = await Promise.race([receiver.close(), sleep(2000).then(() => null)]);
        receiverClosed = closed !== null && closed.closed === true;
        if (closed !== null && (closed.stream_failure || closed.persistence_failure || closed.limit_failure)) failed = true;
      } catch { failed = true; }
      try {
        const logging = queue.then(async () => { if (log) { await log.sync(); await log.close(); } return true; });
        logsClosed = await Promise.race([logging, sleep(2000).then(() => false)]);
      } catch { failed = true; }
      failed = failed || !receiverClosed || !tunnelStopped || !logsClosed;
      return { receiver_closed: receiverClosed, tunnel_stopped: tunnelStopped, logs_closed: logsClosed, failed };
    })();
    return stopPromise;
  };
  if (options.onStopReady) options.onStopReady(stop);
  function consume(bytes) {
    try {
      const chunk = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes);
      const allowed = Math.max(0, 1048576 - logBytes);
      const keep = chunk.subarray(0, allowed);
      logBytes += keep.length;
      if (chunk.length > keep.length) { logDropped += chunk.length - keep.length; failed = true; }
      queue = queue.then(async () => {
        if (!log || !keep.length) return;
        let written = 0;
        while (written < keep.length) {
          const result = await log.write(keep, written, keep.length - written);
          if (!Number.isInteger(result.bytesWritten) || result.bytesWritten < 1
            || result.bytesWritten > keep.length - written) throw new Error('private_log_write');
          written += result.bytesWritten;
        }
      }).catch(() => { failed = true; });
      // Only bounded startup lines are inspected. Every byte remains private, even on errors.
      line += keep.toString('utf8');
      if (line.length > 8192 && !line.includes('\n')) { line = ''; failed = true; }
      let index;
      while ((index = line.indexOf('\n')) !== -1) {
        const entry = line.slice(0, index); line = line.slice(index + 1);
        if (entry.length > 8192) { failed = true; continue; }
        const urls = [...entry.matchAll(/https:\/\/([a-z0-9]+(?:-[a-z0-9]+)*\.trycloudflare\.com)(?=[\s|]|$)/g)];
        for (const item of urls) {
          if (hostname && hostname !== item[1]) failed = true;
          hostname = item[1];
        }
        const metric = entry.match(/Starting metrics server on 127\.0\.0\.1:([0-9]{1,5})\/metrics/);
        if (metric) {
          const port = Number(metric[1]);
          if (port < 1 || port > 65535 || (metricsPort && metricsPort !== port)) failed = true;
          else metricsPort = port;
        }
      }
      if (Buffer.byteLength(line) > 8192) { line = ''; failed = true; }
    } catch { failed = true; }
  }
  try {
    log = await (options.logIo || fs.promises).open(path.join(config.folder, 'cloudflared.log'), 'wx', 0o600);
    if (stopPromise) { await log.close(); log = null; throw new Error('stopped'); }
    const args = ['tunnel', '--config', path.join(config.folder, 'cloudflared.yml'), '--no-autoupdate',
      '--protocol', 'http2', '--metrics', '127.0.0.1:0', '--loglevel', 'info', '--url', `http://127.0.0.1:${receiver.port}`];
    child = (options.spawn || spawn)(config.binary, args, { cwd: config.folder,
      env: { PATH: '/usr/bin:/bin', LANG: 'C', TMPDIR: config.folder }, stdio: ['ignore', 'pipe', 'pipe'] });
    childClosed = new Promise(resolve => {
      child.on('error', () => { failed = true; });
      child.on('close', () => { childExited = true; if (!stopPromise) failed = true; resolve(); });
    });
    for (const stream of [child.stdout, child.stderr]) {
      if (!stream) throw new Error('runtime_pipes');
      stream.on('data', consume); stream.on('error', () => { failed = true; });
    }
    timer = setTimeout(() => { failed = true; void stop().catch(() => {}); }, config.duration_ms);
    const until = performance.now() + Math.min(options.startupMs || 60000, config.duration_ms);
    while (performance.now() < until && !failed && !childExited) {
      if (hostname && metricsPort && await (options.ready || ready)(metricsPort)) {
        await queue;
        if (failed) throw new Error('private_log_failure');
        return { destination: `https://${hostname}${config.path}`, arm: receiver.arm,
          poll: status, stop };
      }
      await sleep(25);
    }
    throw new Error('tunnel_unavailable');
  } catch {
    failed = true; await stop(); throw new Error('supervisor_unavailable');
  }
}

async function main() {
  let supervisor = null; let earlyStop = null; let shuttingDown = false;
  let buffer = ''; let initialized = false; let busy = false; let fatalTimer = null;
  function output(value) {
    try { process.stdout.write(`${JSON.stringify(value)}\n`); } catch { /* Private pipe closed; shutdown below. */ }
  }
  async function shutdown(failure) {
    if (shuttingDown) return;
    shuttingDown = true;
    clearTimeout(fatalTimer);
    const hard = setTimeout(() => process.exit(1), 5000);
    let stopped = { receiver_closed: false, tunnel_stopped: false, logs_closed: false, failed: true };
    try { if (supervisor) stopped = await supervisor.stop(); else if (earlyStop) stopped = await earlyStop(); } catch { failure = true; }
    output({ type: 'stopped', ...stopped, failed: failure || stopped.failed });
    clearTimeout(hard); process.exit(failure || stopped.failed ? 1 : 0);
  }
  const fail = () => { void shutdown(true).catch(() => process.exit(1)); };
  process.stdout.on('error', fail); process.stderr.on('error', fail);
  process.on('uncaughtException', fail); process.on('unhandledRejection', fail);
  process.on('SIGTERM', fail); process.on('SIGINT', fail); process.stdin.on('error', fail);
  process.stdin.on('end', fail);
  process.stdin.setEncoding('utf8');
  process.stdin.on('data', chunk => {
    buffer += chunk;
    if (Buffer.byteLength(buffer) > 65536 || busy) { fail(); return; }
    const newline = buffer.indexOf('\n');
    if (newline < 0) return;
    const entry = buffer.slice(0, newline); buffer = buffer.slice(newline + 1);
    if (buffer !== '') { fail(); return; }
    busy = true;
    (async () => {
      const value = JSON.parse(entry);
      if (!initialized) {
        initialized = true;
        fatalTimer = setTimeout(fail, 305000);
        supervisor = await createSupervisor(value, { onStopReady: stop => { earlyStop = stop; } });
        output({ type: 'ready', destination: supervisor.destination });
      } else if (value && value.type === 'arm' && typeof value.registration === 'string') {
        supervisor.arm(value.registration); output({ type: 'armed' });
      } else if (value && value.type === 'poll') {
        output({ type: 'observed', ...supervisor.poll() });
      } else if (value && value.type === 'stop') {
        await shutdown(false);
      } else { throw new Error('protocol'); }
    })().catch(fail).finally(() => { busy = false; });
  });
}

module.exports = { BINARY_SHA256, verifiedBinary, ready, createSupervisor };
if (require.main === module) void main().catch(() => process.exit(1));
