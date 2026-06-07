import { createHash, timingSafeEqual } from 'node:crypto';
import { parsePhoneNumberFromString } from 'libphonenumber-js';

const mediaTypes = new Set(['image', 'video', 'document', 'audio']);
const sendTypes = new Set(['text', 'reaction', ...mediaTypes]);
const mimeTypePattern = /^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*(?:\s*;.*)?$/i;
const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const chatIdPattern = /^[A-Za-z0-9._-]+@(c|g)\.us$/;
const phoneInputPattern = /^\+?[0-9][0-9\s().-]*$/;

function present(value) {
  return typeof value === 'string' && value.trim() !== '';
}

function fail(message) {
  return { ok: false, message };
}

export function parsePositiveInteger(value, fallback) {
  const parsed = Number(value);

  if (!Number.isFinite(parsed) || parsed < 1) {
    return fallback;
  }

  return Math.floor(parsed);
}

export function isHttpUrl(value) {
  if (!present(value)) return false;

  try {
    const url = new URL(value);

    return ['http:', 'https:'].includes(url.protocol) && Boolean(url.hostname);
  } catch {
    return false;
  }
}

export function isValidSessionId(value) {
  return present(value) && uuidPattern.test(value);
}

export function isValidChatId(value) {
  return present(value) && chatIdPattern.test(value.trim());
}

export function normalizeRecipient(value) {
  if (!present(value)) return null;

  const recipient = value.trim();
  if (isValidChatId(recipient)) return recipient;
  if (!phoneInputPattern.test(recipient)) return null;

  const digits = recipient.replace(/\D/g, '');
  if (digits.length < 8 || digits.length > 15) return null;

  const parsed = recipient.startsWith('+')
    ? parsePhoneNumberFromString(recipient)
    : parsePhoneNumberFromString(`+${digits}`);

  if (!parsed || !parsed.isPossible()) return null;

  return `${parsed.number.replace(/^\+/, '')}@c.us`;
}

export function isValidRecipient(value) {
  return normalizeRecipient(value) !== null;
}

export function secureTokenEquals(expected, provided) {
  if (!present(expected) || !present(provided)) return false;

  const expectedDigest = createHash('sha256').update(String(expected)).digest();
  const providedDigest = createHash('sha256').update(String(provided)).digest();

  return timingSafeEqual(expectedDigest, providedDigest);
}

export function isValidBase64(value) {
  if (!present(value)) return false;

  const normalized = value.replace(/\s+/g, '');

  return normalized.length > 0
    && normalized.length % 4 === 0
    && /^[A-Za-z0-9+/]*={0,2}$/.test(normalized);
}

export function decodedBase64Bytes(value) {
  if (!isValidBase64(value)) return null;

  return Buffer.from(value.replace(/\s+/g, ''), 'base64').length;
}

export function isValidMimeType(value) {
  return present(value) && mimeTypePattern.test(value);
}

export function mimeMatchesMessageType(type, mimeType) {
  const family = String(mimeType || '').split(';')[0].trim().toLowerCase().split('/')[0];

  return {
    image: family === 'image',
    video: family === 'video',
    audio: family === 'audio',
    document: isValidMimeType(mimeType),
  }[type] ?? false;
}

export function mediaResponseError(type, expectedMimeType, headers = {}, maxBytes = null) {
  const contentLength = Number(headers['content-length']);
  if (Number.isFinite(contentLength) && maxBytes && contentLength > maxBytes) {
    return `Media URL response is larger than the configured ${maxBytes} byte limit.`;
  }

  const contentType = String(headers['content-type'] || '').split(';')[0].trim().toLowerCase();
  if (!contentType) {
    return null;
  }

  if (type === 'document') {
    return null;
  }

  if (!mimeMatchesMessageType(type, contentType)) {
    return `Media URL response content type ${contentType} does not match ${type} messages.`;
  }

  if (!mimeMatchesMessageType(type, expectedMimeType)) {
    return `Declared MIME type ${expectedMimeType} does not match ${type} messages.`;
  }

  return null;
}

export function validateSendPayload(payload, options = {}) {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    return fail('Request body must be a JSON object.');
  }

  const type = payload.type || 'text';
  if (!sendTypes.has(type)) {
    return fail('Unsupported message type.');
  }

  if (type === 'reaction') {
    if (!present(payload.message_id)) {
      return fail('message_id is required for reaction messages.');
    }

    if (!present(payload.reaction)) {
      return fail('reaction is required for reaction messages.');
    }

    return { ok: true, type };
  }

  if (!present(payload.to)) {
    return fail('to is required.');
  }

  if (!isValidRecipient(payload.to)) {
    return fail('to must be an international phone number, a contact chat id ending in @c.us, or a group chat id ending in @g.us.');
  }

  if (type === 'text') {
    if (!present(payload.text)) {
      return fail('text is required for text messages.');
    }

    return { ok: true, type };
  }

  if (!present(payload.mime_type)) {
    return fail('mime_type is required for media messages.');
  }

  if (!isValidMimeType(payload.mime_type)) {
    return fail('mime_type must be a valid MIME type.');
  }

  if (!mimeMatchesMessageType(type, payload.mime_type)) {
    return fail(`mime_type does not match ${type} messages.`);
  }

  if (!present(payload.media_base64) && !present(payload.media_url)) {
    return fail('media_base64 or media_url is required for media messages.');
  }

  if (present(payload.media_base64) && !isValidBase64(payload.media_base64)) {
    return fail('media_base64 must be valid base64.');
  }

  const mediaBase64MaxBytes = parsePositiveInteger(options.mediaBase64MaxBytes, 0);
  if (present(payload.media_base64) && mediaBase64MaxBytes > 0 && decodedBase64Bytes(payload.media_base64) > mediaBase64MaxBytes) {
    return fail('media_base64 exceeds the maximum decoded media size.');
  }

  if (present(payload.media_url) && !isHttpUrl(payload.media_url)) {
    return fail('media_url must be an HTTP or HTTPS URL.');
  }

  return { ok: true, type };
}
