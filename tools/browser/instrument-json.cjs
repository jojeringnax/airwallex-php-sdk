'use strict';

const numberSource = Symbol('original JSON number');
function parseInstrumentJson(body, limit = 65536) {
  if (!Buffer.isBuffer(body) || ![65536, 262144, 1048576].includes(limit) || body.length > limit)
    throw new Error('JSON byte limit exceeded.');
  const raw = new TextDecoder('utf-8', { fatal: true }).decode(body),
    native = JSON.parse(raw);
  const bounded = (value, depth) =>
    depth <= 32 &&
    (value === null ||
      typeof value !== 'object' ||
      Object.values(value).every((child) => bounded(child, depth + 1)));
  if (!bounded(native, 0)) throw new Error('JSON depth limit exceeded.');
  const scopes = [];
  for (let index = 0; index < raw.length; index++) {
    const char = raw[index];
    if (char === '{' || char === '[') scopes.push(char === '{' ? new Set() : null);
    else if (char === '}' || char === ']') scopes.pop();
    else if (char === '"') {
      const expression = /"(?:[^"\\]|\\.)*"/gy;
      expression.lastIndex = index;
      const match = expression.exec(raw);
      if (!match) throw new Error('JSON string is invalid.');
      index = expression.lastIndex - 1;
      if (raw.slice(index + 1).trimStart()[0] === ':') {
        const key = JSON.parse(match[0]),
          scope = scopes.at(-1);
        if (!(scope instanceof Set) || scope.has(key)) throw new Error('JSON keys are ambiguous.');
        scope.add(key);
      }
    }
  }
  return JSON.parse(raw, (_key, value, context) => {
    if (typeof value !== 'number') return value;
    if (!context || typeof context.source !== 'string')
      throw new Error('Exact JSON number support is required.');
    return Object.freeze({ [numberSource]: context.source });
  });
}
function integer(value, minimum = 0, maximum = Number.MAX_SAFE_INTEGER) {
  const raw = value?.[numberSource];
  return (
    typeof raw === 'string' &&
    /^(?:0|[1-9][0-9]*)$/u.test(raw) &&
    Number.isSafeInteger(Number(raw)) &&
    Number(raw) >= minimum &&
    Number(raw) <= maximum
  );
}
function zero(value) {
  const raw = typeof value === 'string' ? value : value?.[numberSource];
  return typeof raw === 'string' && /^0(?:\.0+)?$/u.test(raw);
}
function decimal(value) {
  const raw = typeof value === 'string' ? value : value?.[numberSource];
  return typeof raw === 'string' && raw.length <= 1000 && /^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/u.test(raw);
}
function object(value) {
  return (
    value !== null && typeof value === 'object' && !Array.isArray(value) && value[numberSource] === undefined
  );
}
function keys(value, allowed, required = []) {
  return (
    object(value) &&
    Object.keys(value).every((key) => allowed.includes(key)) &&
    required.every((key) => Object.hasOwn(value, key))
  );
}
function identity(value) {
  return typeof value === 'string' && /^[A-Za-z0-9_-]{1,128}$/u.test(value);
}
function uuid(value) {
  return typeof value === 'string' && /^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/u.test(value);
}

module.exports = { parseInstrumentJson, integer, zero, decimal, object, keys, identity, uuid };
