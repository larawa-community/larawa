import dns from 'node:dns/promises';
import net from 'node:net';

function isLocalHostname(hostname) {
  const host = hostname.toLowerCase().replace(/\.$/, '');

  return host === 'localhost' || host.endsWith('.localhost');
}

function ipv4ToNumber(address) {
  return address.split('.').reduce((value, part) => (value * 256) + Number(part), 0) >>> 0;
}

function ipv4InRange(address, cidrBase, bits) {
  const value = ipv4ToNumber(address);
  const base = ipv4ToNumber(cidrBase);
  const mask = bits === 0 ? 0 : (0xffffffff << (32 - bits)) >>> 0;

  return (value & mask) === (base & mask);
}

function expandIpv6(address) {
  const normalized = address.toLowerCase();
  const ipv4Match = normalized.match(/(.+:)(\d+\.\d+\.\d+\.\d+)$/);
  const value = ipv4Match
    ? `${ipv4Match[1]}${Math.floor(ipv4ToNumber(ipv4Match[2]) / 65536).toString(16)}:${(ipv4ToNumber(ipv4Match[2]) % 65536).toString(16)}`
    : normalized;
  const [left, right = ''] = value.split('::');
  const leftParts = left ? left.split(':') : [];
  const rightParts = right ? right.split(':') : [];
  const missing = 8 - leftParts.length - rightParts.length;

  if (missing < 0 || (value.includes('::') && value.split('::').length > 2)) {
    return null;
  }

  const parts = [...leftParts, ...Array(Math.max(missing, 0)).fill('0'), ...rightParts];
  if (parts.length !== 8 || parts.some((part) => !/^[0-9a-f]{1,4}$/.test(part))) {
    return null;
  }

  return parts.reduce((result, part) => (result << 16n) + BigInt(parseInt(part, 16)), 0n);
}

function ipv6InRange(address, cidrBase, bits) {
  const value = expandIpv6(address);
  const base = expandIpv6(cidrBase);

  if (value === null || base === null) return false;

  const shift = 128n - BigInt(bits);

  return (value >> shift) === (base >> shift);
}

export function isPublicIp(address) {
  if (net.isIPv4(address)) {
    return ![
      ['0.0.0.0', 8],
      ['10.0.0.0', 8],
      ['100.64.0.0', 10],
      ['127.0.0.0', 8],
      ['169.254.0.0', 16],
      ['172.16.0.0', 12],
      ['192.0.0.0', 24],
      ['192.0.2.0', 24],
      ['192.168.0.0', 16],
      ['198.18.0.0', 15],
      ['198.51.100.0', 24],
      ['203.0.113.0', 24],
      ['224.0.0.0', 4],
      ['240.0.0.0', 4],
    ].some(([base, bits]) => ipv4InRange(address, base, bits));
  }

  if (net.isIPv6(address)) {
    return ![
      ['::', 128],
      ['::1', 128],
      ['::ffff:0:0', 96],
      ['64:ff9b::', 96],
      ['100::', 64],
      ['2001::', 23],
      ['2001:db8::', 32],
      ['fc00::', 7],
      ['fe80::', 10],
      ['ff00::', 8],
    ].some(([base, bits]) => ipv6InRange(address, base, bits));
  }

  return false;
}

export async function assertPublicHttpUrl(rawUrl, { allowPrivate = false, label = 'media_url' } = {}) {
  let url;

  try {
    url = new URL(rawUrl);
  } catch {
    throw new Error(`${label} must be an HTTP or HTTPS URL.`);
  }

  if (!['http:', 'https:'].includes(url.protocol) || !url.hostname) {
    throw new Error(`${label} must be an HTTP or HTTPS URL.`);
  }

  if (allowPrivate) {
    return url;
  }

  if (isLocalHostname(url.hostname)) {
    throw new Error(`${label} cannot point to localhost or private network addresses.`);
  }

  const addresses = net.isIP(url.hostname)
    ? [{ address: url.hostname }]
    : await dns.lookup(url.hostname, { all: true, verbatim: true }).catch(() => []);

  if (addresses.length === 0) {
    throw new Error(`${label} host must resolve to a public address.`);
  }

  for (const { address } of addresses) {
    if (!isPublicIp(address)) {
      throw new Error(`${label} cannot point to localhost or private network addresses.`);
    }
  }

  return url;
}
