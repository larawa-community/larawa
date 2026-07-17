import assert from 'node:assert/strict';
import test from 'node:test';
import { isAmbiguousPuppeteerSendError } from '../src/puppeteer-errors.js';

test('recognizes Puppeteer errors that can happen after WhatsApp accepted a send', () => {
  assert.equal(isAmbiguousPuppeteerSendError(new Error('r: r')), true);
  assert.equal(isAmbiguousPuppeteerSendError(new Error('Execution context was destroyed.')), true);
  assert.equal(isAmbiguousPuppeteerSendError(new Error('Target closed')), true);
  assert.equal(isAmbiguousPuppeteerSendError(new Error('Cannot find context with specified id')), true);
});

test('does not hide definite send failures', () => {
  assert.equal(isAmbiguousPuppeteerSendError(new Error('Recipient is not registered on WhatsApp.')), false);
  assert.equal(isAmbiguousPuppeteerSendError(new Error('Invalid media payload.')), false);
});
