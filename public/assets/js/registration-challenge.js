import { apiGet } from './api.js';

/*
 * Registration bot resistance. The challenge is fetched when the registration
 * form is shown, which also starts the server's minimum-fill-time clock, and
 * its small proof-of-work puzzle is solved in the background while the person
 * fills in the form. Each challenge is single-use.
 */
export function createRegistrationChallenge(endpoint = '/api/v1/registration-challenge.php') {
  let pending = null;

  function prepare() {
    if (pending === null) {
      pending = fetchAndSolve(endpoint);
      pending.catch(() => {
        pending = null;
      });
    }
    return pending;
  }

  return {
    prepare,
    /** Resolves to { nonce, solution }, or null when the server requires no challenge. */
    solution: prepare,
    reset() {
      pending = null;
    },
  };
}

async function fetchAndSolve(endpoint) {
  const response = await apiGet(endpoint);
  const challenge = response.challenge;
  if (!challenge) return null;
  return { nonce: challenge.nonce, solution: await solve(challenge.nonce, challenge.bits) };
}

const BATCH_SIZE = 256;

async function solve(nonce, bits) {
  if (bits === 0) return '0';
  if (!globalThis.crypto?.subtle) {
    throw new Error('Registration requires a secure (HTTPS) connection.');
  }

  const encoder = new TextEncoder();
  for (let start = 0; ; start += BATCH_SIZE) {
    const digests = await Promise.all(Array.from({ length: BATCH_SIZE }, (_, offset) => (
      crypto.subtle.digest('SHA-256', encoder.encode(`${nonce}:${start + offset}`))
    )));
    const index = digests.findIndex((digest) => leadingZeroBits(new Uint8Array(digest)) >= bits);
    if (index !== -1) return String(start + index);
  }
}

function leadingZeroBits(bytes) {
  let count = 0;
  for (const byte of bytes) {
    if (byte !== 0) return count + Math.clz32(byte) - 24;
    count += 8;
  }
  return count;
}
