'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
  parseInstrumentJson: parse,
  integer,
  zero,
  object,
  keys,
  identity,
  uuid
} = require('./instrument-json.cjs');

test('decimal authorization preserves original tokens and never accepts rounding/underflow as zero', () => {
  for (const raw of ['0', '0.00', '"0"', '"0.000"']) assert.equal(zero(parse(Buffer.from(raw))), true);
  for (const raw of ['1e-9999', '0e3', '-0', '0.000000000000000000000000001', '9007199254740993', '"0e0"']) {
    assert.equal(zero(parse(Buffer.from(raw))), false);
  }
});
test('native numbers cannot become string identities or UUIDs, and integer grammar is explicit', () => {
  const value = parse(
    Buffer.from('{"id":123,"count":1,"exponent":1e0,"uuid":"00000000-0000-4000-8000-000000000000"}')
  );
  assert.equal(identity(value.id), false);
  assert.equal(integer(value.count, 0, 10), true);
  assert.equal(integer(value.exponent), false);
  assert.equal(uuid(value.uuid), true);
  assert.equal(object(value.id), false);
  assert.equal(keys(value, ['id', 'count', 'exponent', 'uuid'], ['id']), true);
  assert.equal(keys(value, ['id']), false);
  assert.equal(keys(value, ['id', 'count', 'exponent', 'uuid'], ['missing']), false);
});
test('ambiguous aliases, nested duplicate keys, invalid UTF-8, depth and bytes are rejected', () => {
  for (const raw of [
    '{"id":"one","\\u0069d":"two"}',
    '{"outer":{"id":"one","id":"two"}}',
    '['.repeat(34) + '0' + ']'.repeat(34)
  ])
    assert.throws(() => parse(Buffer.from(raw)));
  assert.throws(() => parse(Buffer.from([0xff])));
  assert.throws(() => parse(Buffer.alloc(65537, 32)));
});
