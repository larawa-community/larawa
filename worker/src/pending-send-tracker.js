const defaultTtlMs = 30000;

export class PendingSendTracker {
  constructor(ttlMs = defaultTtlMs) {
    this.ttlMs = ttlMs;
    this.attempts = new Map();
  }

  track(clientMessageId, payload, resolvedTo) {
    if (!clientMessageId) return null;
    this.prune();
    const key = String(clientMessageId);
    this.attempts.set(key, {
      clientMessageId,
      body: payload.text ?? payload.caption ?? '',
      resolvedTo,
      createdAt: Date.now(),
    });
    return key;
  }

  forget(clientMessageId) {
    if (clientMessageId) this.attempts.delete(String(clientMessageId));
  }

  match(message) {
    this.prune();
    const body = message.body ?? '';
    const to = typeof message.to === 'string' ? message.to : message.to?._serialized;
    const attempts = [...this.attempts.entries()];
    const match = attempts.find(([, attempt]) => attempt.body === body && attempt.resolvedTo === to)
      || attempts.find(([, attempt]) => attempt.body !== '' && attempt.body === body);

    if (!match) return null;
    const [key, attempt] = match;
    this.attempts.delete(key);
    return attempt.clientMessageId;
  }

  prune() {
    const cutoff = Date.now() - this.ttlMs;
    for (const [key, attempt] of this.attempts) {
      if (attempt.createdAt < cutoff) this.attempts.delete(key);
    }
  }
}
