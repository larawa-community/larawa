import axios from 'axios';
import express from 'express';
import { readFileSync } from 'node:fs';
import fs from 'node:fs/promises';
import path from 'node:path';
import QRCode from 'qrcode';
import whatsappWeb from 'whatsapp-web.js';
import { assertPublicHttpUrl } from './outbound-url.js';
import { PendingSendTracker } from './pending-send-tracker.js';
import { isAmbiguousPuppeteerSendError } from './puppeteer-errors.js';
import { createSessionRegistry } from './registry.js';
import { serializedId } from './serialization.js';
import {
  isValidSessionId,
  mediaResponseError,
  normalizeRecipient,
  parsePositiveInteger,
  secureTokenEquals,
  validateSendPayload,
} from './validation.js';

const { Client, LocalAuth, MessageMedia } = whatsappWeb;

const port = Number(process.env.WA_WORKER_PORT || 3001);
const dataPath = process.env.WA_WORKER_DATA_PATH || '/data/sessions';
const defaultCallbackUrl = process.env.WA_WORKER_CALLBACK_URL;
const envPath = process.env.LARAWA_ENV_PATH || '/var/www/html/storage/app/larawa/.env';
const chromiumPath = process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium';
const callbackAttempts = Number(process.env.WA_WORKER_CALLBACK_ATTEMPTS || 6);
const callbackRetryDelayMs = Number(process.env.WA_WORKER_CALLBACK_RETRY_DELAY_MS || 1500);
const shutdownTimeoutMs = Number(process.env.WA_WORKER_SHUTDOWN_TIMEOUT_MS || 25000);
const jsonBodyLimit = process.env.WA_WORKER_JSON_BODY_LIMIT || '50mb';
const mediaUrlMaxBytes = parsePositiveInteger(process.env.WA_WORKER_MEDIA_URL_MAX_BYTES, 25 * 1024 * 1024);
const mediaBase64MaxBytes = parsePositiveInteger(process.env.WA_WORKER_MEDIA_BASE64_MAX_BYTES, mediaUrlMaxBytes);
const mediaUrlMaxRedirects = Math.max(0, Math.floor(Number(process.env.WA_WORKER_MEDIA_URL_MAX_REDIRECTS ?? 3) || 0));
const mediaUrlAllowPrivate = ['true', '1', 'yes', 'on'].includes(String(process.env.WA_WORKER_MEDIA_URL_ALLOW_PRIVATE ?? process.env.MEDIA_URL_ALLOW_PRIVATE ?? 'false').toLowerCase());
const app = express();
const clients = new Map();
const sessionRegistry = createSessionRegistry(dataPath, defaultCallbackUrl);
const suppressDuringShutdown = new Set(['qr', 'authenticated', 'ready', 'auth_failure', 'disconnected', 'worker.error', 'status']);
let isShuttingDown = false;
let httpServer;

app.use(express.json({ limit: jsonBodyLimit }));

function parseEnvValue(value) {
  const trimmed = value.trim();
  if ((trimmed.startsWith('"') && trimmed.endsWith('"')) || (trimmed.startsWith("'") && trimmed.endsWith("'"))) {
    return trimmed.slice(1, -1)
      .replace(/\\"/g, '"')
      .replace(/\\\\/g, '\\')
      .replace(/\\\$/g, '$');
  }
  return trimmed;
}

function runtimeEnv() {
  try {
    const contents = readFileSync(envPath, 'utf8');
    const values = {};
    for (const line of contents.split(/\r?\n/)) {
      const match = line.match(/^\s*([A-Z0-9_]+)\s*=(.*)$/);
      if (!match || line.trimStart().startsWith('#')) continue;
      values[match[1]] = parseEnvValue(match[2]);
    }
    return values;
  } catch {
    return {};
  }
}

function workerToken() {
  return runtimeEnv().WA_WORKER_INTERNAL_TOKEN || process.env.WA_WORKER_INTERNAL_TOKEN || 'change-me-worker-token';
}

function assertInternal(req, res, next) {
  const header = req.headers.authorization || req.headers['x-worker-token'] || '';
  const provided = header.startsWith('Bearer ') ? header.slice(7) : header;
  if (!secureTokenEquals(workerToken(), provided)) {
    return res.status(401).json({ message: 'Unauthorized worker request.' });
  }
  return next();
}

async function ensureDirs() {
  await sessionRegistry.ensureDirs();
}

async function readRegistry() {
  return sessionRegistry.read();
}

async function rememberSession(sessionId, callbackUrl) {
  await sessionRegistry.remember(sessionId, callbackUrl);
}

async function forgetSession(sessionId) {
  await sessionRegistry.forget(sessionId);
}

function sessionDataPath(sessionId) {
  return sessionRegistry.sessionDataPath(sessionId);
}

function sleep(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

function shouldRetryCallback(error) {
  return !error.response || error.response.status >= 500;
}

function callbackErrorSummary(error) {
  const data = error.response?.data;
  if (!data) return null;

  if (typeof data === 'string') return data.slice(0, 2000);

  try {
    return JSON.stringify({
      message: data.message,
      errors: data.errors,
    }).slice(0, 2000);
  } catch {
    return null;
  }
}

async function removeIfExists(filePath) {
  try {
    await fs.rm(filePath, { force: true });
  } catch (error) {
    console.warn('stale lock cleanup failed', filePath, error.message);
  }
}

async function cleanupChromiumProfileLocks(sessionId) {
  const profilePath = sessionDataPath(sessionId);
  await Promise.all([
    removeIfExists(path.join(profilePath, 'SingletonLock')),
    removeIfExists(path.join(profilePath, 'SingletonSocket')),
    removeIfExists(path.join(profilePath, 'SingletonCookie')),
    removeIfExists(path.join(profilePath, 'DevToolsActivePort')),
  ]);
}

async function removeSessionData(sessionId) {
  await sessionRegistry.removeSessionData(sessionId);
}

async function stopSession(sessionId, destroyAuth = false) {
  const state = clients.get(sessionId);

  if (state) {
    if (destroyAuth) {
      await state.client.logout().catch((error) => {
        console.warn('session logout before destroy failed', sessionId, error.message);
      });
    }

    await state.client.destroy().catch((error) => {
      console.warn('session destroy failed', sessionId, error.message);
    });
    clients.delete(sessionId);
  }

  await forgetSession(sessionId);

  if (destroyAuth) {
    await removeSessionData(sessionId);
  }
}

async function emit(sessionId, event, payload = {}) {
  if (isShuttingDown && suppressDuringShutdown.has(event)) return;

  const state = clients.get(sessionId);
  const callbackUrl = state?.callbackUrl || (await readRegistry())[sessionId]?.callbackUrl || defaultCallbackUrl;
  if (!callbackUrl) return;

  for (let attempt = 1; attempt <= callbackAttempts; attempt += 1) {
    try {
      await axios.post(callbackUrl, { session_id: sessionId, event, payload }, {
        timeout: 10000,
        headers: { Authorization: `Bearer ${workerToken()}` },
      });
      return;
    } catch (error) {
      const status = error.response?.status || error.message;
      if (attempt >= callbackAttempts || !shouldRetryCallback(error)) {
        const summary = callbackErrorSummary(error);
        console.error('callback failed', sessionId, event, status, ...(summary ? [summary] : []));
        return;
      }

      console.warn('callback retrying', sessionId, event, status, `attempt ${attempt}/${callbackAttempts}`);
      await sleep(callbackRetryDelayMs);
    }
  }
}

function serializeMessage(message) {
  const from = serializedId(message.from);

  return {
    message_id: serializedId(message.id),
    from,
    to: serializedId(message.to),
    author: serializedId(message.author),
    from_me: message.fromMe,
    body: message.body,
    type: message.type,
    timestamp: message.timestamp,
    has_media: message.hasMedia,
    is_group: Boolean(from && from.endsWith('@g.us')),
  };
}

function httpError(message, statusCode = 500) {
  const error = new Error(message);
  error.statusCode = statusCode;

  return error;
}

async function resolveSendTarget(client, recipient) {
  const chatId = normalizeRecipient(recipient);

  if (!chatId) {
    throw httpError('Recipient must be an international phone number, a contact chat id ending in @c.us, or a group chat id ending in @g.us.', 422);
  }

  if (chatId.endsWith('@g.us')) {
    return {
      requested_to: chatId,
      resolved_to: chatId,
      recipient_type: 'group',
      is_registered: true,
    };
  }

  const number = chatId.replace(/@c\.us$/, '');
  const resolved = await client.getNumberId(number);
  const resolvedTo = serializedId(resolved);

  if (!resolvedTo) {
    throw httpError('Recipient is not registered on WhatsApp.', 422);
  }

  return {
    requested_to: chatId,
    resolved_to: resolvedTo,
    recipient_type: resolvedTo.endsWith('@lid') ? 'lid' : 'contact',
    is_registered: true,
  };
}

function serializeContact(contact) {
  return {
    id: serializedId(contact.id),
    number: contact.number || null,
    name: contact.name || null,
    pushname: contact.pushname || null,
    short_name: contact.shortName || null,
    is_business: Boolean(contact.isBusiness),
    is_enterprise: Boolean(contact.isEnterprise),
    is_group: Boolean(contact.isGroup),
    is_me: Boolean(contact.isMe),
    is_my_contact: Boolean(contact.isMyContact),
    is_user: Boolean(contact.isUser),
    is_wa_contact: Boolean(contact.isWAContact),
    is_blocked: Boolean(contact.isBlocked),
  };
}

function serializeChat(chat) {
  const lastMessage = chat.lastMessage ? serializeMessage(chat.lastMessage) : null;

  return {
    id: serializedId(chat.id),
    name: chat.name || null,
    is_group: Boolean(chat.isGroup),
    is_read_only: Boolean(chat.isReadOnly),
    is_muted: Boolean(chat.isMuted),
    mute_expiration: chat.muteExpiration || null,
    unread_count: chat.unreadCount || 0,
    timestamp: chat.timestamp || null,
    archived: Boolean(chat.archived),
    pinned: Boolean(chat.pinned),
    is_locked: Boolean(chat.isLocked),
    last_message: lastMessage,
  };
}

function serializeGroup(chat) {
  return {
    ...serializeChat(chat),
    description: chat.description || null,
    owner: serializedId(chat.owner),
    created_at: chat.createdAt instanceof Date ? chat.createdAt.toISOString() : null,
    participant_count: Array.isArray(chat.participants) ? chat.participants.length : null,
    participants: Array.isArray(chat.participants)
      ? chat.participants.map((participant) => ({
        id: serializedId(participant.id),
        is_admin: Boolean(participant.isAdmin),
        is_super_admin: Boolean(participant.isSuperAdmin),
      }))
      : [],
  };
}

function ackToStatus(ack) {
  return {
    [-1]: 'error',
    0: 'pending',
    1: 'sent',
    2: 'delivered',
    3: 'read',
    4: 'played',
  }[ack] || 'ack';
}

async function persistMedia(sessionId, message, payload) {
  if (!message.hasMedia) return payload;
  const media = await message.downloadMedia();
  if (!media) return payload;

  const extension = media.mimetype?.split('/')[1]?.split(';')[0] || 'bin';
  const filename = media.filename || `${Date.now()}-${message.id.id}.${extension}`;

  return {
    ...payload,
    mime_type: media.mimetype,
    filename,
    media: {
      base64: media.data,
      mime_type: media.mimetype,
      filename,
    },
  };
}

async function buildMedia(payload) {
  if (payload.media_base64) {
    return new MessageMedia(payload.mime_type, payload.media_base64, payload.filename);
  }

  const response = await fetchMediaUrl(payload.media_url);
  const responseError = mediaResponseError(payload.type, payload.mime_type, response.headers, mediaUrlMaxBytes);
  if (responseError) {
    throw new Error(responseError);
  }

  return new MessageMedia(payload.mime_type, Buffer.from(response.data).toString('base64'), payload.filename);
}

async function fetchMediaUrl(rawUrl) {
  let currentUrl = rawUrl;

  for (let redirects = 0; redirects <= mediaUrlMaxRedirects; redirects += 1) {
    const guardedUrl = await assertPublicHttpUrl(currentUrl, {
      allowPrivate: mediaUrlAllowPrivate,
      label: 'media_url',
    });
    const response = await axios.get(guardedUrl.toString(), {
      responseType: 'arraybuffer',
      timeout: 30000,
      maxContentLength: mediaUrlMaxBytes,
      maxBodyLength: mediaUrlMaxBytes,
      maxRedirects: 0,
      validateStatus: (status) => status >= 200 && status < 400,
    });

    if (response.status < 300) {
      return response;
    }

    const location = response.headers.location;
    if (!location) {
      throw new Error('media_url redirect is missing a Location header.');
    }

    if (redirects >= mediaUrlMaxRedirects) {
      throw new Error('media_url exceeded the maximum redirect limit.');
    }

    currentUrl = new URL(location, guardedUrl).toString();
  }

  throw new Error('media_url exceeded the maximum redirect limit.');
}

async function startSession(sessionId, callbackUrl) {
  if (clients.has(sessionId)) {
    const current = clients.get(sessionId);
    current.callbackUrl = callbackUrl || current.callbackUrl;
    await rememberSession(sessionId, current.callbackUrl);
    return current;
  }

  await rememberSession(sessionId, callbackUrl);
  await cleanupChromiumProfileLocks(sessionId);
  const client = new Client({
    authStrategy: new LocalAuth({ clientId: sessionId, dataPath }),
    puppeteer: {
      executablePath: chromiumPath,
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
    },
  });
  const state = {
    client,
    sessionId,
    callbackUrl: callbackUrl || defaultCallbackUrl,
    status: 'initializing',
    qr: null,
    qrDataUrl: null,
    readyAt: null,
    pendingSends: new PendingSendTracker(),
  };
  clients.set(sessionId, state);

  client.on('qr', async (qr) => {
    state.status = 'qr';
    state.qr = qr;
    state.qrDataUrl = await QRCode.toDataURL(qr);
    await emit(sessionId, 'qr', { qr, qr_data_url: state.qrDataUrl });
  });

  client.on('authenticated', () => {
    state.status = 'authenticated';
    state.qr = null;
    state.qrDataUrl = null;
    emit(sessionId, 'authenticated', {});
  });

  client.on('ready', async () => {
    state.status = 'ready';
    state.qr = null;
    state.qrDataUrl = null;
    state.readyAt = new Date().toISOString();
    const info = client.info || {};
    await emit(sessionId, 'ready', {
      phone_number: info.wid?.user,
      platform: info.platform,
      pushname: info.pushname,
    });
  });

  client.on('auth_failure', (message) => {
    state.status = 'auth_failure';
    emit(sessionId, 'auth_failure', { message });
  });

  client.on('disconnected', (reason) => {
    state.status = 'disconnected';
    emit(sessionId, 'disconnected', { reason });
  });

  client.on('message', async (message) => {
    const payload = await persistMedia(sessionId, message, serializeMessage(message));
    await emit(sessionId, 'message.received', payload);
  });

  client.on('message_create', async (message) => {
    if (!message.fromMe) return;
    const serialized = serializeMessage(message);
    const clientMessageId = state.pendingSends.match(message);
    if (clientMessageId) serialized.client_message_id = clientMessageId;
    const payload = await persistMedia(sessionId, message, serialized);
    await emit(sessionId, 'message.created', payload);
  });

  client.on('message_ack', (message, ack) => {
    emit(sessionId, 'message.status', {
      message_id: serializedId(message.id),
      status: ackToStatus(ack),
      ack,
    });
  });

  client.on('message_reaction', (reaction) => {
    emit(sessionId, 'message.reaction', {
      message_id: serializedId(reaction.msgId),
      reaction: reaction.reaction,
      sender: reaction.senderId,
      timestamp: reaction.timestamp,
    });
  });

  client.on('group_join', (notification) => emit(sessionId, 'group.join', notification));
  client.on('group_leave', (notification) => emit(sessionId, 'group.leave', notification));
  client.on('change_state', (status) => emit(sessionId, 'status', { status }));

  client.initialize().catch((error) => {
    state.status = 'failed';
    emit(sessionId, 'worker.error', { message: error.message });
  });

  return state;
}

function readySession(req, res) {
  const sessionId = validatedRouteSessionId(req, res);
  if (!sessionId) return null;

  const state = clients.get(sessionId);
  if (!state) {
    res.status(404).json({ message: 'Session is not running in this worker.' });
    return null;
  }

  if (state.status !== 'ready') {
    res.status(409).json({ message: 'Session is not ready.' });
    return null;
  }

  return state;
}

function rejectInvalidSessionId(res) {
  res.status(422).json({ message: 'session_id must be a UUID.' });
  return null;
}

function validatedRouteSessionId(req, res) {
  const { sessionId } = req.params;

  if (!isValidSessionId(sessionId)) {
    return rejectInvalidSessionId(res);
  }

  return sessionId;
}

function collectionLimit(req) {
  const requested = Number(req.query.limit || 100);

  if (!Number.isFinite(requested) || requested < 1) return 100;

  return Math.min(Math.floor(requested), 500);
}

function rejectUnavailableChatCollection(res) {
  return res.status(503).json({
    message: 'Chat and group listing is temporarily unavailable due to an upstream whatsapp-web.js compatibility issue.',
    upstream_issue: 'https://github.com/wwebjs/whatsapp-web.js/issues/201838',
  });
}

app.get('/health', (_req, res) => {
  res.status(isShuttingDown ? 503 : 200).json({ ok: !isShuttingDown, sessions: clients.size });
});

app.use((req, res, next) => {
  if (isShuttingDown) {
    return res.status(503).json({ message: 'Worker is shutting down.' });
  }

  return next();
});

app.post('/internal/sessions', assertInternal, async (req, res, next) => {
  try {
    const { session_id: sessionId, callback_url: callbackUrl } = req.body;
    if (!sessionId) return res.status(422).json({ message: 'session_id is required.' });
    if (!isValidSessionId(sessionId)) return rejectInvalidSessionId(res);
    const state = await startSession(sessionId, callbackUrl);
    res.status(202).json({ session_id: sessionId, status: state.status });
  } catch (error) {
    next(error);
  }
});

app.get('/internal/sessions/:sessionId', assertInternal, async (req, res) => {
  const sessionId = validatedRouteSessionId(req, res);
  if (!sessionId) return;

  const state = clients.get(sessionId);
  if (!state) return res.status(404).json({ message: 'Session is not running in this worker.' });
  const info = state.client.info || {};
  res.json({
    session_id: state.sessionId,
    status: state.status,
    ready_at: state.readyAt,
    qr: state.qr,
    qr_data_url: state.qrDataUrl,
    phone_number: info.wid?.user,
    platform: info.platform,
    pushname: info.pushname,
  });
});

app.delete('/internal/sessions/:sessionId', assertInternal, async (req, res, next) => {
  try {
    const sessionId = validatedRouteSessionId(req, res);
    if (!sessionId) return;

    const destroyAuth = req.body?.destroy !== false;
    await stopSession(sessionId, destroyAuth);
    res.json({
      message: destroyAuth ? 'Session stopped and auth data removed.' : 'Session stopped and unregistered; auth data preserved.',
      destroyed_auth: destroyAuth,
    });
  } catch (error) {
    next(error);
  }
});

app.get('/internal/sessions/:sessionId/chats', assertInternal, async (req, res, next) => {
  try {
    const state = readySession(req, res);
    if (!state) return;

    return rejectUnavailableChatCollection(res);
  } catch (error) {
    next(error);
  }
});

app.get('/internal/sessions/:sessionId/contacts', assertInternal, async (req, res, next) => {
  try {
    const state = readySession(req, res);
    if (!state) return;

    const contacts = await state.client.getContacts();
    res.json({
      data: contacts
        .filter((contact) => !contact.isGroup)
        .slice(0, collectionLimit(req))
        .map(serializeContact),
    });
  } catch (error) {
    next(error);
  }
});

app.get('/internal/sessions/:sessionId/groups', assertInternal, async (req, res, next) => {
  try {
    const state = readySession(req, res);
    if (!state) return;

    return rejectUnavailableChatCollection(res);
  } catch (error) {
    next(error);
  }
});

app.post('/internal/sessions/:sessionId/send', assertInternal, async (req, res, next) => {
  try {
    const state = readySession(req, res);
    if (!state) return;

    const payload = req.body;
    const validation = validateSendPayload(payload, { mediaBase64MaxBytes });
    if (!validation.ok) {
      return res.status(422).json({ message: validation.message });
    }

    let sent;
    if (payload.type === 'reaction') {
      const message = await state.client.getMessageById(payload.message_id);
      await message.react(payload.reaction);
      return res.json({ status: 'sent', message_id: payload.message_id });
    }

    const target = await resolveSendTarget(state.client, payload.to);
    const trackedSend = state.pendingSends.track(payload.client_message_id, payload, target.resolved_to);

    try {
      if (['image', 'video', 'document', 'audio'].includes(payload.type)) {
        const media = await buildMedia(payload);
        sent = await state.client.sendMessage(target.resolved_to, media, {
          caption: payload.caption,
          sendAudioAsVoice: payload.type === 'audio' && payload.as_voice === true,
          sendMediaAsDocument: payload.type === 'document',
        });
      } else {
        sent = await state.client.sendMessage(target.resolved_to, payload.text);
      }
    } catch (error) {
      if (!isAmbiguousPuppeteerSendError(error)) {
        state.pendingSends.forget(trackedSend);
        throw error;
      }

      console.warn('send result uncertain', state.sessionId, target.resolved_to, error.message || error);
      return res.status(202).json({
        status: 'pending',
        message_id: null,
        delivery_uncertain: true,
        warning: 'WhatsApp may have accepted the message, but the browser result could not be confirmed.',
        ...target,
      });
    }

    state.pendingSends.forget(trackedSend);
    res.json({ status: 'pending', message_id: serializedId(sent.id), ...target });
  } catch (error) {
    next(error);
  }
});

app.use((error, _req, res, _next) => {
  console.error(error);
  res.status(error.statusCode || 500).json({ message: error.message || 'Worker error.' });
});

await ensureDirs();
const registry = await readRegistry();
for (const [sessionId, value] of Object.entries(registry)) {
  if (!isValidSessionId(sessionId)) {
    console.warn('skipping invalid session id in worker registry', sessionId);
    continue;
  }

  startSession(sessionId, value.callbackUrl).catch((error) => console.error('session boot failed', sessionId, error));
}

async function closeClientForShutdown(sessionId, state) {
  state.status = 'stopping';
  await state.client.destroy().catch((error) => {
    console.warn('session destroy during shutdown failed', sessionId, error.message);
  });
  clients.delete(sessionId);
}

async function closeHttpServer() {
  if (!httpServer) return;

  await new Promise((resolve) => {
    httpServer.close((error) => {
      if (error) console.warn('worker http server close failed', error.message);
      resolve();
    });
  });
}

async function shutdown(signal) {
  if (isShuttingDown) return;

  isShuttingDown = true;
  console.log(`LaraWA worker received ${signal}; closing ${clients.size} WhatsApp session(s).`);

  const forcedExit = setTimeout(() => {
    console.error(`LaraWA worker shutdown exceeded ${shutdownTimeoutMs}ms; exiting.`);
    process.exit(1);
  }, shutdownTimeoutMs);
  forcedExit.unref();

  await closeHttpServer();
  await Promise.all(Array.from(clients.entries()).map(([sessionId, state]) => closeClientForShutdown(sessionId, state)));
  clearTimeout(forcedExit);
  console.log('LaraWA worker shutdown complete.');
  process.exit(0);
}

process.on('SIGTERM', () => {
  shutdown('SIGTERM').catch((error) => {
    console.error('worker shutdown failed', error);
    process.exit(1);
  });
});

process.on('SIGINT', () => {
  shutdown('SIGINT').catch((error) => {
    console.error('worker shutdown failed', error);
    process.exit(1);
  });
});

httpServer = app.listen(port, () => {
  console.log(`LaraWA worker listening on ${port}`);
});
