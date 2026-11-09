'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const https = require('node:https');
const { InstrumentJournal, hash } = require('./instrument-journal.cjs');
const { InstrumentRelay } = require('./instrument-relay.cjs');

function claim(origin = 'https://checkout.sandbox.airwallex.com') {
  return {
    role: 'consent_create',
    ordinal: 0,
    method: 'POST',
    url: origin + '/synthetic',
    body: Buffer.from('{"request_id":"00000000-0000-4000-8000-000000000000"}'),
    headers: { 'content-type': 'application/json' },
    scope: hash('synthetic scope'),
    requestId: '00000000-0000-4000-8000-000000000000',
    responseLimit: 65536
  };
}

function journalFixture() {
  const directory = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'awx-instrument-journal-test-')));
  fs.chmodSync(directory, 0o700);
  return { directory, close: () => fs.rmSync(directory, { recursive: true, force: true }) };
}

test('exclusive possible-send and returned identity records precede permission and omit private wire', () => {
  const f = journalFixture();
  try {
    const journal = new InstrumentJournal(f.directory, { context: hash('context') });
    const request = claim();
    const action = journal.prepare(request);
    const records = fs
      .readdirSync(f.directory)
      .map((name) => JSON.parse(fs.readFileSync(path.join(f.directory, name))));
    assert.equal(records.length, 2);
    assert.equal(records[1].phase, 'possible_send');
    assert.equal(records[1].action, action);
    assert.equal(records[1].body_sha256, hash(request.body));
    assert.equal(records[1].source_request_id, request.requestId);
    assert.equal(records[1].target_sha256, hash(request.url));
    assert.equal(records[1].headers_sha256, hash(JSON.stringify(request.headers)));
    const raw = fs.readFileSync(path.join(f.directory, '0001.json'), 'utf8');
    assert.equal(raw.includes(request.url), false);
    assert.equal(raw.includes('content-type'), false);
    assert.equal(fs.statSync(path.join(f.directory, '0001.json')).mode & 0o777, 0o600);
    assert.equal(journal.evidence().unresolved_actions, 1);
    journal.observed(
      request,
      action,
      { status: 200, body: Buffer.from('{"id":"synthetic-consent"}') },
      { consent_id: 'synthetic-consent' }
    );
    assert.equal(
      JSON.parse(fs.readFileSync(path.join(f.directory, '0002.json'))).identities.consent_id,
      'synthetic-consent'
    );
    assert.equal(journal.evidence().unresolved_actions, 0);
    assert.equal(journal.close(), true);
    assert.throws(() => journal.prepare({ ...request, ordinal: 1 }));
  } finally {
    f.close();
  }
});

for (const failedSync of [1, 2, 3, 4, 5, 6])
  test('fsync failure ' + failedSync + ' never returns send/release permission', () => {
    const f = journalFixture();
    let calls = 0;
    try {
      let journal;
      assert.throws(
        () => {
          journal = new InstrumentJournal(f.directory, {}, (fd) => {
            if (++calls === failedSync) throw new Error('SYNTHETIC_PRIVATE_ERROR');
            fs.fsyncSync(fd);
          });
          const request = claim(),
            action = journal.prepare(request);
          journal.observed(request, action, { status: 200, body: Buffer.from('{}') });
        },
        (error) => !String(error).includes('SYNTHETIC_PRIVATE_ERROR')
      );
      assert.equal(calls, failedSync);
      if (journal) {
        assert.equal(journal.healthy(), false);
        assert.throws(() => journal.prepare(claim()));
      }
    } finally {
      f.close();
    }
  });

test('financial ordinals cannot repeat a role, wire, result or changed body', () => {
  for (const mutation of ['role', 'body', 'target', 'result']) {
    const f = journalFixture();
    try {
      const journal = new InstrumentJournal(f.directory, {}),
        request = claim(),
        action = journal.prepare(request);
      if (mutation === 'role') assert.throws(() => journal.prepare({ ...request, ordinal: 1 }));
      if (mutation === 'body')
        assert.throws(() =>
          journal.observed({ ...request, body: Buffer.from('changed') }, action, {
            status: 200,
            body: Buffer.from('{}')
          })
        );
      if (mutation === 'target')
        assert.throws(() =>
          journal.observed({ ...request, url: request.url + '/changed' }, action, {
            status: 200,
            body: Buffer.from('{}')
          })
        );
      if (mutation === 'result') {
        journal.observed(request, action, { status: 200, body: Buffer.from('{}') });
        assert.throws(() => journal.observed(request, action, { status: 200, body: Buffer.from('{}') }));
      }
      assert.equal(journal.close(), false);
    } finally {
      f.close();
    }
  }
});

test('uncertain sends remain inventoried and journals cannot reuse existing or public directories', () => {
  const f = journalFixture();
  try {
    const journal = new InstrumentJournal(f.directory, {});
    journal.prepare(claim());
    journal.fail();
    assert.equal(journal.close(), false);
    assert.equal(journal.evidence().possible_financial_send, true);
    assert.equal(journal.evidence().unresolved_actions, 1);
    assert.throws(() => JSON.stringify(journal));
    assert.throws(() => new InstrumentJournal(f.directory, {}));
    fs.chmodSync(f.directory, 0o755);
    assert.throws(() => new InstrumentJournal(f.directory, {}));
  } finally {
    f.close();
  }
});

async function serverFixture(handler) {
  const cert = fs.readFileSync(
    path.join(__dirname, '../../tests/fixtures/hosted-risk/localhost-fixture.crt')
  );
  const key = fs.readFileSync(path.join(__dirname, '../../tests/fixtures/hosted-risk/localhost-fixture.key'));
  const server = https.createServer({ cert, key }, handler),
    sockets = new Set();
  server.on('connection', (socket) => {
    sockets.add(socket);
    socket.on('close', () => sockets.delete(socket));
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  return {
    origin: 'https://127.0.0.1:' + server.address().port,
    ca: cert,
    close: async () => {
      for (const socket of sockets) socket.destroy();
      await new Promise((resolve) => server.close(resolve));
    }
  };
}

test('native relay preserves exact target/body and releases only a bounded identity response', async () => {
  let received;
  const f = await serverFixture((request, response) => {
    const chunks = [];
    request.on('data', (chunk) => chunks.push(chunk));
    request.on('end', () => {
      received = {
        body: Buffer.concat(chunks),
        target: request.url,
        encoding: request.headers['accept-encoding']
      };
      response.writeHead(200, { 'content-type': 'application/json' }).end('{"id":"synthetic"}');
    });
  });
  const relay = new InstrumentRelay(f);
  try {
    const request = claim(f.origin),
      result = await relay.send(request, performance.now() + 2000, () => true);
    assert.equal(result.body.toString(), '{"id":"synthetic"}');
    assert.equal(received.target, '/synthetic');
    assert.deepEqual(received.body, request.body);
    assert.equal(received.encoding, 'identity');
    assert.equal(await relay.close(), true);
    await assert.rejects(relay.send(request, performance.now() + 2000, () => true));
  } finally {
    await relay.close();
    await f.close();
  }
});

for (const failure of [
  'redirect',
  'encoding',
  'declared_size',
  'streamed_size',
  'truncated',
  'expired',
  'scope'
]) {
  test('native relay withholds ' + failure + ' without following or replaying', async () => {
    let hits = 0;
    const f = await serverFixture((_request, response) => {
      hits++;
      if (failure === 'redirect') {
        response.writeHead(302, { location: '/forbidden' }).end();
        return;
      }
      if (failure === 'encoding') {
        response.writeHead(200, { 'content-encoding': 'gzip' }).end('opaque');
        return;
      }
      if (failure === 'declared_size') {
        response.writeHead(200, { 'content-length': '65537' }).end();
        return;
      }
      if (failure === 'streamed_size') {
        response.writeHead(200);
        response.end(Buffer.alloc(65537));
        return;
      }
      if (failure === 'truncated') {
        response.writeHead(200, { 'content-length': '20' });
        response.write('short');
        response.destroy();
        return;
      }
      response.writeHead(200).end('{}');
    });
    const relay = new InstrumentRelay(f);
    try {
      const operation = relay.send(
        claim(f.origin),
        performance.now() + (failure === 'expired' ? -1 : 1000),
        () => failure !== 'scope'
      );
      if (failure === 'redirect') assert.equal((await operation).status, 302);
      else await assert.rejects(operation);
      assert.equal(hits, ['expired', 'scope'].includes(failure) ? 0 : 1);
    } finally {
      await relay.close();
      await f.close();
    }
  });
}

test('production relay rejects literal/private destinations before any network send', async () => {
  const relay = new InstrumentRelay();
  try {
    for (const origin of [
      'https://127.0.0.1',
      'https://localhost',
      'http://example.com',
      'https://example.com:444'
    ]) {
      await assert.rejects(relay.send(claim(origin), performance.now() + 1000, () => true));
    }
  } finally {
    await relay.close();
  }
});

test('native pre-send proof after TLS can withhold all HTTP bytes', async () => {
  let hits = 0;
  const f = await serverFixture((_request, response) => {
    hits++;
    response.writeHead(200).end('{}');
  });
  const relay = new InstrumentRelay(f);
  try {
    await assert.rejects(
      relay.send(
        claim(f.origin),
        performance.now() + 2000,
        () => true,
        async () => {
          throw Error('changed source');
        }
      )
    );
    assert.equal(hits, 0);
  } finally {
    await relay.close();
    await f.close();
  }
});
