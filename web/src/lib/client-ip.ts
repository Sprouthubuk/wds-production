import { isIP } from 'node:net';

const loopback = (ip: string): boolean => /^(::ffff:)?127\./.test(ip) || ip === '::1';

/**
 * The visitor's IP. Behind nginx (the connection is from this machine) it's X-Real-IP, which nginx sets itself on
 * every request (forge/web-nginx.conf), replacing anything the visitor sent. X-Forwarded-For isn't used: nginx adds
 * to what the visitor sent. Connected to directly, it's the connection's own address and no header is believed.
 */
export function visitorIp(socketAddress: string | null, headers: Headers): string | null {
  if (!socketAddress) return null;
  const real = (headers.get('x-real-ip') ?? '').trim();
  return loopback(socketAddress) && isIP(real) ? real : socketAddress;
}
