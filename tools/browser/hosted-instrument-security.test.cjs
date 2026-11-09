'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { InstrumentSecurityScope, PendingLoader } = require('./hosted-pending-loader.cjs');
const { publicAddress, publicConnectionTarget, sameAddress } = require('./hosted-policy.cjs');
const util = require('node:util');

function fixture() {
  let clock = 0, bound = false, detached = false, generation = 0, sends = 0, acks = 0, aborts = 0;
  const parent = { renderEnabled: () => true, bindingEvidence: () => ({ payer_payload_delivered: true }),
    normalEvidence: () => ({ normal_reads_requested: true }), normalSourceMatches: value => value === 'PRIVATE_DOCUMENT',
    riskSessionMatches: value => bound && value === 'PRIVATE_SESSION' };
  const scope = new InstrumentSecurityScope(parent, 40000, () => clock);
  const frame = { url: () => 'PRIVATE_DOCUMENT', isDetached: () => detached };
  const risk = { loaderTarget: value => value.startsWith('https://static.sandbox.airwallex.com/webapp/fraud/device-fingerprint/index.js?'),
    handle: async () => { acks++; sends++; return true; } };
  const pending = new PendingLoader(risk, scope);
  const request = { frame: () => frame, url: () => 'https://static.sandbox.airwallex.com/webapp/fraud/device-fingerprint/index.js?sessionId=PRIVATE_SESSION&hasScriptId=false&disable3rdTracking=false',
    method: () => 'GET', resourceType: () => 'script' };
  const route = { request: () => request, abort: async () => { aborts++; } };
  const handle = () => pending.handle(route, {}, () => ({ main: frame, bound: bound ? {} : undefined }),
    () => false, () => generation);
  return { parent, scope, pending, request, handle, bind: () => { bound = true; },
    detach: () => { detached = true; }, navigate: () => { generation++; }, clock: value => { clock = value; },
    counts: () => ({ sends, acks, aborts }) };
}

test('new profile has independent immutable 40/30/38/15 bounds and permanent closure', () => {
  const f = fixture();
  assert.equal(f.scope.admissionRemaining(), 30000);
  f.clock(12000); f.scope.enter();
  assert.equal(f.scope.admissionRemaining(), 15000);
  f.clock(25000); f.scope.enter(); // No timer reset.
  assert.equal(f.scope.admissionRemaining(), 2000);
  f.clock(27000); assert.equal(f.scope.normalWindowRemaining(), 0);
  f.scope.close(); f.clock(0); assert.equal(f.scope.admissionRemaining(), 0);
  assert.deepEqual(f.scope.evidence(), { instrument_security_requested: true, instrument_ready: false,
    financial_permission: false, security_graph_complete: false, security_source_first_stop: 'release_loader_window_elapsed' });
  assert.throws(() => JSON.stringify(f.scope));
  assert.doesNotMatch(util.inspect(f.scope), /PRIVATE_SESSION|PRIVATE_DOCUMENT/u);
});

test('early original loader waits privately with zero ACK/send until later exact frame/session proof', async () => {
  const f = fixture(), task = f.handle();
  await new Promise(resolve => setTimeout(resolve, 25));
  assert.deepEqual(f.counts(), { sends: 0, acks: 0, aborts: 0 });
  f.bind(); assert.equal(await task, true);
  assert.deepEqual(f.counts(), { sends: 1, acks: 1, aborts: 0 });
  assert.equal(f.pending.evidence().security_loader_promoted, true);
  assert.throws(() => JSON.stringify(f.pending));
});

for (const fault of ['detach', 'same_url_reload', 'cutoff', 'duplicate', 'wrong_session']) {
  test('unbound original candidate cannot be replaced or sent after ' + fault, async () => {
    const f = fixture();
    if (fault === 'wrong_session') f.request.url = () => 'https://static.sandbox.airwallex.com/webapp/fraud/device-fingerprint/index.js?sessionId=OTHER&hasScriptId=false&disable3rdTracking=false';
    const task = f.handle();
    await new Promise(resolve => setTimeout(resolve, 15));
    if (fault === 'detach') f.detach();
    if (fault === 'same_url_reload') f.navigate();
    if (fault === 'cutoff') f.clock(30001);
    if (fault === 'duplicate') await f.handle();
    f.bind(); await task;
    assert.equal(f.counts().sends, 0); assert.equal(f.counts().acks, 0);
    assert.ok(f.counts().aborts >= 1);
    await f.handle(); assert.equal(f.counts().sends, 0);
  });
}

for (const value of ['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '192.0.2.1',
  '198.18.0.1', '224.0.0.1', '255.255.255.255', '::1', '::ffff:8.8.8.8', 'fe80::1', 'fc00::1', '2001:db8::1', '2002::1']) {
  test('public-only connection policy rejects nonpublic/mapped address ' + value, () => assert.equal(publicAddress(value), false));
}

test('DNS admission rejects an entire mixed answer set and pins a single permitted address', async () => {
  let calls = 0;
  await assert.rejects(publicConnectionTarget('static.sandbox.airwallex.com', async () => {
    calls++; return [{ address: '8.8.8.8', family: 4 }, { address: '127.0.0.1', family: 4 }];
  }));
  const selected = await publicConnectionTarget('static.sandbox.airwallex.com', async () => {
    calls++; return [{ address: '8.8.8.8', family: 4 }, { address: '1.1.1.1', family: 4 }];
  });
  assert.deepEqual(selected, { address: '8.8.8.8', family: 4 }); assert.equal(calls, 2);
  assert.equal(sameAddress('8.8.8.8', selected.address), true);
  assert.equal(sameAddress('127.0.0.1', selected.address), false);
  assert.equal(sameAddress('::ffff:8.8.8.8', selected.address), false);
  for (const host of ['127.0.0.1', '[::1]', 'PRIVATE_USER@airwallex.com', 'airwallex.com/']) {
    await assert.rejects(publicConnectionTarget(host, async () => assert.fail('Forbidden DNS invoked')));
  }
});

test('the actual proxy connection pins the selected public address and checks its connected peer before CONNECT ACK', async () => {
  const net = require('node:net'), dns = require('node:dns/promises'), http = require('node:http');
  const { PassThrough } = require('node:stream');
  const { egressProxy } = require('./hosted-policy.cjs');
  const originalLookup = dns.lookup, originalConnect = net.connect;
  let connected = [], peer = '127.0.0.1';
  const proxy = await egressProxy({ allowsAuthority: (host, port) => host === 'static.sandbox.airwallex.com'
    && String(port) === '443', allowsHttp: () => false }, true);
  try {
    dns.lookup = async () => [{ address: '8.8.8.8', family: 4 }];
    net.connect = (options, callback) => {
      connected.push(options); const socket = new PassThrough();
      Object.defineProperty(socket, 'remoteAddress', { value: peer });
      setImmediate(callback); return socket;
    };
    const probe = () => new Promise(resolve => {
      const request = http.request(proxy.server, { method: 'CONNECT', path: 'static.sandbox.airwallex.com:443' });
      request.on('error', () => resolve(false));
      request.on('connect', (response, socket) => { socket.destroy(); resolve(response.statusCode === 200); });
      request.end();
    });
    assert.equal(await probe(), false);
    peer = '8.8.8.8'; assert.equal(await probe(), true);
    assert.deepEqual(connected, [{ host: '8.8.8.8', port: 443, family: 4 }, { host: '8.8.8.8', port: 443, family: 4 }]);
    assert.equal(proxy.counts.connected, 1);
    assert.equal(proxy.counts.externalConnected, 1);
  } finally { dns.lookup = originalLookup; net.connect = originalConnect; await proxy.close(); }
});

test('admission closes at 30 seconds while retained responses remain bounded by the 38-second release latch', () => {
  const f = fixture(); f.clock(25000); f.scope.enter();
  assert.equal(f.scope.admissionRemaining(), 5000);
  assert.equal(f.scope.normalWindowRemaining(), 13000);
  f.clock(30000); assert.equal(f.scope.admissionRemaining(), 0);
  assert.equal(f.scope.normalWindowRemaining(), 8000);
  f.clock(38000); assert.equal(f.scope.normalWindowRemaining(), 0);
  f.clock(40000); assert.equal(f.scope.normalWindowRemaining(), 0);
});

// Verbatim guard references from immutable 15eeb7b21bf204c6bd6ac2a36b5fd1d4b03aa5e5; no provider source executes.
const referenceLive = String.raw`  live() {
    return this.#enabled && !this.#poisoned && this.#parent.renderEnabled()
      && this.#parent.bindingEvidence().payer_payload_delivered
      && this.#parent.normalEvidence().normal_reads_requested === true
      && this.#parent.normalWindowRemaining() > 0;
  }

`;
const referenceVerify = String.raw`  verifyLoader(body) {
    if (!this.#loader || !this.live() || !Buffer.isBuffer(body) || body.length > 262144
        || digest(body) !== this.#loader.expectedHash) { this.poison('loader_integrity'); return false; }
    this.#verified = true;
    return true;
  }

`;
const referenceOriginal = String.raw`  #original(frame, main, bound) {
    try {
      return performance.now() < this.#caseDeadline && frame.isDetached() === false
        && ((frame === main && this.#parent.normalSourceMatches(frame.url(), true))
        || (frame === bound && this.#parent.normalSourceMatches(frame.url())));
    } catch { return false; }
  }

`;
const referenceScope = String.raw`class InstrumentSecurityScope {
  #parent; #case; #start; #closed = false; #clock;
  constructor(parent, caseDeadline, clock = () => performance.now()) {
    if (!Number.isFinite(caseDeadline) || typeof clock !== 'function') throw new Error('Invalid security scope.');
    this.#parent = parent; this.#case = caseDeadline; this.#clock = clock;
  }
  enter() { this.#start ??= this.#clock(); }
  close() { this.#closed = true; }
  admissionRemaining() {
    return this.#closed ? 0 : Math.max(0, Math.min(this.#case - 10000,
      this.#start === undefined ? Infinity : this.#start + 15000) - this.#clock());
  }
  normalWindowRemaining() {
    return this.#closed ? 0 : Math.max(0, Math.min(this.#case - 2000,
      this.#start === undefined ? this.#case - 10000 : this.#start + 15000) - this.#clock());
  }
  renderEnabled() { return this.#parent.renderEnabled(); }
  bindingEvidence() { return this.#parent.bindingEvidence(); }
  normalEvidence() { return this.#parent.normalEvidence(); }
  normalSourceMatches(...args) { return this.#parent.normalSourceMatches(...args); }
  riskSessionMatches(...args) { return this.#parent.riskSessionMatches(...args); }
  evidence() { return { instrument_security_requested: true, instrument_ready: false,
    financial_permission: false, security_graph_complete: false }; }
  toJSON() { throw new Error('Private security scope cannot be serialized.'); }
}

`;

const fs = require('node:fs'), path = require('node:path'), Module = require('node:module');
const crypto = require('node:crypto');
function compiled(file, replacements = [], overrides = {}) {
  const filename = path.join(__dirname, file);
  let source = fs.readFileSync(filename, 'utf8');
  for (const [start, end, replacement] of replacements) {
    const begin = source.indexOf(start), finish = source.indexOf(end, begin);
    assert.ok(begin >= 0 && finish > begin);
    source = source.slice(0, begin) + replacement + source.slice(finish);
  }
  if (Object.hasOwn(overrides, 'fixture:performance')) {
    source = source.replace("'use strict';", "'use strict';\nconst performance = require('fixture:performance');");
  }
  const loaded = new Module(filename, module);
  loaded.filename = filename; loaded.paths = Module._nodeModulePaths(__dirname);
  const original = loaded.require.bind(loaded);
  loaded.require = name => Object.hasOwn(overrides, name) ? overrides[name] : original(name);
  loaded._compile(source, filename);
  return loaded.exports;
}
const policyReferences = [
  ['  live(report = null) {', '  note(reason)', referenceLive],
  ['  verifyLoader(body, report = null) {', '  // Only values absent', referenceVerify],
];

function policyTrace(reference, failure, action = 'verify') {
  const trace = [], reports = [];
  const body = Buffer.from('SYNTHETIC_PRIVATE_LOADER');
  let selected = null;
  const privateError = new Error();
  for (const key of ['message', 'stack', 'name']) Object.defineProperty(privateError, key, {
    get() { throw new Error('Private Error property inspected.'); },
  });
  const call = (name, value) => { trace.push(name);
    if (selected === 'throw_' + name) throw privateError;
    return selected === name ? false : value; };
  const parent = {
    renderEnabled: () => call('render', true),
    bindingEvidence: () => { call('binding', true); return { get payer_payload_delivered() {
      return call('payer', true); } }; },
    normalEvidence: () => { call('normal', true); return { get normal_reads_requested() {
      return call('selected', true); } }; },
    normalWindowRemaining: () => call('window', 1000),
    normalSourceMatches: () => true, riskSessionMatches: () => true,
  };
  const hash = { ...crypto, createHash: algorithm => { trace.push('hash'); return crypto.createHash(algorithm); } };
  const { HostedRiskPolicy } = compiled('hosted-risk-policy.cjs', reference ? policyReferences : [], { 'node:crypto': hash });
  const value = new HostedRiskPolicy(parent, failure !== 'disabled', {
    origin: 'http://127.0.0.1:1234', bwsOrigin: 'https://127.0.0.1:1235',
    loaderHash: crypto.createHash('sha256').update(body).digest('hex'),
  });
  if (action === 'verify' && failure !== 'claim_missing') value.claimLoader(
    'http://127.0.0.1:1234/fingerprint.js?sessionId=synthetic&hasScriptId=false&disable3rdTracking=false', 'GET', 'script',
    { document: 'SYNTHETIC_PRIVATE_DOCUMENT', role: 'dropin', session: 'synthetic', orderSession: 'synthetic',
      id: 'airwallex-fraud-api', disableTracking: null, merchant: null, tenant: null, project: null });
  if (failure === 'policy_poisoned') value.poison('unknown_dependency');
  const poison = value.poison.bind(value);
  value.poison = reason => { trace.push('poison:' + reason); return poison(reason); };
  selected = failure;
  const data = failure === 'body_not_buffer' ? null : failure === 'body_limit' ? Buffer.alloc(262145)
    : failure === 'body_hash' ? Buffer.from('SYNTHETIC_PRIVATE_WRONG') : body;
  trace.length = 0;
  let result;
  try { result = action === 'live' ? value.live(label => reports.push(label))
    : value.verifyLoader(data, label => reports.push(label)); }
  catch (error) { assert.equal(error, privateError); result = 'same_exception'; }
  return { trace, result, reports, evidence: value.evidence() };
}

for (const [failure, gate] of [
  ['claim_missing', 'claim_missing'], ['policy_poisoned', 'policy_poisoned'], ['render', 'render_unavailable'],
  ['payer', 'payer_not_delivered'], ['selected', 'normal_not_selected'], ['window', 'scope_window_not_positive'],
  ['body_not_buffer', 'body_not_buffer'], ['body_limit', 'body_limit'], ['body_hash', 'body_hash'],
  ['throw_render', null], ['throw_binding', null], ['throw_payer', null], ['throw_normal', null],
  ['throw_selected', null], ['throw_window', null], ['matched', null],
]) {
  test('loader validation preserves immutable operand/hash/poison trace: ' + failure, () => {
    const before = policyTrace(true, failure), after = policyTrace(false, failure);
    assert.deepEqual(after.trace, before.trace);
    assert.deepEqual(after.evidence, before.evidence);
    assert.equal(after.result, before.result);
    assert.deepEqual(after.reports, gate === null ? [] : [gate]);
    assert.doesNotMatch(JSON.stringify(after), /SYNTHETIC_PRIVATE/u);
  });
}
for (const failure of ['disabled', 'policy_poisoned', 'render', 'payer', 'selected', 'window', 'matched']) {
  test('live retains exact short-circuit calls and result: ' + failure, () => {
    const before = policyTrace(true, failure, 'live'), after = policyTrace(false, failure, 'live');
    assert.deepEqual(after.trace, before.trace); assert.equal(after.result, before.result);
  });
}

test('source-stop memo adds no clock calls or behavioral latch under rewind, ties and saturation', () => {
  const Reference = compiled('hosted-pending-loader.cjs', [
    ['class InstrumentSecurityScope', '// One original route', referenceScope],
  ]).InstrumentSecurityScope;
  for (const start of [undefined, 0, 12000, 15000, 25000]) {
    const traces = [[], []]; let now = 0;
    const values = [new Reference({}, 40000, () => { traces[0].push(now); return now; }),
      new InstrumentSecurityScope({}, 40000, () => { traces[1].push(now); return now; })];
    if (start !== undefined) { now = start; values.forEach(scope => scope.enter()); }
    for (now of [0, 15000, 27000, 30000, 38000, 40000, 0, Number.NaN, Infinity]) {
      assert.equal(values[1].admissionRemaining(), values[0].admissionRemaining());
      assert.equal(values[1].normalWindowRemaining(), values[0].normalWindowRemaining());
    }
    assert.deepEqual(traces[1], traces[0]);
    const first = values[1].firstStop();
    values[1].noteStop('SYNTHETIC_PRIVATE_URL');
    values[1].close('bound_detached'); values[0].close();
    for (let index = 0; index < 10000; index++) values[1].noteStop('teardown');
    assert.equal(values[1].firstStop(), first);
    assert.equal(values[1].admissionRemaining(), values[0].admissionRemaining());
    assert.deepEqual(traces[1], traces[0]);
  }
  const f = fixture(); f.scope.noteStop('SYNTHETIC_PRIVATE_URL'); assert.equal(f.scope.firstStop(), 'not_observed');
  f.scope.close('main_document_request'); f.scope.close('teardown');
  assert.equal(f.scope.firstStop(), 'main_document_request');
});

const referenceHandle = String.raw`  async #handle(route, context, main, bound, closing, admissionClosed) {
    if (this.#journal === null) return false;
    if (admissionClosed()) return false;
    const request = route.request();
    let frame;
    try { frame = request.frame(); } catch { return false; }
    if (!this.#original(frame, main, bound)) return false;
    const role = frame === main ? 'main' : 'dropin';
    const document = frame.url();
    if (this.#policy.loaderTarget(request.url())) {
      let delivered = false;
      try {
        this.#rememberDeadline();
        // Only these source-defined attributes enter private memory; no DOM text or input values are read.
        const proof = await this.#value(frame.evaluate(value => {
          const matches = [...document.scripts].filter(element => element.src === value);
          if (matches.length !== 1) return null;
          const script = matches[0];
          return { id: script.id, orderSession: script.getAttribute('data-order-session-id'),
            tenant: script.getAttribute('data-tenant') ?? script.getAttribute('tenant'),
            project: script.getAttribute('data-project-name'),
            disableTracking: script.getAttribute('data-disable-3rd-tracking') };
        }, request.url()));
        const query = new URL(request.url()).searchParams;
        const claim = this.#policy.claimLoader(request.url(), request.method(), request.resourceType(),
          proof === null ? null : { ...proof, document, role,
            session: query.get('sessionId'), merchant: query.get('merchantId') });
        if (!claim || this.#closed || closing() || admissionClosed() || !this.#original(frame, main, bound)) return true;
        this.#beforeCookies = await this.#value(context.cookies());
        if (this.#beforeCookies.some(cookie => riskCookieNames.has(cookie.name))) {
          this.#policy.note('cookie_unbound'); return true;
        }
        if (this.#closed || closing() || admissionClosed() || !this.#original(frame, main, bound)) return true;
        // Retain construction before await; shutdown disposes every created public context.
        this.#rememberDeadline();
        if (this.#instrumentSecurity) {
          const target = request.url();
          const selected = { role: 'loader', ordinal: 0, url: target, headers: { accept: '*/*' },
            body: Buffer.alloc(0), scope: claim.scope,
            sessionHash: require('node:crypto').createHash('sha256').update(query.get('sessionId')).digest('hex') };
          const deadline = Math.min(this.#deadline, this.#caseDeadline,
            performance.now() + this.#parent.normalWindowRemaining());
          const response = await this.#value(this.#journal.run(selected, async () => {
            if (this.#closed || closing() || admissionClosed() || !this.#original(frame, main, bound)
                || !this.#policy.live()) throw new Error('Loader scope is unavailable.');
            return this.#relay.send(target, 'GET', 'loader', selected.headers, selected.body, deadline,
              () => !this.#closed && !closing() && !admissionClosed() && this.#original(frame, main, bound));
          }, value => value.status === 200 && this.#policy.verifyLoader(value.body), deadline));
          if (this.#closed || closing() || !this.#original(frame, main, bound) || !this.#policy.live()) return true;
          await this.#value(route.fulfill({ status: response.status,
            headers: this.#policy.responseHeaders(response), body: response.body }));
          delivered = true;
          return true;
        }
        this.#publicClient ??= this.#playwright.request.newContext({ ignoreHTTPSErrors: false,
          proxy: { server: this.#proxy, bypass: '' } }).then(client => {
          // Retain late completion even when bounded shutdown already reported failure.
          if (this.#closed) void this.#disposePublic(client);
          return client;
        });
        const client = await this.#value(this.#publicClient);
        if (this.#closed || closing() || admissionClosed() || !this.#original(frame, main, bound) || !this.#policy.live()) return true;
        const response = await this.#value(client.get(request.url(), {
          headers: { accept: '*/*', cookie: '', referer: '' }, maxRedirects: 0, maxRetries: 0,
          timeout: Math.max(1, Math.min(this.#parent.normalWindowRemaining(), this.#caseDeadline - performance.now())),
        }).then(created => {
          if (this.#closed) void this.#disposeResponse(created);
          return created;
        }));
        try {
          if (this.#closed || closing() || !this.#original(frame, main, bound)) return true;
          if (response.status() !== 200) { this.#policy.poison('response'); return true; }
          const body = await this.#value(response.body());
          if (!this.#policy.verifyLoader(body)) return true;
          if (this.#closed || closing() || !this.#original(frame, main, bound)) return true;
          const headers = Object.fromEntries(Object.entries(response.headers())
            .filter(([name]) => !['set-cookie', 'set-cookie2'].includes(name.toLowerCase())));
          await this.#value(route.fulfill({ response, headers, body }));
          delivered = true;
        } finally {
          if (!await this.#bounded(this.#disposeResponse(response))) this.#cleanupFailed = true;
        }
      } catch { this.#policy.poison('response'); }
      finally {
        if (!delivered && !await this.#bounded(Promise.resolve().then(() => route.abort('blockedbyclient')))) {
          this.#cleanupFailed = true; this.#policy.poison('shutdown');
        }
      }
      return true;
    }
    if (!['POST', 'OPTIONS'].includes(request.method())) return false;
    if (!this.#policy.telemetryMatches(document, role)) return false;
    // Collect only new known first-party risk cookies after the verified loader. Never pass an arbitrary jar.
    if (this.#beforeCookies !== null
        && !this.#policy.bindCookies(this.#beforeCookies, await this.#value(context.cookies()))) {
      this.#policy.poison('cookie_unbound'); return false;
    }
    const headers = this.#policy.cookieProjection(await this.#value(request.allHeaders()));
    if (headers === null) return false;
    const claim = this.#policy.claim(request.url(), request.method(), request.resourceType(),
      headers, request.postDataBuffer() ?? Buffer.alloc(0), document, role);
    if (claim === null) return false;
    let delivered = false;
    let queued = false;
    try {
      // Exactly the admitted visitor plus eight finite events may wait; sends remain serial.
      if (this.#waiting >= 9) { this.#policy.poison('budget'); return true; }
      this.#waiting++;
      queued = true;
      const task = this.#chain.then(async () => {
        if (this.#closed || closing() || admissionClosed() || !this.#original(frame, main, bound) || !this.#policy.live()) {
          this.#policy.poison('document_changed'); throw new Error('Risk scope is unavailable.');
        }
        const deadline = Math.min(this.#caseDeadline, this.#deadline, performance.now() + this.#parent.normalWindowRemaining());
        const forward = async selected => this.#journal.run(selected, async () => {
          if (this.#closed || closing() || admissionClosed() || !this.#original(frame, main, bound) || !this.#policy.live()) {
            throw new Error('Risk scope is unavailable.');
          }
          this.#policy.invoke(selected.role);
          const response = await this.#relay.send(selected.url, selected.role === 'preflight' ? 'OPTIONS' : 'POST',
            selected.role, selected.headers, selected.body, deadline, this.#instrumentSecurity
              ? () => !this.#closed && !closing() && !admissionClosed() && this.#original(frame, main, bound) : null);
          this.#policy.status(selected.role, response.status);
          return response;
        }, response => this.#policy.validateResponse(selected, response), deadline);
        // Browser route interception omits automatic CORS OPTIONS: perform the exact grant check before the body.
        if (claim.role === 'visitor') {
          const preflight = this.#policy.preflightFor(claim);
          if (preflight !== null) await forward(preflight);
        }
        const response = await forward(claim);
        if (this.#closed || closing() || !this.#original(frame, main, bound) || !this.#policy.live()) {
          throw new Error('Risk scope is unavailable.');
        }
        await this.#value(route.fulfill({ status: response.status,
          headers: this.#policy.responseHeaders(response), body: response.body }));
        delivered = true;
        this.#policy.delivered(claim.role);
      });
      this.#chain = task.catch(() => {});
      await this.#value(task);
    } catch { this.#policy.poison('journal'); }
    finally {
      if (queued) this.#waiting--;
      if (!delivered && !await this.#bounded(Promise.resolve().then(() => route.abort('blockedbyclient')))) {
        this.#cleanupFailed = true; this.#policy.poison('shutdown');
      }
    }
    return true;
  }

`;

async function observerTrace(reference, fault, instrument = true) {
  const trace = [];
  let now = 1000, stage = 'before', detached = false, changed = false, validating = false;
  const performance = { now: () => { trace.push('clock'); return now; } };
  const body = Buffer.from('SYNTHETIC_PRIVATE_LOADER');
  const privateError = new Error();
  for (const key of ['message', 'stack', 'name']) Object.defineProperty(privateError, key, {
    get() { throw new Error('Private Error property inspected.'); },
  });
  const parent = {
    renderEnabled: () => { trace.push('render'); return !(fault === 'render_after_response' && stage !== 'before'); },
    bindingEvidence: () => { trace.push('binding'); return { payer_payload_delivered: true }; },
    normalEvidence: () => { trace.push('normal'); return { normal_reads_requested: true }; },
    normalSourceMatches: (_value, main) => { trace.push(main ? 'main_source' : 'bound_source');
      return !changed || (fault === 'or_alternative' && !main); },
    riskSessionMatches: () => { trace.push('session'); return true; },
    normalWindowRemaining: () => { trace.push('legacy_window'); return 1000; },
  };
  const scope = new InstrumentSecurityScope(parent, 40000, () => { trace.push('scope_clock'); return now; });
  scope.enter();
  const fixture = { origin: 'http://127.0.0.1:1234', bwsOrigin: 'https://127.0.0.1:1235',
    loaderHash: crypto.createHash('sha256').update(body).digest('hex'), ca: Buffer.from('SYNTHETIC_CA') };
  const hash = { ...crypto, createHash: algorithm => { trace.push('hash'); return crypto.createHash(algorithm); } };
  const policies = compiled('hosted-risk-policy.cjs', reference ? policyReferences : [], { 'node:crypto': hash });
  const response = { fields: [{ name: 'content-type', value: 'application/javascript' }],
    get status() { trace.push('status');
      if (validating && fault === 'validator_exception') throw privateError;
      return ['http_status', 'abort'].includes(fault) ? 403 : 200; },
    get body() { trace.push('body');
      if (fault === 'http_status' && validating) assert.fail('A false status evaluated the later body operand.');
      return fault === 'hash' ? Buffer.from('SYNTHETIC_PRIVATE_WRONG') : body; },
  };
  const journal = {
    async run(_claim, invoke, validate) {
      trace.push('prepare_ack');
      const result = await invoke();
      trace.push('validate'); validating = true;
      let matched = false;
      try { matched = validate(result) === true; } catch (error) { assert.equal(error, privateError); }
      validating = false;
      if (fault === 'after_validation_stop') scope.close('main_navigated');
      if (fault === 'after_validation_detach') { detached = true; scope.close('bound_detached'); }
      if (['after_validation_document', 'or_alternative', 'original_exception'].includes(fault)) changed = true;
      if (fault === 'case_after_validation') now = 40001;
      trace.push('observed_ack');
      if (!matched || fault === 'ack_failure') throw new Error('Fixed synthetic journal failure.');
      return result;
    },
    evidence: () => ({}), close: async () => { trace.push('journal_close'); },
  };
  class Relay {
    async send(_target, _method, _role, _headers, _body, _deadline, guard) {
      trace.push('relay_before_guard'); if (!guard()) throw privateError;
      trace.push('possible_send'); trace.push('relay_after_guard'); if (!guard()) throw privateError;
      stage = 'response';
      if (fault === 'window_after_response') now = 20000;
      return response;
    }
    async close() { trace.push('relay_close'); return true; }
  }
  const overrides = { './hosted-risk-policy.cjs': policies, './hosted-risk-relay.cjs': { HostedRiskRelay: Relay },
    'node:crypto': hash, 'fixture:performance': performance };
  const replacements = reference ? [
    ['  #original(', '  handle(route', referenceOriginal],
    ['  async #handle(', '  async drainSent(', referenceHandle],
  ] : [];
  const { HostedRiskObserver } = compiled('hosted-risk-observer.cjs', replacements, overrides);
  const playwright = { request: { newContext: async () => { trace.push('context'); return {
    get: async () => { trace.push('get'); stage = 'response'; return {
      status: () => response.status, body: async () => response.body,
      headers: () => { trace.push('headers'); return { 'content-type': 'application/javascript' }; },
      dispose: async () => { trace.push('response_dispose'); },
    }; }, dispose: async () => { trace.push('context_dispose'); },
  }; } } };
  const observer = new HostedRiskObserver(instrument ? scope : parent, journal, playwright, fixture, 40000, instrument);
  observer.start('http://127.0.0.1:1236');
  const frame = { url: () => { trace.push('frame_url');
    if (fault === 'original_exception' && changed) throw privateError;
    return 'SYNTHETIC_PRIVATE_DOCUMENT'; }, isDetached: () => { trace.push('detached'); return detached; },
    evaluate: async () => { trace.push('proof'); return { id: 'airwallex-fraud-api', orderSession: 'synthetic',
      tenant: fault === 'or_alternative' ? 'pa' : null,
      project: fault === 'or_alternative' ? 'element-js' : null, disableTracking: null }; } };
  const request = { frame: () => { trace.push('frame'); return frame; },
    url: () => { trace.push('url'); return fixture.origin + '/fingerprint.js?sessionId=synthetic&hasScriptId=false&disable3rdTracking=false'; },
    method: () => { trace.push('method'); return 'GET'; }, resourceType: () => { trace.push('type'); return 'script'; } };
  const route = { request: () => { trace.push('request'); return request; },
    fulfill: async () => { trace.push('fulfill'); if (fault === 'fulfill') throw privateError; },
    abort: async () => { trace.push('abort'); if (fault === 'abort') throw privateError; } };
  const context = { cookies: async () => { trace.push('cookies'); return []; } };
  const result = await observer.handle(route, context, fault === 'or_alternative' ? frame : null, frame,
    () => { trace.push('closing'); return fault === 'pre_send_closing'; },
    () => { trace.push('admission'); return false; });
  const beforeClose = observer.evidence();
  scope.close('teardown');
  const clean = await observer.close();
  const afterClose = observer.evidence();
  const legacy = Object.fromEntries(Object.entries(afterClose).filter(([key]) => !key.startsWith('security_loader_')));
  return { trace, result, clean, legacy, beforeClose, afterClose, stop: scope.firstStop() };
}

for (const fault of ['matched', 'http_status', 'hash', 'render_after_response', 'window_after_response',
  'validator_exception', 'after_validation_stop', 'after_validation_detach', 'after_validation_document',
  'case_after_validation', 'original_exception', 'or_alternative', 'pre_send_closing', 'ack_failure', 'fulfill', 'abort']) {
  test('instrument loader retains immutable handler call/ACK/send/disposal order: ' + fault, async () => {
    const before = await observerTrace(true, fault), after = await observerTrace(false, fault);
    assert.deepEqual(after.trace, before.trace);
    assert.equal(after.result, before.result); assert.equal(after.clean, before.clean);
    assert.deepEqual(after.legacy, before.legacy);
    const evidence = after.beforeClose;
    const gates = { http_status: 'http_status', hash: 'body_hash', render_after_response: 'render_unavailable',
      window_after_response: 'scope_window_not_positive', validator_exception: 'validator_exception',
      after_validation_stop: 'scope_window_not_positive', after_validation_detach: 'frame_detached',
      after_validation_document: 'bound_source_mismatch', case_after_validation: 'case_bound_failed',
      original_exception: 'original_probe_exception', pre_send_closing: 'caller_closing',
      ack_failure: 'operation_failed', fulfill: 'operation_failed', abort: 'http_status' };
    if (Object.hasOwn(gates, fault)) assert.equal(evidence.security_loader_first_failure_gate, gates[fault]);
    else assert.equal(Object.hasOwn(evidence, 'security_loader_first_failure_gate'), false);
    if (['matched', 'or_alternative'].includes(fault)) assert.equal(evidence.security_loader_validation, 'matched');
    if (fault.startsWith('after_validation_') || fault === 'ack_failure') assert.equal(evidence.security_loader_validation, 'matched');
    if (fault === 'validator_exception') assert.equal(evidence.security_loader_validation, 'exception');
    if (fault === 'window_after_response') {
      assert.equal(evidence.security_loader_scope_stop_at_failure, 'release_loader_window_elapsed');
      assert.equal(after.stop, 'release_loader_window_elapsed');
    }
    if (fault === 'after_validation_detach') assert.equal(evidence.security_loader_scope_stop_at_failure, 'bound_detached');
    if (fault === 'http_status') {
      assert.equal(evidence.security_loader_scope_stop_at_failure, 'not_observed');
      assert.equal(after.stop, 'teardown');
      assert.equal(after.afterClose.security_loader_scope_stop_at_failure, 'not_observed');
    }
    assert.doesNotMatch(JSON.stringify({ ...evidence, ...after.afterClose }), /SYNTHETIC_PRIVATE/u);
  });
}

const referenceDocument = String.raw`      if (instrumentSecurity && request.resourceType() === 'document') {
        let frame;
        try { frame = request.frame(); } catch { /* Unavailable navigation is withheld. */ }
        const main = mainPage?.mainFrame();
        if (!frame || (frame === main && instrumentMainDocumentSeen) || frame === boundFrame) {
          sourceGeneration++; security.close(); pendingLoader.close();
          counts.blocked_requests++; await route.abort('blockedbyclient'); return;
        }
        if (frame === main) instrumentMainDocumentSeen = true;
        else if (request.url() === initial) {
          counts.blocked_requests++; await route.abort('blockedbyclient'); return;
        }
      }
`;

const referenceEvents = String.raw`      let mainNavigations = 0;
      const frameNavigations = new WeakMap();
      page.on('framenavigated', frame => {
        if (frame === page.mainFrame() && ++mainNavigations > 1) {
          sourceGeneration++; security.close(); pendingLoader.close();
        } else if (frame === boundFrame && (frameNavigations.get(frame) ?? 0) > 0) {
          sourceGeneration++; security.close(); pendingLoader.close();
        }
        if (frame === page.mainFrame() || frame === boundFrame) {
          frameNavigations.set(frame, (frameNavigations.get(frame) ?? 0) + 1);
        }
      });
      page.on('framedetached', frame => { if (frame === boundFrame) {
        sourceGeneration++; security.close(); pendingLoader.close();
      } });
`;

async function documentTrace(reference, role, seen) {
  const trace = [], counts = { blocked_requests: 0 }, scope = new InstrumentSecurityScope({}, 40000, () => 0);
  const main = {}, boundFrame = {}, frame = role === 'missing' ? undefined : role === 'main' ? main
    : role === 'bound' ? boundFrame : {};
  const request = { resourceType: () => { trace.push('type'); return 'document'; },
    frame: () => { trace.push('frame'); return frame; }, url: () => { trace.push('url'); return 'SYNTHETIC_PRIVATE_OTHER'; } };
  const mainPage = { mainFrame: () => { trace.push('main'); return main; } };
  const security = { close: reason => { trace.push('close'); scope.close(reason); } };
  const pendingLoader = { close: () => trace.push('pending_close') }, route = { abort: async () => trace.push('abort') };
  const source = fs.readFileSync(path.join(__dirname, 'hosted-browser.cjs'), 'utf8');
  const start = source.indexOf("      if (instrumentSecurity && request.resourceType() === 'document') {");
  const end = source.indexOf('      if (observeParentCountry', start);
  assert.ok(start > 0 && end > start);
  const body = reference ? referenceDocument : source.slice(start, end);
  const run = new Function('request', 'mainPage', 'boundFrame', 'security', 'pendingLoader', 'route', 'counts', 'seen',
    `return (async () => { const instrumentSecurity = true, initial = 'SYNTHETIC_PRIVATE_INITIAL';
      let instrumentMainDocumentSeen = seen, sourceGeneration = 0;
      try { ${body} } finally { counts.generation = sourceGeneration; counts.seen = instrumentMainDocumentSeen; }
    })();`);
  await run(request, mainPage, boundFrame, security, pendingLoader, route, counts, seen);
  return { trace, counts, stop: scope.firstStop() };
}
for (const [role, seen, expected] of [['missing', false, 'document_frame_unavailable'],
  ['main', false, 'not_observed'], ['main', true, 'main_document_request'],
  ['bound', false, 'bound_document_request'], ['other', false, 'not_observed']]) {
  test('document-request stop uses only the original evaluated branch: ' + role + '/' + seen, async () => {
    const before = await documentTrace(true, role, seen), after = await documentTrace(false, role, seen);
    assert.deepEqual(after.trace, before.trace); assert.deepEqual(after.counts, before.counts);
    assert.equal(after.stop, expected);
  });
}

function eventTrace(reference, actions) {
  const trace = [], handlers = {}, main = {}, boundFrame = {};
  const scope = new InstrumentSecurityScope({}, 40000, () => 0);
  const page = { mainFrame: () => { trace.push('main'); return main; }, on: (event, callback) => { handlers[event] = callback; } };
  const security = { close: reason => { trace.push('close'); scope.close(reason); } };
  const pendingLoader = { close: () => trace.push('pending_close') };
  const source = fs.readFileSync(path.join(__dirname, 'hosted-browser.cjs'), 'utf8');
  const start = source.indexOf('      let mainNavigations = 0;'), end = source.indexOf('    page.setDefaultTimeout', start);
  const body = reference ? referenceEvents : source.slice(start, end).slice(0, source.slice(start, end).lastIndexOf('    }'));
  const run = new Function('page', 'boundFrame', 'security', 'pendingLoader', 'documents', `let sourceGeneration = 0;
    ${body} return () => sourceGeneration;`);
  const generation = run(page, boundFrame, security, pendingLoader, { navigated: () => {} });
  for (const action of actions) handlers[action === 'detach' ? 'framedetached' : 'framenavigated'](action === 'main' ? main : boundFrame);
  const first = scope.firstStop(); scope.close('teardown');
  return { trace, generation: generation(), first, final: scope.firstStop() };
}
for (const [actions, reason] of [[['main', 'main'], 'main_navigated'], [['bound', 'bound'], 'bound_navigated'],
  [['detach'], 'bound_detached'], [['bound', 'bound', 'main', 'main', 'detach'], 'bound_navigated']]) {
  test('navigation/detach first-stop preserves existing event getter/call order: ' + actions.join('/'), () => {
    const before = eventTrace(true, actions), after = eventTrace(false, actions);
    assert.deepEqual(after.trace, before.trace); assert.equal(after.generation, before.generation);
    assert.equal(after.first, reason); assert.equal(after.final, reason);
  });
}

for (const fault of ['matched', 'http_status', 'hash', 'fulfill']) {
  test('legacy observer evidence bytes and all pre-existing calls remain identical: ' + fault, async () => {
    const before = await observerTrace(true, fault, false), after = await observerTrace(false, fault, false);
    assert.deepEqual(after.trace, before.trace);
    assert.equal(JSON.stringify(after.beforeClose), JSON.stringify(before.beforeClose));
    assert.equal(JSON.stringify(after.afterClose), JSON.stringify(before.afterClose));
    assert.equal(after.clean, before.clean); assert.equal(after.result, before.result);
    assert.equal(Object.keys(after.afterClose).some(key => key.startsWith('security_')), false);
  });
}

const { BoundDocumentObservations } = require('./hosted-browser.cjs');
const flushDocuments = () => new Promise(resolve => setImmediate(resolve));
function documentFixture(deadline = 1000) {
  let generation = 0, now = 0, captures = 0, probes = 0, disposals = 0;
  let url = 'https://fixture.invalid/#/elements/dropin?options=SYNTHETIC_PRIVATE';
  let capture, probe, disposal;
  const handle = { asElement() { return this; }, dispose: async () => { disposals++; if (disposal) return disposal; } };
  const frame = { url: () => url, evaluateHandle: () => { captures++; return capture ?? handle; },
    evaluate: () => { probes++; return probe ?? true; } };
  const observer = new BoundDocumentObservations(deadline, () => generation, () => now);
  observer.bind(frame, url, generation);
  return { observer, frame, handle, first: () => observer.navigated(frame, generation, false),
    stop: () => { generation++; observer.navigated(frame, generation, true); },
    generation: () => { generation++; }, time: value => { now = value; }, url: value => { url = value; },
    capture: value => { capture = value; }, probe: value => { probe = value; }, disposal: value => { disposal = value; },
    counts: () => ({ captures, probes, disposals }) };
}

test('passive document proof is one-shot, private and independent of the unchanged closing guard', async () => {
  const f = documentFixture(); f.first(); await flushDocuments(); f.stop(); await flushDocuments();
  assert.deepEqual(f.observer.evidence(), {
    security_bound_navigation_original_relation: 'exact_repeat', security_bound_navigation_previous_relation: 'exact_repeat',
    security_bound_document_reference: 'captured_before_stop', security_bound_document_continuity: 'same',
  });
  f.observer.close(); await flushDocuments(); f.observer.finish(); f.observer.finish();
  assert.equal(f.observer.evidence().security_bound_document_cleanup, 'complete');
  assert.deepEqual(f.counts(), { captures: 1, probes: 1, disposals: 1 });
  assert.doesNotMatch(JSON.stringify(f.observer.evidence()), /SYNTHETIC_PRIVATE/u);
  assert.doesNotMatch(util.inspect(f.observer), /fixture\.invalid|SYNTHETIC_PRIVATE/u);
  assert.throws(() => JSON.stringify(f.observer));
});

for (const value of [false, 'true', null, 1]) {
  test('native continuity requires a boolean result: ' + String(value), async () => {
    const f = documentFixture(); f.probe(Promise.resolve(value)); f.first(); await flushDocuments();
    f.stop(); await flushDocuments(); await f.observer.finish();
    assert.equal(f.observer.evidence().security_bound_document_continuity, value === false ? 'different' : 'unavailable');
    assert.equal(f.counts().disposals, 1);
  });
}

test('first navigation relationships remain fixed through later changes and detachment generations', async () => {
  const f = documentFixture(); f.first(); await flushDocuments();
  f.url('https://fixture.invalid/#/elements/dropin?options=OTHER_PRIVATE'); f.stop(); await flushDocuments();
  const first = f.observer.evidence();
  assert.equal(first.security_bound_navigation_original_relation, 'fragment_only_change');
  assert.equal(first.security_bound_navigation_previous_relation, 'fragment_only_change');
  f.url('https://other.invalid/PRIVATE'); f.stop(); f.generation();
  assert.deepEqual(f.observer.evidence(), first); await f.observer.finish();
});

test('previous and original relationships differ without inventing an original Document', async () => {
  const f = documentFixture(); f.url('https://fixture.invalid/#/different'); f.first(); f.stop();
  assert.equal(f.observer.evidence().security_bound_navigation_original_relation, 'fragment_only_change');
  assert.equal(f.observer.evidence().security_bound_navigation_previous_relation, 'exact_repeat');
  assert.equal(f.observer.evidence().security_bound_document_continuity, 'not_captured');
  assert.equal(f.counts().captures, 0); await f.observer.finish();
});

for (const value of ['https://fixture.invalid/next', 'about:blank', 'https://fixture.invalid/\nPRIVATE',
  'bad PRIVATE', null, 'https://fixture.invalid/' + 'PRIVATE'.repeat(4000)]) {
  test('passive URL relationship is bounded and exposes no components: ' + (value === null ? 'null' : value.length), async () => {
    const f = documentFixture(); f.first(); await flushDocuments(); f.url(value); f.stop(); await flushDocuments();
    const expected = typeof value === 'string' && (value.endsWith('/next') || value === 'about:blank')
      ? 'nonfragment_change' : 'unavailable';
    assert.equal(f.observer.evidence().security_bound_navigation_original_relation, expected);
    assert.doesNotMatch(JSON.stringify(f.observer.evidence()), /PRIVATE|fixture\.invalid/u);
    await f.observer.finish();
  });
}

test('cached URL and native evaluation failures inspect no Error properties', async () => {
  const f = documentFixture(), privateError = new Error();
  for (const field of ['message', 'name', 'stack']) Object.defineProperty(privateError, field, {
    get() { assert.fail('Private Error property read.'); },
  });
  f.first(); await flushDocuments();
  f.frame.url = () => { throw privateError; };
  f.frame.evaluate = () => { throw privateError; };
  f.stop(); await flushDocuments(); await f.observer.finish();
  assert.equal(f.observer.evidence().security_bound_navigation_original_relation, 'unavailable');
  assert.equal(f.observer.evidence().security_bound_document_continuity, 'unavailable');
});

for (const fault of ['stop', 'generation', 'close', 'case_deadline', 'operation_deadline']) {
  test('a late baseline is unusable and disposed once: ' + fault, async () => {
    const f = documentFixture(); let release;
    f.capture(new Promise(resolve => { release = resolve; })); f.first(); await flushDocuments();
    if (fault === 'stop') f.stop();
    if (fault === 'generation') f.generation();
    if (fault === 'close') f.observer.close();
    if (fault === 'case_deadline') f.time(1001);
    if (fault === 'operation_deadline') f.time(501);
    release(f.handle); await flushDocuments();
    if (fault !== 'stop' && fault !== 'close') f.stop();
    await f.observer.finish();
    assert.notEqual(f.observer.evidence().security_bound_document_continuity, 'same');
    assert.equal(f.counts().probes, 0); assert.equal(f.counts().disposals, 1);
  });
}

for (const fault of ['generation', 'close', 'deadline']) {
  test('a late native comparison cannot upgrade unknown continuity: ' + fault, async () => {
    const f = documentFixture(); let release;
    f.probe(new Promise(resolve => { release = resolve; })); f.first(); await flushDocuments();
    f.stop(); await flushDocuments();
    if (fault === 'generation') f.generation();
    if (fault === 'close') f.observer.close();
    if (fault === 'deadline') f.time(501);
    release(true); await flushDocuments(); await f.observer.finish();
    assert.equal(f.observer.evidence().security_bound_document_continuity, 'unavailable');
    assert.equal(f.counts().disposals, 1);
  });
}

for (const fault of ['capture', 'comparison', 'disposal']) {
  test('no-progress native work remains bounded with once-only late cleanup: ' + fault, async () => {
    const f = documentFixture(15); let release;
    const pending = new Promise(resolve => { release = resolve; });
    if (fault === 'capture') f.capture(pending);
    if (fault === 'comparison') f.probe(pending);
    if (fault === 'disposal') f.disposal(pending);
    f.first(); await flushDocuments(); f.stop(); await flushDocuments();
    const start = performance.now(); await f.observer.finish();
    assert.ok(performance.now() - start < 500);
    assert.equal(f.observer.evidence().security_bound_document_cleanup, 'unavailable');
    const before = f.observer.evidence(); release(fault === 'capture' ? f.handle : true);
    await flushDocuments(); await f.observer.finish();
    assert.deepEqual(f.observer.evidence(), before); assert.equal(f.counts().disposals, 1);
  });
}

test('replacement frames, missing handles and pre-invocation shutdown cannot establish a reference', async () => {
  for (const fault of ['replacement', 'missing', 'closed_before_call', 'expired_before_call']) {
    const f = documentFixture();
    if (fault === 'replacement') {
      const other = { url: () => assert.fail('Unbound URL was observed.') };
      f.observer.bind(other, 'https://other.invalid/', 0); f.observer.navigated(other, 0, false);
    }
    if (fault === 'missing') f.capture(Promise.resolve(undefined));
    f.first();
    if (fault === 'closed_before_call') f.observer.close();
    if (fault === 'expired_before_call') f.time(1001);
    await flushDocuments(); f.stop(); await flushDocuments(); await f.observer.finish();
    if (fault !== 'replacement') assert.notEqual(f.observer.evidence().security_bound_document_continuity, 'same');
    if (fault === 'closed_before_call' || fault === 'expired_before_call') assert.equal(f.counts().captures, 0);
    if (fault === 'replacement') assert.equal(f.counts().captures, 1);
  }
});

for (const role of ['capture', 'comparison']) {
  test('a no-progress native read expires without relying on teardown: ' + role, async () => {
    const f = documentFixture(10); let release;
    const pending = new Promise(resolve => { release = resolve; });
    if (role === 'capture') f.capture(pending); else f.probe(pending);
    f.first(); await flushDocuments();
    if (role === 'comparison') f.stop();
    await new Promise(resolve => setTimeout(resolve, 25));
    if (role === 'capture') f.stop();
    assert.notEqual(f.observer.evidence().security_bound_document_continuity, 'same');
    f.observer.finish(); const before = f.observer.evidence();
    release(role === 'capture' ? f.handle : true); await flushDocuments();
    assert.deepEqual(f.observer.evidence(), before); assert.equal(f.counts().disposals, 1);
  });
}

test('a native null/non-Node handle is never adopted as the original Document', async () => {
  const f = documentFixture(); let disposed = 0;
  f.capture(Promise.resolve({ asElement: () => null, dispose: async () => { disposed++; } }));
  f.first(); await flushDocuments(); f.stop(); await flushDocuments(); f.observer.finish();
  assert.equal(f.observer.evidence().security_bound_document_reference, 'unavailable');
  assert.equal(f.observer.evidence().security_bound_document_continuity, 'not_captured');
  assert.equal(f.counts().probes, 0); assert.equal(disposed, 1);
});

test('baseline invocation sends only public origin/path, never private fragment or options', async () => {
  const f = documentFixture();
  f.frame.evaluateHandle = (_expression, expected) => {
    assert.equal(expected, 'https://fixture.invalid/');
    assert.doesNotMatch(expected, /PRIVATE|options|#/u); return f.handle;
  };
  f.first(); await flushDocuments(); f.stop(); await flushDocuments(); f.observer.finish();
  assert.equal(f.observer.evidence().security_bound_document_continuity, 'same');
});

test('a changed non-fragment location receives no original Document argument', async () => {
  const f = documentFixture(); f.first(); await flushDocuments(); f.url('https://other.invalid/PRIVATE');
  f.stop(); await flushDocuments(); f.observer.finish();
  assert.equal(f.observer.evidence().security_bound_navigation_original_relation, 'nonfragment_change');
  assert.equal(f.observer.evidence().security_bound_document_continuity, 'unavailable');
  assert.equal(f.counts().probes, 0); assert.equal(f.counts().disposals, 1);
});

for (const fault of ['generation', 'close', 'deadline', 'location']) {
  test('a stale native comparison is withheld before invocation: ' + fault, async () => {
    const f = documentFixture(); f.first(); await flushDocuments(); f.stop();
    if (fault === 'generation') f.generation();
    if (fault === 'close') f.observer.close();
    if (fault === 'deadline') f.time(501);
    if (fault === 'location') f.url('https://other.invalid/PRIVATE');
    await flushDocuments(); f.observer.finish();
    assert.equal(f.counts().probes, 0);
    assert.equal(f.observer.evidence().security_bound_document_continuity, 'unavailable');
    assert.equal(f.counts().disposals, 1);
  });
}

test('a synchronous repeat before baseline invocation earns no native capture', async () => {
  const f = documentFixture(); f.first(); f.stop(); await flushDocuments(); f.observer.finish();
  assert.equal(f.counts().captures, 0); assert.equal(f.counts().probes, 0);
  assert.equal(f.observer.evidence().security_bound_document_reference, 'not_established');
  assert.equal(f.observer.evidence().security_bound_document_continuity, 'not_captured');
});

const { boundDocumentWorkerFixture, sanitizeBoundDocumentFixture } = require('./hosted-smoke.cjs');
async function wiredDocumentScenario(kind = 'same', source) {
  const handlers = {}, trace = [], main = {}, url = 'https://fixture.invalid/document#SYNTHETIC_PRIVATE_OPTIONS';
  let captures = 0, comparisons = 0, disposals = 0, release;
  const pending = new Promise(resolve => { release = resolve; });
  const privateError = new Error();
  for (const key of ['message', 'stack', 'name']) Object.defineProperty(privateError, key, {
    get() { assert.fail('A native failure inspected private Error data.'); },
  });
  const handle = { asElement() { return this; }, dispose: async () => { disposals++; trace.push('native_dispose'); } };
  const frame = { url: () => url, evaluateHandle: async () => {
    captures++; trace.push('native_capture'); return kind.startsWith('late') ? pending : handle;
  }, evaluate: async (_expression, prior) => {
    comparisons++; trace.push('native_compare'); assert.equal(prior, handle);
    if (kind === 'unavailable') throw privateError;
    return kind !== 'replacement';
  } };
  const page = { on: (event, callback) => { handlers[event] = callback; }, mainFrame: () => main };
  const fixture = boundDocumentWorkerFixture(page, 1000, { trace, source, clock: () => 0,
    context: { close: async () => { trace.push('context_close'); } } });
  handlers.framenavigated(main);
  fixture.bind({ frame: () => frame }, url);
  handlers.framenavigated(frame);
  await flushDocuments(); await flushDocuments();
  if (kind === 'generation') handlers.framenavigated(main);
  handlers.framenavigated(frame);
  await flushDocuments(); await flushDocuments();
  if (kind === 'late') { release(handle); await flushDocuments(); await flushDocuments(); }
  await fixture.finish();
  const outputs = [fixture.output(), fixture.output(true)].map(value => sanitizeBoundDocumentFixture({
    ...value, raw_url: url, raw_document: 'SYNTHETIC_PRIVATE_DOM',
  }));
  if (kind === 'late_cleanup') {
    release(handle); await flushDocuments(); await flushDocuments();
    assert.deepEqual(fixture.output().security_bound_document_cleanup, outputs[0].security_bound_document_cleanup);
  }
  assert.doesNotMatch(JSON.stringify(outputs), /SYNTHETIC_PRIVATE/u);
  return { outputs, trace, captures, comparisons, disposals, generation: fixture.generation() };
}

function assertWiredSame(result) {
  assert.equal(result.captures, 1); assert.equal(result.comparisons, 1); assert.equal(result.disposals, 1);
  assert.equal(result.generation, 1);
  for (const value of result.outputs) {
    assert.equal(value.security_source_first_stop, 'bound_navigated');
    assert.equal(value.security_bound_navigation_original_relation, 'exact_repeat');
    assert.equal(value.security_bound_document_reference, 'captured_before_stop');
    assert.equal(value.security_bound_document_continuity, 'same');
    assert.equal(value.security_bound_document_cleanup, 'complete');
    assert.equal(value.financial_permission, false);
  }
  assert.deepEqual(result.trace.slice(result.trace.indexOf('security_close:bound_navigated'),
    result.trace.indexOf('native_compare') + 1), ['security_close:bound_navigated', 'pending_close', 'native_compare']);
  assert.ok(result.trace.indexOf('native_capture') > result.trace.indexOf('render_delivered'));
  assert.ok(result.trace.indexOf('directory_removed') > result.trace.indexOf('context_close'));
  assert.equal(result.outputs[0].result, 'blocked'); assert.equal(result.outputs[1].result, 'fail');
}

test('extracted production bind, handlers, teardown and both outputs reach the real PHP sanitizer', async () => {
  assertWiredSame(await wiredDocumentScenario());
});
for (const kind of ['replacement', 'unavailable', 'generation', 'late', 'late_cleanup']) {
  test('production Document wiring preserves closed proof and cleanup: ' + kind, async () => {
    const result = await wiredDocumentScenario(kind);
    assert.equal(result.captures, 1); assert.equal(result.disposals, 1);
    assert.equal(result.comparisons, ['generation', 'late', 'late_cleanup'].includes(kind) ? 0 : 1);
    for (const value of result.outputs) {
      assert.equal(value.financial_permission, false);
      assert.equal(value.security_bound_document_continuity, kind === 'replacement' ? 'different'
        : kind === 'unavailable' ? 'unavailable' : 'not_captured');
      assert.equal(value.security_bound_document_cleanup, kind === 'late_cleanup' ? 'unavailable' : 'complete');
    }
  });
}

for (const [name, before, after] of [
  ['bind', 'documents?.bind(boundFrame, renderFrameSource, sourceGeneration);', ''],
  ['failure output', '    ...(documents?.evidence() ?? {}),\n', ''],
  ['success output', '...(documents?.evidence() ?? {}), ...cardChoice.evidence()', '...cardChoice.evidence()'],
  ['finish', '    documents?.finish();', ''],
  ['bind generation', 'documents?.bind(boundFrame, renderFrameSource, sourceGeneration);',
    'documents?.bind(boundFrame, renderFrameSource, sourceGeneration + 1);'],
  ['event generation', 'documents.navigated(frame, sourceGeneration, stoppedBound);',
    'documents.navigated(frame, sourceGeneration + 1, stoppedBound);'],
]) {
  test('production-wiring acceptance detects removal or miswiring: ' + name, async () => {
    const original = fs.readFileSync(path.join(__dirname, 'hosted-browser.cjs'), 'utf8');
    assert.equal(original.split(before).length, 2);
    const result = await wiredDocumentScenario('same', original.replace(before, after));
    assert.throws(() => assertWiredSame(result), assert.AssertionError);
  });
}
