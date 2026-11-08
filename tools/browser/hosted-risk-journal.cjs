'use strict';

const fs = require('node:fs');
const { createHash } = require('node:crypto');

class RiskJournalFailure extends Error {
  constructor(phase) { super('Private risk durability handshake failed.'); this.phase = phase; }
}

class RiskJournalClient {
  #nonce;
  #input;
  #output;
  #buffer = Buffer.alloc(0);
  #sequence = 0;
  #pending = null;
  #poisoned = false;
  #queued = 0;
  #chain = Promise.resolve();
  #descriptors;
  #prepared = 0;
  #results = 0;
  #closed = false;
  constructor(bootstrap, input, output, descriptors = []) {
    if (!bootstrap || Object.keys(bootstrap).sort().join(',') !== 'nonce,version'
        || bootstrap.version !== 1 || !/^[a-f0-9]{64}$/u.test(bootstrap.nonce ?? '')) {
      throw new RiskJournalFailure('configuration');
    }
    this.#nonce = bootstrap.nonce;
    this.#input = input;
    this.#output = output;
    this.#descriptors = descriptors;
    input.on('data', chunk => this.#receive(chunk));
    input.on('error', () => { if (!this.#closed) this.#stop(); });
    input.on('end', () => { if (!this.#closed) this.#stop(); });
    output.on('error', () => { if (!this.#closed) this.#stop(); });
  }

  static fromDescriptors(bootstrap) {
    if (!fs.fstatSync(3).isFIFO() || !fs.fstatSync(4).isFIFO()) throw new RiskJournalFailure('configuration');
    return new RiskJournalClient(bootstrap,
      fs.createReadStream(null, { fd: 4, autoClose: false }),
      fs.createWriteStream(null, { fd: 3, autoClose: false }), [3, 4]);
  }

  #stop() {
    this.#poisoned = true;
    if (this.#pending) {
      clearTimeout(this.#pending.timer);
      this.#pending.reject(new RiskJournalFailure('acknowledgement'));
      this.#pending = null;
    }
  }

  #receive(chunk) {
    try {
      if (!Buffer.isBuffer(chunk)) chunk = Buffer.from(chunk);
      this.#buffer = Buffer.concat([this.#buffer, chunk]);
      if (this.#buffer.length > 4096) throw new Error();
      const line = this.#buffer.indexOf(10);
      if (line < 0) return;
      if (line !== this.#buffer.length - 1 || !this.#pending) throw new Error();
      const raw = new TextDecoder('utf-8', { fatal: true }).decode(this.#buffer.subarray(0, line));
      this.#buffer = Buffer.alloc(0);
      const response = JSON.parse(raw);
      // ACKs contain primitive fields only. Reject duplicate/escaped-alias keys before trusting a value.
      const names = [...raw.matchAll(/"((?:[^"\\]|\\.)*)"\s*:/gu)].map(match => JSON.parse('"' + match[1] + '"'));
      if (new Set(names).size !== names.length || !response || typeof response !== 'object' || Array.isArray(response)
          || Object.keys(response).sort().join(',') !== 'durable,nonce,ordinal,phase,role,sequence,version'
          || response.version !== 1 || response.nonce !== this.#nonce || response.durable !== true
          || response.sequence !== this.#pending.sequence || response.phase !== this.#pending.phase
          || response.role !== this.#pending.role || response.ordinal !== this.#pending.ordinal) throw new Error();
      clearTimeout(this.#pending.timer);
      this.#pending.resolve();
      this.#pending = null;
    } catch { this.#stop(); }
  }

  #exchange(data, deadline) {
    if (this.#pending || this.#poisoned || !Number.isFinite(deadline) || deadline <= performance.now()) {
      return Promise.reject(new RiskJournalFailure('acknowledgement'));
    }
    const message = { version: 1, nonce: this.#nonce, sequence: ++this.#sequence, ...data };
    const raw = JSON.stringify(message) + '\n';
    if (Buffer.byteLength(raw) > 4096) return Promise.reject(new RiskJournalFailure('framing'));
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => this.#stop(), Math.max(1, Math.min(1000, deadline - performance.now())));
      this.#pending = { sequence: message.sequence, phase: message.phase, role: message.role,
        ordinal: message.ordinal, resolve, reject, timer };
      try {
        this.#output.write(raw, error => { if (error) this.#stop(); });
      } catch { this.#stop(); }
    });
  }

  run(claim, invoke, validate, deadline) {
    if (this.#closed || this.#poisoned || this.#queued >= 2) return Promise.reject(new RiskJournalFailure('queue'));
    this.#queued++;
    const task = this.#chain.then(async () => {
      if (this.#closed || this.#poisoned || deadline <= performance.now()) throw new RiskJournalFailure('acknowledgement');
      const hash = value => createHash('sha256').update(value).digest('hex');
      const metadata = { role: claim.role, ordinal: claim.ordinal, body_sha256: hash(claim.body),
        target_sha256: hash(claim.url), target_uri: claim.url, headers_sha256: hash(JSON.stringify(claim.headers)),
        scope_sha256: claim.scope, session_sha256: claim.sessionHash, body_bytes: claim.body.length };
      await this.#exchange({ phase: 'prepare', ...metadata }, deadline);
      this.#prepared++;
      if (this.#closed || this.#poisoned || deadline <= performance.now()) throw new RiskJournalFailure('acknowledgement');
      const identity = { role: claim.role, ordinal: claim.ordinal,
        body_sha256: metadata.body_sha256, target_sha256: metadata.target_sha256 };
      let response;
      try { response = await invoke(); }
      catch {
        try { await this.#exchange({ phase: 'failed', ...identity }, Math.max(deadline, performance.now() + 250)); }
        catch { /* Durable possible-send state already exists; never retry an unknown ACK. */ }
        this.#poisoned = true;
        throw new RiskJournalFailure('send');
      }
      let matched = false;
      try { matched = validate(response) === true; } catch { /* No original exception is retained. */ }
      await this.#exchange({ phase: 'observed', ...identity, http_status: response.status,
        response_bytes: response.body.length, response_sha256: hash(response.body),
        response_validation: matched ? 'matched' : 'withheld' }, Math.max(deadline, performance.now() + 250));
      this.#results++;
      if (!matched) { this.#poisoned = true; throw new RiskJournalFailure('response'); }
      return response;
    }).catch(() => { this.#poisoned = true; throw new RiskJournalFailure('durability'); })
      .finally(() => { this.#queued--; });
    this.#chain = task.catch(() => {});
    return task;
  }

  async close() {
    this.#closed = true;
    if (this.#pending || this.#queued > 0) this.#stop();
    this.#input.destroy();
    this.#output.destroy();
    for (const descriptor of this.#descriptors) {
      try { fs.closeSync(descriptor); } catch { /* Already closed is not a new network action. */ }
    }
    await this.#chain;
  }

  evidence() {
    return { risk_journal_prepared: this.#prepared, risk_journal_results: this.#results,
      risk_journal_poisoned: this.#poisoned, risk_journal_closed: this.#closed };
  }

  toJSON() { throw new Error('Private risk journal cannot be serialized.'); }
}

module.exports = { RiskJournalClient, RiskJournalFailure };
