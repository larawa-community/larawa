import assert from 'node:assert/strict';
import test from 'node:test';
import { PendingSendTracker } from '../src/pending-send-tracker.js';

test('correlates a synthetic LID callback with the original client message', () => {
  const tracker = new PendingSendTracker();
  tracker.track(42, { text: 'Synthetic test message' }, '12025550199@c.us');

  assert.equal(tracker.match({ body: 'Synthetic test message', to: '999000000000001@lid' }), 42);
  assert.equal(tracker.match({ body: 'Synthetic test message', to: '999000000000001@lid' }), null);
});

test('prefers an exact synthetic recipient match for identical messages', () => {
  const tracker = new PendingSendTracker();
  tracker.track(10, { text: 'same' }, 'one@example.test');
  tracker.track(11, { text: 'same' }, 'two@example.test');

  assert.equal(tracker.match({ body: 'same', to: 'two@example.test' }), 11);
  assert.equal(tracker.match({ body: 'same', to: 'one@example.test' }), 10);
});
