'use strict';

const https = require('node:https');
const tls = require('node:tls');
const net = require('node:net');
const { publicConnectionTarget, sameAddress } = require('./hosted-policy.cjs');

class InstrumentRelay {
  #requests = new Set();
  #sockets = new Set();
  #closed = false;
  #received = 0;
  #fixture;
  constructor(fixture = null) {
    if (
      fixture !== null &&
      (fixture.origin !== 'https://127.0.0.1:' + new URL(fixture.origin).port || !Buffer.isBuffer(fixture.ca))
    )
      throw new Error('Invalid local instrument fixture.');
    this.#fixture = fixture;
  }
  async send(claim, deadline, live, beforeSend = async () => {}) {
    deadline = Math.min(deadline, performance.now() + 8000);
    const url = new URL(claim.url);
    if (
      this.#closed ||
      typeof live !== 'function' ||
      !live('admit') ||
      !Number.isFinite(deadline) ||
      deadline <= performance.now() ||
      !Buffer.isBuffer(claim.body) ||
      claim.body.length > 65536 ||
      url.href !== claim.url ||
      url.protocol !== 'https:' ||
      url.username ||
      url.password ||
      url.hash ||
      (this.#fixture === null
        ? Boolean(url.port) || net.isIP(url.hostname) !== 0
        : url.origin !== this.#fixture.origin) ||
      !Number.isSafeInteger(claim.responseLimit) ||
      claim.responseLimit < 0 ||
      claim.responseLimit > 2097152
    ) {
      throw new Error('Instrument transport scope is unavailable.');
    }
    const timeout = Math.min(8000, deadline - performance.now());
    let lookupTimer;
    const selected =
      this.#fixture === null
        ? await Promise.race([
            publicConnectionTarget(url.hostname),
            new Promise((_, reject) => {
              lookupTimer = setTimeout(() => reject(new Error('Lookup expired.')), Math.min(1000, timeout));
            })
          ]).finally(() => clearTimeout(lookupTimer))
        : { address: '127.0.0.1', family: 4 };
    if (this.#closed || !live('admit') || performance.now() >= deadline)
      throw new Error('Instrument transport scope ended.');
    return new Promise((resolve, reject) => {
      let request,
        socket,
        agent,
        idle,
        done = false;
      const finish = (value, failure = false) => {
        if (done) return;
        done = true;
        clearTimeout(timer);
        clearTimeout(idle);
        request?.destroy();
        socket?.destroy();
        agent?.destroy();
        if (failure) reject(new Error('Instrument transport failed.'));
        else resolve(value);
      };
      const timer = setTimeout(
        () => finish(null, true),
        Math.max(1, Math.min(8000, deadline - performance.now()))
      );
      const arm = () => {
        clearTimeout(idle);
        idle = setTimeout(
          () => finish(null, true),
          Math.max(1, Math.min(5000, deadline - performance.now()))
        );
      };
      try {
        const headers = {
          ...claim.headers,
          'accept-encoding': 'identity',
          ...(claim.body.length || claim.method === 'POST'
            ? { 'content-length': String(claim.body.length) }
            : {})
        };
        socket = tls.connect({
          host: selected.address,
          port: url.port || 443,
          servername: net.isIP(url.hostname) ? undefined : url.hostname,
          rejectUnauthorized: true,
          ALPNProtocols: ['http/1.1'],
          ...(this.#fixture === null ? {} : { ca: this.#fixture.ca })
        });
        this.#sockets.add(socket);
        socket.once('close', () => this.#sockets.delete(socket));
        socket.on('error', () => finish(null, true));
        socket.once('secureConnect', async () => {
          try {
            await beforeSend();
            if (
              done ||
              this.#closed ||
              !live('admit') ||
              performance.now() >= deadline ||
              !socket.authorized ||
              !sameAddress(socket.remoteAddress, selected.address) ||
              tls.checkServerIdentity(url.hostname, socket.getPeerCertificate())
            )
              return finish(null, true);
            // HTTP is constructed only after the selected peer and original TLS hostname are verified.
            agent = new https.Agent({ keepAlive: false, maxSockets: 1 });
            agent.createConnection = (_options, callback) => {
              callback(null, socket);
              return socket;
            };
            request = https.request(
              {
                hostname: url.hostname,
                port: url.port || 443,
                path: url.pathname + url.search,
                method: claim.method,
                headers,
                rejectUnauthorized: true,
                maxHeaderSize: 16384,
                agent
              },
              (response) => {
                try {
                  const fields = response.rawHeaders;
                  const encoding = response.headers['content-encoding'];
                  const length = response.headers['content-length'];
                  if (
                    !live('receive') ||
                    this.#closed ||
                    performance.now() >= deadline ||
                    fields.length > 128 ||
                    fields.reduce((sum, item) => sum + Buffer.byteLength(item), 0) > 16384 ||
                    (encoding !== undefined && encoding !== 'identity') ||
                    (length !== undefined &&
                      (!/^[0-9]+$/u.test(length) || Number(length) > claim.responseLimit)) ||
                    !Number.isInteger(response.statusCode) ||
                    response.statusCode < 200 ||
                    response.statusCode > 599
                  ) {
                    response.destroy();
                    finish(null, true);
                    return;
                  }
                  const chunks = [];
                  let bytes = 0;
                  response.on('data', (chunk) => {
                    if (done) return;
                    bytes += chunk.length;
                    this.#received += chunk.length;
                    if (
                      this.#closed ||
                      !live('receive') ||
                      bytes > claim.responseLimit ||
                      this.#received > 12582912 ||
                      performance.now() >= deadline
                    ) {
                      response.destroy();
                      finish(null, true);
                      return;
                    }
                    chunks.push(chunk);
                    arm();
                  });
                  response.on('error', () => finish(null, true));
                  response.on('aborted', () => finish(null, true));
                  response.on('end', () => {
                    if (done) return;
                    if (
                      !response.complete ||
                      !live('receive') ||
                      this.#closed ||
                      performance.now() >= deadline
                    )
                      return finish(null, true);
                    finish({
                      status: response.statusCode,
                      headers: response.headers,
                      body: Buffer.concat(chunks),
                      fields: Array.from({ length: fields.length / 2 }, (_, index) => ({
                        name: fields[index * 2],
                        value: fields[index * 2 + 1]
                      }))
                    });
                  });
                } catch {
                  finish(null, true);
                }
              }
            );
            this.#requests.add(request);
            request.once('close', () => this.#requests.delete(request));
            request.on('error', () => finish(null, true));
            request.on('information', () => finish(null, true));
            if (this.#closed || !live('admit') || performance.now() >= deadline) return finish(null, true);
            request.end(claim.body);
            arm();
          } catch {
            finish(null, true);
          }
        });
        arm();
      } catch {
        finish(null, true);
      }
    });
  }
  async close() {
    this.#closed = true;
    return (
      await Promise.all(
        [...this.#requests, ...this.#sockets].map(
          (request) =>
            new Promise((resolve) => {
              if (request.closed) {
                resolve(true);
                return;
              }
              let timer = setTimeout(() => resolve(false), 100);
              request.once('close', () => {
                clearTimeout(timer);
                resolve(true);
              });
              request.destroy();
            })
        )
      )
    ).every(Boolean);
  }
  toJSON() {
    throw new Error('Private instrument relay cannot be serialized.');
  }
}

module.exports = { InstrumentRelay };
