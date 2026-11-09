'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { createHash, randomUUID } = require('node:crypto');

const hash = (value) => createHash('sha256').update(value).digest('hex');
const financialRoles = new Set(['collect', 'consent_create', 'captcha_submit', 'consent_verify', 'complete']);

// Each exclusive record and its directory entry are durable before the caller can send/release.
class InstrumentJournal {
  #directory;
  #sync;
  #sequence = 0;
  #durable = 0;
  #poisoned = false;
  #closed = false;
  #pending = new Map();
  #used = new Set();
  #financial = new Set();
  #possibleFinancial = false;
  constructor(directory, provenance, synchronize = fs.fsyncSync) {
    if (
      typeof directory !== 'string' ||
      !path.isAbsolute(directory) ||
      fs.realpathSync(directory) !== directory ||
      typeof synchronize !== 'function'
    )
      throw new Error('Private instrument journal is unavailable.');
    const stat = fs.lstatSync(directory);
    if (
      !stat.isDirectory() ||
      (stat.mode & 0o777) !== 0o700 ||
      (typeof process.getuid === 'function' && stat.uid !== process.getuid()) ||
      fs.readdirSync(directory).length
    )
      throw new Error('Private instrument journal is unavailable.');
    this.#directory = directory;
    this.#sync = synchronize;
    this.#write({ phase: 'profile', provenance });
  }

  #write(record) {
    if (this.#poisoned || this.#closed) throw new Error('Private instrument journal is closed.');
    const filename = path.join(this.#directory, String(this.#sequence++).padStart(4, '0') + '.json');
    let fd, directory;
    try {
      const bytes = Buffer.from(JSON.stringify(record));
      if (bytes.length > 16384) throw new Error();
      fd = fs.openSync(
        filename,
        fs.constants.O_WRONLY | fs.constants.O_CREAT | fs.constants.O_EXCL | fs.constants.O_NOFOLLOW,
        0o600
      );
      if (fs.writeSync(fd, bytes) !== bytes.length) throw new Error();
      this.#sync(fd);
      fs.closeSync(fd);
      fd = undefined;
      directory = fs.openSync(this.#directory, fs.constants.O_RDONLY | fs.constants.O_NOFOLLOW);
      this.#sync(directory);
      fs.closeSync(directory);
      directory = undefined;
      this.#durable++;
    } catch {
      this.#poisoned = true;
      throw new Error('Private instrument durability failed.');
    } finally {
      for (const descriptor of [fd, directory])
        if (descriptor !== undefined) {
          try {
            fs.closeSync(descriptor);
          } catch {
            this.#poisoned = true;
          }
        }
    }
  }

  prepare(claim) {
    if (
      this.#poisoned ||
      this.#closed ||
      !claim ||
      !Buffer.isBuffer(claim.body) ||
      typeof claim.role !== 'string' ||
      !/^[a-z_]{1,32}$/u.test(claim.role) ||
      !Number.isSafeInteger(claim.ordinal) ||
      claim.ordinal < 0
    )
      throw new Error('Instrument action is unavailable.');
    const financial = financialRoles.has(claim.role),
      identity = claim.role + ':' + claim.ordinal;
    const wire = hash(claim.url) + ':' + hash(claim.body);
    if (
      this.#used.has(identity) ||
      this.#used.size >= 240 ||
      (financial && (this.#financial.has(claim.role) || this.#financial.has(wire)))
    ) {
      this.#poisoned = true;
      throw new Error('Instrument action cannot be replayed.');
    }
    const record = {
      phase: claim.method === 'UI' ? 'possible_action' : 'possible_send',
      action: randomUUID(),
      role: claim.role,
      ordinal: claim.ordinal,
      method: claim.method,
      target_sha256: hash(claim.url),
      body_sha256: hash(claim.body),
      headers_sha256: hash(JSON.stringify(claim.headers)),
      body_bytes: claim.body.length,
      source_request_id: claim.requestId ?? null,
      scope_sha256: claim.scope,
      financial
    };
    this.#write(record);
    this.#used.add(identity);
    this.#pending.set(identity, record);
    if (financial) {
      this.#financial.add(claim.role);
      this.#financial.add(wire);
      this.#possibleFinancial = true;
    }
    return record.action;
  }

  observed(claim, action, response, identities = {}, matched = true) {
    const identity = claim.role + ':' + claim.ordinal,
      prepared = this.#pending.get(identity);
    if (
      !prepared ||
      prepared.action !== action ||
      prepared.target_sha256 !== hash(claim.url) ||
      prepared.body_sha256 !== hash(claim.body) ||
      !Buffer.isBuffer(response.body) ||
      !identities ||
      typeof identities !== 'object' ||
      Array.isArray(identities) ||
      Object.entries(identities).some(
        ([name, value]) =>
          ![
            'consent_id',
            'payments_customer_id',
            'method_id',
            'initial_intent_id',
            'checkout_id',
            'source_id'
          ].includes(name) ||
          typeof value !== 'string' ||
          !/^[A-Za-z0-9_-]{1,128}$/u.test(value)
      )
    ) {
      this.#poisoned = true;
      throw new Error('Instrument result is unbound.');
    }
    this.#write({
      phase: 'observed',
      action,
      role: claim.role,
      ordinal: claim.ordinal,
      response_sha256: hash(response.body),
      http_status: response.status,
      response_bytes: response.body.length,
      identities,
      response_validation: matched ? 'matched' : 'withheld'
    });
    if (matched) this.#pending.delete(identity);
    else this.#poisoned = true;
  }

  fail() {
    this.#poisoned = true;
  }
  check(claim, action) {
    const record = this.#pending.get(claim.role + ':' + claim.ordinal);
    const matched =
      this.healthy() &&
      record?.action === action &&
      record.method === claim.method &&
      record.target_sha256 === hash(claim.url) &&
      record.body_sha256 === hash(claim.body) &&
      record.headers_sha256 === hash(JSON.stringify(claim.headers)) &&
      record.scope_sha256 === claim.scope &&
      record.source_request_id === (claim.requestId ?? null);
    if (!matched) this.#poisoned = true;
    return matched;
  }
  healthy() {
    return !this.#closed && !this.#poisoned;
  }
  evidence() {
    return {
      journal_records: this.#durable,
      possible_financial_send: this.#possibleFinancial,
      journal_healthy: this.healthy(),
      unresolved_actions: this.#pending.size
    };
  }
  close() {
    const clean = !this.#poisoned && this.#pending.size === 0;
    this.#closed = true;
    return clean;
  }
  toJSON() {
    throw new Error('Private instrument journal cannot be serialized.');
  }
}

module.exports = { InstrumentJournal, hash, financialRoles };
