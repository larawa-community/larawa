import assert from 'node:assert/strict';
import test from 'node:test';
import { serializedId } from '../src/serialization.js';

test('serializes WhatsApp IDs exposed in supported shapes', () => {
  assert.equal(serializedId('12025550199@c.us'), '12025550199@c.us');
  assert.equal(serializedId({ _serialized: 'legacy@g.us' }), 'legacy@g.us');
  assert.equal(serializedId({ serialized: 'alternate@lid' }), 'alternate@lid');
  assert.equal(serializedId({ user: '12025550199', server: 'c.us' }), '12025550199@c.us');
});

test('reconstructs a message key when _serialized is absent', () => {
  assert.equal(serializedId({
    fromMe: false,
    remote: { user: '999000000000003', server: 'g.us' },
    id: '3EB0123456789',
    participant: { user: '12025550199', server: 'c.us' },
  }), 'false_999000000000003@g.us_3EB0123456789_12025550199@c.us');
});

test('returns null for unknown ID shapes', () => {
  assert.equal(serializedId(undefined), null);
  assert.equal(serializedId({}), null);
});
