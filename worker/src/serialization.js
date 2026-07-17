export function serializedId(value) {
  if (typeof value === 'string') return value;
  if (!value || typeof value !== 'object') return null;

  if (typeof value._serialized === 'string') return value._serialized;
  if (typeof value.serialized === 'string') return value.serialized;
  if (typeof value.user === 'string' && typeof value.server === 'string') {
    return `${value.user}@${value.server}`;
  }

  if (typeof value.id === 'string') {
    const remote = serializedId(value.remote);
    if (!remote) return value.id;

    const participant = serializedId(value.participant);
    return [String(Boolean(value.fromMe)), remote, value.id, participant]
      .filter((part) => part !== null)
      .join('_');
  }

  return null;
}
