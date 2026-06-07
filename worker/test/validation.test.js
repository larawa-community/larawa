import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { assertPublicHttpUrl, isPublicIp } from '../src/outbound-url.js';
import { createSessionRegistry } from '../src/registry.js';
import {
  decodedBase64Bytes,
  isHttpUrl,
  isValidChatId,
  isValidRecipient,
  isValidSessionId,
  isValidBase64,
  isValidMimeType,
  mediaResponseError,
  mimeMatchesMessageType,
  normalizeRecipient,
  parsePositiveInteger,
  secureTokenEquals,
  validateSendPayload,
} from '../src/validation.js';

async function temporarySessionDirectory() {
  return fs.mkdtemp(path.join(os.tmpdir(), 'larawa-worker-test-'));
}

test('validates text payloads', () => {
  assert.deepEqual(validateSendPayload({
    type: 'text',
    to: '120363000000000000@g.us',
    text: 'Hello group from LaraWA',
  }), { ok: true, type: 'text' });

  assert.deepEqual(validateSendPayload({
    type: 'text',
    to: '+1 202-555-0100',
    text: 'Hello from LaraWA',
  }), { ok: true, type: 'text' });

  assert.equal(validateSendPayload({
    type: 'text',
    to: '15551234567@c.us',
  }).message, 'text is required for text messages.');

  assert.equal(validateSendPayload({
    type: 'text',
    to: 'not-a-chat-id',
    text: 'Hello',
  }).message, 'to must be an international phone number, a contact chat id ending in @c.us, or a group chat id ending in @g.us.');
});

test('validates media payloads', () => {
  assert.deepEqual(validateSendPayload({
    type: 'image',
    to: '15551234567@c.us',
    media_base64: 'ZmFrZQ==',
    mime_type: 'image/png',
  }), { ok: true, type: 'image' });

  assert.equal(validateSendPayload({
    type: 'video',
    to: '15551234567@c.us',
    media_url: 'https://example.com/video.mp4',
  }).message, 'mime_type is required for media messages.');

  assert.equal(validateSendPayload({
    type: 'audio',
    to: '15551234567@c.us',
    mime_type: 'audio/ogg',
  }).message, 'media_base64 or media_url is required for media messages.');

  assert.equal(validateSendPayload({
    type: 'image',
    to: '15551234567@c.us',
    media_base64: 'MTIzNDU2',
    mime_type: 'image/png',
  }, { mediaBase64MaxBytes: 5 }).message, 'media_base64 exceeds the maximum decoded media size.');

  assert.equal(validateSendPayload({
    type: 'image',
    to: '15551234567@c.us',
    media_base64: 'ZmFrZQ==',
    mime_type: 'application/pdf',
  }).message, 'mime_type does not match image messages.');

  assert.equal(validateSendPayload({
    type: 'document',
    to: '15551234567@c.us',
    media_base64: 'not base64',
    mime_type: 'application/pdf',
  }).message, 'media_base64 must be valid base64.');

  assert.equal(validateSendPayload({
    type: 'video',
    to: '15551234567@c.us',
    media_url: 'file:///tmp/video.mp4',
    mime_type: 'video/mp4',
  }).message, 'media_url must be an HTTP or HTTPS URL.');
});

test('validates reaction payloads', () => {
  assert.deepEqual(validateSendPayload({
    type: 'reaction',
    message_id: 'wamid.test',
    reaction: '👍',
  }), { ok: true, type: 'reaction' });

  assert.equal(validateSendPayload({
    type: 'reaction',
    reaction: '👍',
  }).message, 'message_id is required for reaction messages.');
});

test('rejects unsupported or malformed payloads', () => {
  assert.equal(validateSendPayload(null).message, 'Request body must be a JSON object.');
  assert.equal(validateSendPayload([]).message, 'Request body must be a JSON object.');
  assert.equal(validateSendPayload({ type: 'sticker' }).message, 'Unsupported message type.');
  assert.equal(validateSendPayload({ type: 'text', text: 'Hello' }).message, 'to is required.');
});

test('validates WhatsApp chat identifiers', () => {
  assert.equal(isValidChatId('15551234567@c.us'), true);
  assert.equal(isValidChatId('120363000000000000@g.us'), true);
  assert.equal(isValidChatId('120363000000000000-123456789@g.us'), true);
  assert.equal(isValidChatId('status@broadcast'), false);
  assert.equal(isValidChatId('15551234567'), false);
  assert.equal(isValidChatId('../15551234567@c.us'), false);
});

test('normalizes friendly contact recipients', () => {
  assert.equal(normalizeRecipient('+12025550100'), '12025550100@c.us');
  assert.equal(normalizeRecipient('+1 202-555-0100'), '12025550100@c.us');
  assert.equal(normalizeRecipient('12025550100'), '12025550100@c.us');
  assert.equal(normalizeRecipient('12025550100@c.us'), '12025550100@c.us');
  assert.equal(normalizeRecipient('120363000000000000@g.us'), '120363000000000000@g.us');
  assert.equal(normalizeRecipient('5550100'), null);
  assert.equal(normalizeRecipient('status@broadcast'), null);
  assert.equal(normalizeRecipient('not-a-chat-id'), null);

  assert.equal(isValidRecipient('+12025550100'), true);
  assert.equal(isValidRecipient('5550100'), false);
});

test('parses positive integer configuration', () => {
  assert.equal(parsePositiveInteger('123', 10), 123);
  assert.equal(parsePositiveInteger('1.8', 10), 1);
  assert.equal(parsePositiveInteger('0', 10), 10);
  assert.equal(parsePositiveInteger('not-a-number', 10), 10);
});

test('validates media helper functions', () => {
  assert.equal(isHttpUrl('https://example.com/image.png'), true);
  assert.equal(isHttpUrl('http://example.com/image.png'), true);
  assert.equal(isHttpUrl('ftp://example.com/image.png'), false);
  assert.equal(isHttpUrl('not a url'), false);

  assert.equal(isValidBase64('ZmFrZQ=='), true);
  assert.equal(isValidBase64(' ZmFr\nZQ== '), true);
  assert.equal(isValidBase64('ZmFrZQ'), false);
  assert.equal(isValidBase64('not base64'), false);
  assert.equal(decodedBase64Bytes('MTIzNDU2'), 6);
  assert.equal(decodedBase64Bytes('not base64'), null);

  assert.equal(isValidMimeType('image/png'), true);
  assert.equal(isValidMimeType('application/pdf; charset=binary'), true);
  assert.equal(isValidMimeType('plain-text'), false);

  assert.equal(mimeMatchesMessageType('image', 'image/webp'), true);
  assert.equal(mimeMatchesMessageType('audio', 'video/mp4'), false);
  assert.equal(mimeMatchesMessageType('document', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), true);
});

test('guards outbound media URL hosts', async () => {
  assert.equal(isPublicIp('8.8.8.8'), true);
  assert.equal(isPublicIp('127.0.0.1'), false);
  assert.equal(isPublicIp('10.0.0.1'), false);
  assert.equal(isPublicIp('192.168.1.10'), false);
  assert.equal(isPublicIp('::1'), false);
  assert.equal(isPublicIp('fc00::1'), false);
  assert.equal(isPublicIp('2001:db8::1'), false);

  await assert.rejects(
    () => assertPublicHttpUrl('file:///tmp/media.png'),
    /must be an HTTP or HTTPS URL/,
  );
  await assert.rejects(
    () => assertPublicHttpUrl('http://127.0.0.1/private.png'),
    /cannot point to localhost or private network addresses/,
  );

  assert.equal((await assertPublicHttpUrl('https://8.8.8.8/media.png')).hostname, '8.8.8.8');
  assert.equal((await assertPublicHttpUrl('http://127.0.0.1/private.png', { allowPrivate: true })).hostname, '127.0.0.1');
});

test('validates worker session identifiers', () => {
  assert.equal(isValidSessionId('019e8e11-4411-72dd-b4b3-06ae734bd45c'), true);
  assert.equal(isValidSessionId('00000000-0000-0000-0000-000000000000'), true);
  assert.equal(isValidSessionId('../sessions/escape'), false);
  assert.equal(isValidSessionId('not-a-uuid'), false);
  assert.equal(isValidSessionId('019e8e11441172ddb4b306ae734bd45c'), false);
});

test('persists worker session restore registry entries', async () => {
  const dataPath = await temporarySessionDirectory();
  const registry = createSessionRegistry(dataPath, 'http://laravel/api/internal/worker/events');
  const sessionId = '019e8e11-4411-72dd-b4b3-06ae734bd45c';

  try {
    await registry.ensureDirs();
    const remembered = await registry.remember(sessionId, null);

    assert.equal(remembered.callbackUrl, 'http://laravel/api/internal/worker/events');
    assert.match(remembered.updatedAt, /^\d{4}-\d{2}-\d{2}T/);

    const restoredRegistry = createSessionRegistry(dataPath);
    assert.deepEqual(Object.keys(await restoredRegistry.read()), [sessionId]);
    assert.equal((await restoredRegistry.read())[sessionId].callbackUrl, 'http://laravel/api/internal/worker/events');

    await restoredRegistry.forget(sessionId);
    assert.deepEqual(await restoredRegistry.read(), {});
  } finally {
    await fs.rm(dataPath, { recursive: true, force: true });
  }
});

test('registry read tolerates missing, malformed, and non-object files', async () => {
  const dataPath = await temporarySessionDirectory();
  const registry = createSessionRegistry(dataPath);

  try {
    assert.deepEqual(await registry.read(), {});

    await fs.writeFile(registry.registryFile, 'not-json');
    assert.deepEqual(await registry.read(), {});

    await fs.writeFile(registry.registryFile, '[]');
    assert.deepEqual(await registry.read(), {});
  } finally {
    await fs.rm(dataPath, { recursive: true, force: true });
  }
});

test('removes LocalAuth session data when auth destruction is requested', async () => {
  const dataPath = await temporarySessionDirectory();
  const registry = createSessionRegistry(dataPath);
  const sessionId = '019e8e11-4411-72dd-b4b3-06ae734bd45c';
  const sessionPath = registry.sessionDataPath(sessionId);

  try {
    await fs.mkdir(sessionPath, { recursive: true });
    await fs.writeFile(path.join(sessionPath, 'Default'), 'chromium profile');

    await registry.removeSessionData(sessionId);

    await assert.rejects(() => fs.stat(sessionPath), /ENOENT/);
  } finally {
    await fs.rm(dataPath, { recursive: true, force: true });
  }
});

test('compares internal worker tokens securely', () => {
  assert.equal(secureTokenEquals('expected-token', 'expected-token'), true);
  assert.equal(secureTokenEquals('expected-token', 'wrong-token'), false);
  assert.equal(secureTokenEquals('expected-token', 'expected-token-extra'), false);
  assert.equal(secureTokenEquals('', ''), false);
  assert.equal(secureTokenEquals('expected-token', ''), false);
});

test('validates media response headers', () => {
  assert.equal(mediaResponseError('image', 'image/png', {
    'content-length': '100',
    'content-type': 'image/png',
  }, 1000), null);

  assert.equal(mediaResponseError('image', 'image/png', {
    'content-length': '1001',
    'content-type': 'image/png',
  }, 1000), 'Media URL response is larger than the configured 1000 byte limit.');

  assert.equal(mediaResponseError('video', 'video/mp4', {
    'content-type': 'text/html',
  }, 1000), 'Media URL response content type text/html does not match video messages.');

  assert.equal(mediaResponseError('document', 'application/pdf', {
    'content-type': 'text/html',
  }, 1000), null);
});
