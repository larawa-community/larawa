const ambiguousSendPatterns = [
  /^r: r$/i,
  /execution context was destroyed/i,
  /target closed/i,
  /inspected target navigated or closed/i,
  /cannot find context with specified id/i,
];

export function isAmbiguousPuppeteerSendError(error) {
  const message = String(error?.message || error || '').trim();
  return ambiguousSendPatterns.some((pattern) => pattern.test(message));
}
