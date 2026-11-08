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
  #token = null;
  #scope = false;
  #expires = 0;
  #payerAttempted = false;
  #expected;
  constructor(initial, fixtureOrigin = null, expected = null) {
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
    this.#expected = expected;
    if (expected !== null) {
      if (!expected || typeof expected !== 'object' || Array.isArray(expected)
          || Object.keys(expected).sort().join(',') !== 'checkout_id,currency,mode'
          || typeof expected.checkout_id !== 'string' || expected.checkout_id.length === 0
          || Buffer.byteLength(expected.checkout_id, 'utf8') > 256 || /[\u0000-\u0020]/u.test(expected.checkout_id)
          || expected.mode !== 'SETUP' || !/^[A-Z]{3}$/u.test(expected.currency)) {
        throw new Error('Invalid private browser expectation.');
      }
      this.#expected = Object.freeze({ ...expected });
      // This is correlation with the SDK-verified URL, not independent JWT signature verification.
      const tokens = url.searchParams.getAll('s');
      if (tokens.length === 1 && /^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/u.test(tokens[0])) {
        try {
          const claim = JSON.parse(Buffer.from(tokens[0].split('.')[1], 'base64url').toString('utf8'));
          this.#scope = claim !== null && typeof claim === 'object' && !Array.isArray(claim)
            && typeof claim.resource_id === 'string' && claim.resource_id.length <= 1024
            && claim.resource_id.includes(':') && claim.resource_id.split(':').pop() === expected.checkout_id
            && Number.isSafeInteger(claim.exp) && claim.exp > Math.floor(Date.now() / 1000);
          if (this.#scope) {
            this.#token = tokens[0];
            this.#expires = claim.exp;
          }
        } catch { /* No raw URL/token/claim becomes an error or diagnostic. */ }
      }
    }
  }

  claimPayerRequest(value, method, type, headers, body = null) {
    const url = parse(value);
    const initial = new URL(this.#initial);
    if (!this.requestScopeVerified() || this.#payerAttempted || !url || method !== 'GET'
        || !['xhr', 'fetch'].includes(type) || body !== null || url.origin !== initial.origin
        || url.pathname !== '/billing/payer-web-api/checkout'
        || !/^\?ts=[0-9]{13}$/u.test(url.search) || url.hash || value !== url.origin + url.pathname + url.search
        || headers.authorization !== `Bearer ${this.#token}`) return false;
    // Set before sending: the public application's interceptor may otherwise retry network/5xx failures.
    this.#payerAttempted = true;
    return true;
  }

  requestScopeVerified() {
    return this.#scope && this.#expires > Math.floor(Date.now() / 1000)
      && (this.#fixture !== null || this.candidateOrigin() === 'sandbox');
  }

  bindingEvidence() {
    return { checkout_token_matches: this.#scope, payer_request_scope_verified: this.requestScopeVerified(),
      payer_get_attempted: this.#payerAttempted, payer_customer_mapping_verified: false,
      payer_payload_delivered: false, financial_routing_verified: false };
  }

  observePayer(body) {
    try {
      if (!Buffer.isBuffer(body) || body.length > 65536) throw new Error();
      const data = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(body));
      if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error();
      const mode = data.mode === this.#expected?.mode;
      const currency = data.currency === this.#expected?.currency;
      return { payer_observation: mode && currency ? 'matched' : 'mode_or_currency_mismatch',
        payer_mode_matches: mode, payer_currency_matches: currency };
    } catch {
      return { payer_observation: 'invalid_response', payer_mode_matches: false, payer_currency_matches: false };
    }
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
