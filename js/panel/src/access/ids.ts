// The id of a new aggregate a page creates (PRD 5.3): the roles and grants commands take the id of
// the role or grant they create from the caller, as every create command of the kernel does, so
// a repeat of the same call is the same aggregate. The kernel's ids are UUIDv7 (RFC 9562): 48
// bits of the time in milliseconds, the version 7, 12 random bits, the variant and 62 random
// bits, which the page makes from the browser's clock and its random source.

/** The form of a UUIDv7 as the kernel's schemas take it. */
export const UUID7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

/**
 * A new UUIDv7 in lowercase, from the time given, in milliseconds since the epoch, and the random
 * bytes given, 10 of them; by default from the browser's clock and its random source.
 */
export function uuid7(
  now: number = Date.now(),
  random: Uint8Array = crypto.getRandomValues(new Uint8Array(10)),
): string {
  if (random.length < 10) {
    throw new RangeError('A UUIDv7 needs 10 random bytes.');
  }

  const bytes = new Uint8Array(16);
  let time = Math.max(0, Math.floor(now));

  for (let index = 5; index >= 0; index -= 1) {
    bytes[index] = time % 256;
    time = Math.floor(time / 256);
  }

  bytes.set(random.subarray(0, 10), 6);
  bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x70;
  bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;

  const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
