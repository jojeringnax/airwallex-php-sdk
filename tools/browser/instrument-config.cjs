'use strict';

const { parseInstrumentJson: parse, keys, object, identity, uuid } = require('./instrument-json.cjs');
const { hash } = require('./instrument-journal.cjs');
const { canonical, query } = require('./instrument-services.cjs');
const { gunzipSync } = require('node:zlib');

const clientKey = 'client-LJmokBKXH96F2ZdI0ZYDyd27hX7Z8HfNU35XJvv3jxa';
const sdkTypes = new Set(['javascript-client', 'javascript-client-react']);
const sourcePair = (body, wire) =>
  sdkTypes.has(body) &&
  sdkTypes.has(wire) &&
  !(body === 'javascript-client-react' && wire === 'javascript-client');
const standard = new Set([
  'accept',
  'accept-language',
  'accept-encoding',
  'content-type',
  'content-length',
  'host',
  'connection',
  'origin',
  'referer',
  'user-agent',
  'priority',
  'sec-ch-ua',
  'sec-ch-ua-mobile',
  'sec-ch-ua-platform',
  'sec-fetch-site',
  'sec-fetch-mode',
  'sec-fetch-dest'
]);
function target(value) {
  const url = canonical(value);
  if (
    !url ||
    !query(url) ||
    url.origin !== 'https://featureassets.org' ||
    url.pathname !== '/v1/initialize' ||
    [...url.searchParams.keys()].sort().join(',') !== 'k,se,sid,st,sv,t' ||
    url.searchParams.get('k') !== clientKey ||
    url.searchParams.get('se') !== '1' ||
    url.searchParams.get('sv') !== '3.20.3' ||
    !sdkTypes.has(url.searchParams.get('st')) ||
    !uuid(url.searchParams.get('sid')) ||
    !/^[0-9]{13}$/u.test(url.searchParams.get('t'))
  )
    return null;
  return url;
}
class InstrumentConfig {
  #reads;
  #used = false;
  #validated = false;
  #delivered = false;
  #session;
  #stable;
  #sdk;
  #events = 0;
  constructor(reads) {
    this.#reads = reads;
  }
  claim(meta) {
    const eventURL = canonical(meta.url);
    if (eventURL?.origin === 'https://prodregistryv2.org' && eventURL.pathname === '/v1/rgstr')
      return this.#event(meta, eventURL);
    const url = target(meta.url);
    if (!url || meta.sourceRole !== 'main' || meta.method !== 'POST' || !['fetch', 'xhr'].includes(meta.type))
      return null;
    if (
      this.#used ||
      meta.body.length > 65536 ||
      Math.abs(Number(url.searchParams.get('t')) - Date.now()) > 60000 ||
      !identity(this.#reads.payer?.sellerId)
    )
      throw new Error('Fresh feature configuration is unavailable.');
    let bytes = 0;
    const origin = new URL(this.#reads.initial).origin;
    for (const [name, value] of Object.entries(meta.headers)) {
      if (
        !standard.has(name) ||
        typeof value !== 'string' ||
        /[^\x20-\x7e]/u.test(value) ||
        value.length > 4096 ||
        (bytes += Buffer.byteLength(name + value)) > 16384
      )
        throw new Error('Feature headers are unsupported.');
    }
    if (
      meta.headers.origin !== origin ||
      (meta.headers.referer !== undefined && meta.headers.referer !== origin + '/') ||
      !/^text\/plain;\s*charset=UTF-8$/iu.test(meta.headers['content-type'] ?? '')
    )
      throw new Error('Feature context is unbound.');
    const reversed = new TextDecoder('utf-8', { fatal: true }).decode(meta.body).split('').reverse().join('');
    const decoded = Buffer.from(reversed, 'base64');
    if (decoded.toString('base64') !== reversed) throw new Error('Feature encoding changed.');
    const value = parse(decoded),
      metadata = value?.statsigMetadata;
    if (
      !keys(
        value,
        ['user', 'hash', 'deltasResponseRequested', 'full_checksum', 'statsigMetadata'],
        ['user', 'hash', 'deltasResponseRequested', 'full_checksum', 'statsigMetadata']
      ) ||
      value.hash !== 'djb2' ||
      value.deltasResponseRequested !== false ||
      value.full_checksum !== null ||
      !keys(
        value.user,
        ['userID', 'statsigEnvironment', 'analyticsOnlyMetadata'],
        ['userID', 'statsigEnvironment', 'analyticsOnlyMetadata']
      ) ||
      value.user.userID !== this.#reads.payer.sellerId ||
      !keys(value.user.statsigEnvironment, ['tier'], ['tier']) ||
      value.user.statsigEnvironment.tier !== 'staging' ||
      !keys(value.user.analyticsOnlyMetadata, []) ||
      !keys(
        metadata,
        ['sdkVersion', 'sdkType', 'stableID', 'sessionID', 'fallbackUrl'],
        ['sdkVersion', 'sdkType', 'stableID', 'sessionID', 'fallbackUrl']
      ) ||
      metadata.sdkVersion !== '3.20.3' ||
      !sourcePair(metadata.sdkType, url.searchParams.get('st')) ||
      !uuid(metadata.stableID) ||
      metadata.sessionID !== url.searchParams.get('sid') ||
      metadata.fallbackUrl !== null
    )
      throw new Error('Feature initialization wire changed.');
    this.#used = true;
    this.#session = metadata.sessionID;
    this.#stable = metadata.stableID;
    this.#sdk = url.searchParams.get('st');
    return {
      role: 'feature_initialize',
      ordinal: 0,
      method: 'POST',
      url: meta.url,
      body: meta.body,
      headers: {
        accept: meta.headers.accept ?? '*/*',
        'content-type': meta.headers['content-type'],
        origin,
        ...(meta.headers.referer === undefined ? {} : { referer: meta.headers.referer })
      },
      responseLimit: 1048576,
      scope: hash(this.#reads.initial),
      security: false
    };
  }
  #event(meta, url) {
    const params = url.searchParams,
      gzip = params.get('gz') === '1';
    if (meta.sourceRole !== 'main' || meta.method !== 'POST' || meta.type !== 'fetch') return null;
    if (
      !this.#delivered ||
      this.#events >= 20 ||
      meta.body.length > 65536 ||
      !query(url) ||
      [...params.keys()].sort().join(',') !== (gzip ? 'ec,gz,k,sid,st,sv,t' : 'ec,k,sid,st,sv,t') ||
      params.get('k') !== clientKey ||
      !sdkTypes.has(params.get('st')) ||
      (this.#sdk === 'javascript-client-react' && params.get('st') !== this.#sdk) ||
      params.get('sv') !== '3.20.3' ||
      params.get('sid') !== this.#session ||
      !/^[0-9]{13}$/u.test(params.get('t')) ||
      Math.abs(Number(params.get('t')) - Date.now()) > 60000 ||
      !/^[1-9][0-9]{0,2}$/u.test(params.get('ec'))
    )
      throw new Error('Feature telemetry is unbound.');
    const origin = new URL(this.#reads.initial).origin;
    let bytes = 0;
    for (const [name, value] of Object.entries(meta.headers)) {
      if (
        !standard.has(name) ||
        typeof value !== 'string' ||
        /[^\x20-\x7e]/u.test(value) ||
        value.length > 4096 ||
        (bytes += Buffer.byteLength(name + value)) > 16384
      )
        throw new Error('Feature telemetry headers changed.');
    }
    if (
      meta.headers.origin !== origin ||
      (meta.headers.referer !== undefined && meta.headers.referer !== origin + '/') ||
      (gzip
        ? meta.headers['content-type'] !== undefined
        : !/^text\/plain;\s*charset=UTF-8$/iu.test(meta.headers['content-type'] ?? ''))
    )
      throw new Error('Feature telemetry context changed.');
    const value = parse(gzip ? gunzipSync(meta.body, { maxOutputLength: 65536 }) : meta.body),
      metadata = value?.statsigMetadata;
    if (
      !keys(value, ['events', 'statsigMetadata'], ['events', 'statsigMetadata']) ||
      !Array.isArray(value.events) ||
      value.events.length !== Number(params.get('ec')) ||
      !value.events.every(object) ||
      !keys(
        metadata,
        ['sdkVersion', 'sdkType', 'stableID', 'sessionID', 'fallbackUrl'],
        ['sdkVersion', 'sdkType', 'stableID', 'sessionID', 'fallbackUrl']
      ) ||
      metadata.sdkVersion !== '3.20.3' ||
      !sourcePair(metadata.sdkType, params.get('st')) ||
      metadata.stableID !== this.#stable ||
      metadata.sessionID !== this.#session ||
      metadata.fallbackUrl !== null
    )
      throw new Error('Feature telemetry identity changed.');
    this.#sdk = params.get('st');
    return {
      role: 'feature_events',
      ordinal: this.#events++,
      method: 'POST',
      url: meta.url,
      body: meta.body,
      headers: {
        accept: meta.headers.accept ?? '*/*',
        origin,
        ...(gzip ? {} : { 'content-type': meta.headers['content-type'] }),
        ...(meta.headers.referer === undefined ? {} : { referer: meta.headers.referer })
      },
      responseLimit: 65536,
      scope: hash(this.#reads.initial),
      security: false
    };
  }
  validate(claim, response) {
    if (claim.role === 'feature_events') {
      if (
        response.status < 200 ||
        response.status >= 300 ||
        ![new URL(this.#reads.initial).origin, '*'].includes(response.headers['access-control-allow-origin'])
      )
        throw new Error('Feature telemetry acknowledgement failed.');
      return {};
    }
    if (
      response.status !== 200 ||
      !/^application\/json(?:\s*;|$)/iu.test(response.headers['content-type'] ?? '') ||
      ![new URL(this.#reads.initial).origin, '*'].includes(response.headers['access-control-allow-origin'])
    )
      throw new Error('Feature response is unbound.');
    const value = parse(response.body, 1048576);
    if (!object(value) || value.has_updates !== true)
      throw new Error('Fresh feature configuration is unavailable.');
    this.#validated = true;
    return {};
  }
  delivered(claim) {
    if (claim.role === 'feature_events') return;
    if (!this.#validated) throw new Error('Feature proof is unavailable.');
    this.#delivered = true;
  }
  ready() {
    return this.#delivered;
  }
  evidence() {
    return { feature_requests: (this.#used ? 1 : 0) + this.#events };
  }
  toJSON() {
    throw new Error('Private feature context cannot be serialized.');
  }
}
module.exports = { InstrumentConfig, clientKey, target };
