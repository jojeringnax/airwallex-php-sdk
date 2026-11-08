'use strict';

const http = require('node:http');
const https = require('node:https');
const tls = require('node:tls');
const net = require('node:net');

// A closed result category, never a retained native request/socket/Error.
class RiskRelayFailure extends Error {
  constructor(phase) { super('Risk telemetry relay did not complete safely.'); this.phase = phase; }
}

class HostedRiskRelay {
  #proxy;
  #fixture;
  #sockets = new Set();
  #requests = new Set();
  #closing = false;
  #failed = false;
  #received = 0;
  constructor(proxy, fixture = null) {
    const parsed = new URL(proxy);
    if (parsed.protocol !== 'http:' || parsed.hostname !== '127.0.0.1' || !parsed.port
        || parsed.username || parsed.password || parsed.pathname !== '/' || parsed.search || parsed.hash) {
      throw new RiskRelayFailure('configuration');
    }
    if (fixture !== null) {
      const origin = new URL(fixture.origin);
      if (origin.protocol !== 'https:' || origin.hostname !== '127.0.0.1' || !origin.port
          || origin.pathname !== '/' || origin.search || origin.hash || !Buffer.isBuffer(fixture.ca)) {
        throw new RiskRelayFailure('configuration');
      }
    }
    this.#proxy = parsed;
    this.#fixture = fixture;
  }

  #target(value, method, role) {
    let target;
    try { target = new URL(value); } catch { throw new RiskRelayFailure('target'); }
    const origin = this.#fixture?.origin ?? 'https://bws.sandbox.airwallex.com';
    if (target.origin !== origin || value !== target.href || target.search || target.hash
        || target.username || target.password || (this.#fixture === null && target.port)
        || (role === 'preflight' ? method !== 'OPTIONS' : method !== 'POST')
        || !['visitor', 'event', 'preflight'].includes(role)
        || (role === 'event' ? !/^\/bws\/v1\/[A-Za-z0-9_-]{1,128}$/u.test(target.pathname)
          : target.pathname !== '/bws/v3/visit')) throw new RiskRelayFailure('target');
    return target;
  }

  #headers(fields, role, bytes) {
    const allowed = new Set(['accept', 'content-type', 'origin', 'referer', 'user-agent', 'cookie',
      'access-control-request-method', 'access-control-request-headers']);
    let size = 0;
    if (!fields || typeof fields !== 'object' || Array.isArray(fields)) throw new RiskRelayFailure('headers');
    for (const [name, value] of Object.entries(fields)) {
      if (!allowed.has(name) || typeof value !== 'string' || /[^\x20-\x7e]/u.test(value)
          || value.length > 4096) throw new RiskRelayFailure('headers');
      size += Buffer.byteLength(name + value);
    }
    if (size > 8192 || (role !== 'event' && fields.cookie)) throw new RiskRelayFailure('headers');
    if (role === 'visitor' && fields['content-type'] !== 'application/json') throw new RiskRelayFailure('headers');
    if (role === 'event' && !/^text\/plain;\s*charset=UTF-8$/iu.test(fields['content-type'] ?? '')) {
      throw new RiskRelayFailure('headers');
    }
    if (role === 'preflight' && (fields['access-control-request-method'] !== 'POST'
        || fields['access-control-request-headers'] !== 'content-type' || fields['content-type'] || bytes !== 0)) {
      throw new RiskRelayFailure('headers');
    }
    return { ...fields, ...(role === 'preflight' ? {} : { 'content-length': String(bytes) }) };
  }

  async send(value, method, role, headers, body, deadline) {
    const target = this.#target(value, method, role);
    if (!Buffer.isBuffer(body) || body.length > 65536 || (role !== 'preflight' && body.length === 0)
        || !Number.isFinite(deadline) || deadline <= performance.now() || this.#closing || this.#failed) {
      throw new RiskRelayFailure('boundary');
    }
    const outgoing = this.#headers(headers, role, body.length);
    const remaining = Math.min(8000, deadline - performance.now());
    if (remaining <= 0) throw new RiskRelayFailure('deadline');
    let connect, socket, secure, request, agent;
    let timer, idle;
    let phase = 'connect';
    const close = () => {
      clearTimeout(timer); clearTimeout(idle);
      for (const item of [request, connect, secure, socket]) if (item) item.destroy();
      if (agent) agent.destroy();
    };
    try {
      return await new Promise((resolve, reject) => {
        let done = false;
        const fail = () => {
          if (done) return;
          done = true; close(); reject(new RiskRelayFailure(phase));
        };
        const armRead = () => {
          clearTimeout(idle);
          idle = setTimeout(fail, Math.max(1, Math.min(5000, deadline - performance.now())));
        };
        timer = setTimeout(() => { phase = 'deadline'; fail(); }, Math.max(1, remaining));
        const authority = target.hostname + ':' + (target.port || '443');
        connect = http.request({ hostname: this.#proxy.hostname, port: this.#proxy.port,
          method: 'CONNECT', path: authority, agent: false, headers: { host: authority }, maxHeaderSize: 16384 });
        this.#requests.add(connect);
        connect.once('close', () => this.#requests.delete(connect));
        connect.on('error', fail);
        connect.on('connect', (reply, tunnel, head) => {
          tunnel.on('error', () => {});
          if (done || this.#closing) { tunnel.destroy(); return; }
          try {
            socket = tunnel;
            this.#sockets.add(socket);
            socket.once('close', () => this.#sockets.delete(socket));
            socket.on('error', fail);
            if (done || this.#closing || reply.statusCode !== 200 || head.length || performance.now() >= deadline) {
              return fail();
            }
            phase = 'tls';
            secure = tls.connect({ socket, rejectUnauthorized: true,
              servername: net.isIP(target.hostname) ? undefined : target.hostname,
              ALPNProtocols: ['http/1.1'], ...(this.#fixture === null ? {} : { ca: this.#fixture.ca }) });
            this.#sockets.add(secure);
            secure.once('close', () => this.#sockets.delete(secure));
            secure.on('error', fail);
            secure.once('secureConnect', () => {
              try {
                if (done || this.#closing || !secure.authorized || performance.now() >= deadline
                    || tls.checkServerIdentity(target.hostname, secure.getPeerCertificate())) return fail();
                phase = 'response';
                agent = new https.Agent({ keepAlive: false, maxSockets: 1 });
                agent.createConnection = (_options, callback) => { callback(null, secure); return secure; };
                request = https.request({ hostname: target.hostname, port: target.port || 443,
                  path: target.pathname, method, headers: outgoing, agent, maxHeaderSize: 16384 }, response => {
                  if (done || this.#closing || performance.now() >= deadline) return fail();
                  const status = response.statusCode;
                  if (!Number.isInteger(status) || status < 100 || status > 599) return fail();
                  const fields = [];
                  let headerBytes = 0;
                  for (let index = 0; index < response.rawHeaders.length; index += 2) {
                    const name = response.rawHeaders[index], value = response.rawHeaders[index + 1];
                    headerBytes += Buffer.byteLength(name + value);
                    fields.push({ name, value });
                  }
                  const length = fields.filter(item => item.name.toLowerCase() === 'content-length');
                  if (headerBytes > 16384 || length.length > 1 || (length.length === 1
                      && (!/^[0-9]+$/u.test(length[0].value) || Number(length[0].value) > 65536))) return fail();
                  const chunks = [];
                  let bytes = 0;
                  response.on('error', fail); response.on('aborted', fail);
                  response.on('data', chunk => {
                    if (done) return;
                    bytes += chunk.length;
                    this.#received += chunk.length;
                    if (this.#closing || bytes > 65536 || this.#received > 262144 || performance.now() >= deadline) return fail();
                    chunks.push(chunk); armRead();
                  });
                  response.once('end', () => {
                    if (done) return;
                    if (this.#closing || performance.now() >= deadline || !response.complete) return fail();
                    done = true;
                    resolve({ status, fields, body: Buffer.concat(chunks), responseBytes: bytes });
                  });
                  armRead();
                });
                this.#requests.add(request);
                request.once('close', () => this.#requests.delete(request));
                request.on('error', fail);
                request.on('information', () => { phase = 'framing'; fail(); });
                request.end(body);
                armRead();

            } catch { fail(); }
            });
          } catch { fail(); }
        });
        connect.end();
      });
    } catch {
      this.#failed = true;
      throw new RiskRelayFailure(phase);
    } finally { close(); }
  }

  async close() {
    this.#closing = true;
    const pending = [...this.#sockets, ...this.#requests].map(item => new Promise(resolve => {
      if (item.closed) { resolve(true); return; }
      let timer;
      item.once('close', () => { clearTimeout(timer); resolve(true); });
      timer = setTimeout(() => resolve(false), 100);
      item.destroy();
    }));
    return (await Promise.all(pending)).every(Boolean);
  }

  toJSON() { throw new Error('Private risk relay cannot be serialized.'); }
}

module.exports = { HostedRiskRelay, RiskRelayFailure };
