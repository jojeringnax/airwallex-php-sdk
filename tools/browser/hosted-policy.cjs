'use strict';

const http = require('node:http');
const net = require('node:net');
const { createHash } = require('node:crypto');

// Development-only preflight: no financial request and no card entry is enabled.
// The shared checkout origin is a candidate initial document, never Sandbox proof.
const candidateOrigins = new Set([
  'https://checkout.airwallex.com',
  'https://checkout.sandbox.airwallex.com',
]);
// Public GETs from both candidate origins matched these exact bytes on 2026-11-08.
// New builds require fresh source verification and independent policy review.
const staticAssets = Object.freeze({
  '/pay/static/v2.286.0/js/vendor.20d1746387dc4efce401.js':
    '1c3f66b6b0ef42939e2c5b6e791b7489d89bf21f7bc7fc270523c6bcc1677c9c',
  '/pay/static/v2.286.0/js/749.7f82c5bd7427d761872f.js':
    '88c82045bf08ae4f7df3bf0fcde6d30ee78864e4aa1d7dad7204183de0fabbee',
  '/pay/static/v2.286.0/js/main.ddc4fcf3b752ddbd5f14.js':
    '72ce3dbcd9ef575f449648ea71b1f7c536a9a4157030de3ebe51c71b19cd4627',
});
const returnUrl = 'https://example.com/';

function assetMatches(bytes, expected) {
  return Buffer.isBuffer(bytes) && typeof expected === 'string'
    && createHash('sha256').update(bytes).digest('hex') === expected;
}

function parse(value) {
  try {
    if (typeof value !== 'string' || value.length > 16384 || /[\u0000-\u0020\\]/u.test(value)) return null;
    const url = new URL(value);
    return url.username || url.password ? null : url;
  } catch { return null; }
}

class HostedPolicy {
  #initial;
  #fixture;
  constructor(initial, fixtureOrigin = null) {
    const url = parse(initial);
    if (!url) throw new Error('Invalid browser preflight configuration.');
    if (fixtureOrigin === null) {
      if (!candidateOrigins.has(url.origin) || url.pathname !== '/pay' || !url.searchParams.get('s')) {
        throw new Error('Invalid browser preflight configuration.');
      }
    } else {
      const fixture = parse(fixtureOrigin);
      if (!fixture || fixture.hostname !== '127.0.0.1' || fixture.protocol !== 'http:'
          || fixture.pathname !== '/' || fixture.search || fixture.hash || url.origin !== fixture.origin) {
        throw new Error('Invalid local fixture configuration.');
      }
    }
    this.#initial = url.href;
    this.#fixture = fixtureOrigin;
  }

  decide(value, method, type) {
    const url = parse(value);
    if (!url) return 'block';
    if (url.origin === 'https://example.com' && url.pathname === '/' && method === 'GET' && type === 'document') {
      return 'return'; // Never send redirect parameters or Referer to the external return destination.
    }
    if (method !== 'GET') return 'block';
    if (type === 'document' && url.href === this.#initial) return 'allow';
    if (this.assetHash(value, method, type) !== null) return 'allow';
    if (this.#fixture !== null && url.origin === this.#fixture
        && type === 'document'
        && ['/frame', '/redirect', '/redirect-same', '/redirect-other'].includes(url.pathname)) return 'allow';
    return 'block';
  }

  assetHash(value, method, type) {
    const url = parse(value);
    if (!url || method !== 'GET' || type !== 'script' || value !== url.origin + url.pathname) return null;
    if (this.#fixture !== null) {
      return url.origin === this.#fixture && url.pathname === '/asset.js'
        ? 'e7642fccb474be1170bd51a330b606aca3bd360717d6fb52abe2be2638b47a1c' : null;
    }
    return url.origin === new URL(this.#initial).origin
      && Object.hasOwn(staticAssets, url.pathname) ? staticAssets[url.pathname] : null;
  }

  candidateOrigin() {
    if (this.#fixture !== null) return null;
    return new URL(this.#initial).hostname === 'checkout.sandbox.airwallex.com' ? 'sandbox' : 'shared';
  }

  allowsAuthority(host, port) {
    if (this.#fixture !== null) {
      return host === '127.0.0.1' && String(port) === new URL(this.#fixture).port;
    }
    const url = parse(`https://${host}:${port}/`);
    if (!url || String(port) !== '443') return false;
    return url.origin === new URL(this.#initial).origin;
  }

  allowsHttp(value) {
    const url = parse(value);
    return this.#fixture !== null && url !== null && url.origin === this.#fixture;
  }

  toJSON() { throw new Error('Browser preflight configuration cannot be serialized.'); }
}

// A second host boundary protects browser traffic outside page routing (including
// redirects/background traffic). CONNECT is tunneled unchanged: browser TLS remains enabled.
async function egressProxy(policy) {
  const sockets = new Set();
  const counts = { rejected: 0, connected: 0, externalConnected: 0 };
  const server = http.createServer((request, response) => {
    if (!policy.allowsHttp(request.url)) {
      counts.rejected++;
      response.writeHead(403).end();
      return;
    }
    const url = new URL(request.url);
    const upstream = http.request(url, { method: request.method, headers: request.headers }, reply => {
      response.writeHead(reply.statusCode ?? 502, reply.headers);
      reply.pipe(response);
    });
    upstream.on('error', () => {
      if (!response.headersSent) response.writeHead(502);
      response.end();
    });
    request.pipe(upstream);
  });
  server.on('connect', (request, client, head) => {
    const authority = /^([a-z0-9.-]+):([0-9]+)$/iu.exec(request.url ?? '');
    if (!authority || !policy.allowsAuthority(authority[1], authority[2])) {
      counts.rejected++;
      client.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');
      return;
    }
    const upstream = net.connect(Number(authority[2]), authority[1], () => {
      counts.connected++;
      if (authority[1] !== '127.0.0.1') counts.externalConnected++;
      client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
      if (head.length) upstream.write(head);
      client.pipe(upstream);
      upstream.pipe(client);
    });
    sockets.add(upstream);
    upstream.on('close', () => sockets.delete(upstream));
    upstream.on('error', () => client.destroy());
    client.on('error', () => upstream.destroy());
    client.on('close', () => upstream.destroy());
  });
  server.on('connection', socket => {
    sockets.add(socket);
    socket.on('error', () => {});
    socket.on('close', () => sockets.delete(socket));
  });
  server.on('upgrade', (_request, socket) => {
    counts.rejected++;
    socket.destroy();
  });
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
  });
  const address = server.address();
  if (!address || typeof address === 'string') throw new Error('Browser proxy could not start.');
  return {
    server: `http://127.0.0.1:${address.port}`,
    counts,
    close: async () => {
      for (const socket of sockets) socket.destroy();
      await new Promise(resolve => server.close(resolve));
    },
  };
}

module.exports = { HostedPolicy, egressProxy, parse, returnUrl, staticAssets, assetMatches };
