// The one place that knows how the server describes recovery keys and the
// self-service password reset (#155). Everything else works with the shapes
// returned here, so a renamed field or endpoint changes only this file.
import { apiPost } from './api.js';

export const RESET_ENDPOINT = '/api/v1/password-reset.php';
export const RESET_CHALLENGE_ENDPOINT = '/api/v1/password-reset/challenge.php';
const RESET_CANCEL_ENDPOINT = '/api/v1/password-reset/cancel.php';
// The reset's own second-factor endpoints: unlike sign-in, completing a
// reset never grants privileged step-up (lead decision on #155).
export const RESET_MFA_ENDPOINTS = {
  options: '/api/v1/password-reset/mfa-options.php',
  finish: '/api/v1/password-reset/mfa-finish.php',
  recovery: '/api/v1/password-reset/mfa-recovery.php',
  // The reset's recovery request names its field "code" (#155 decision).
  recoveryField: 'code',
  totp: '/api/v1/password-reset/mfa-totp.php',
};
const CREATE_KEY_ENDPOINT = '/api/v1/account/recovery-key.php';

export const RESET_FAILED = 'reset_failed';
// Cancel found nothing pending: the reset may already have completed.
export const RESET_NOT_PENDING = 'reset_not_pending';

/** A new key handed out once (registration, reset, creation), or null. */
export function issuedKey(response) {
  return typeof response?.recovery_key === 'string' && response.recovery_key !== '' ? response.recovery_key : null;
}

/**
 * Whether the signed-in account has a key. null means the account has no
 * password to reset (provider-only) or is a guest, so recovery doesn't apply.
 */
export function readRecoveryStatus(user) {
  const status = user?.recovery_key;
  if (status === null || status === undefined || user?.guest) return null;
  return {
    exists: status.exists === true,
    createdAt: typeof status.created_at === 'string' ? status.created_at : null,
  };
}

/**
 * Sets a new password with the recovery key. Resolves to
 * { status: 'complete', key, user } or { status: 'pending_mfa', methods }.
 */
export async function resetPassword({ username, recoveryKey, newPassword, proof }) {
  const response = await apiPost(RESET_ENDPOINT, {
    username,
    recovery_key: recoveryKey,
    new_password: newPassword,
    challenge_nonce: proof?.nonce ?? null,
    challenge_solution: proof?.solution ?? null,
  });
  if (response.status === 'pending_mfa' || response.mfa_required === true) {
    return { status: 'pending_mfa', methods: Array.isArray(response.methods) ? response.methods : [] };
  }
  return { status: 'complete', key: issuedKey(response), user: response.user ?? null };
}

/** Abandons a reset waiting for the second factor; nothing is changed. */
export function cancelReset() {
  return apiPost(RESET_CANCEL_ENDPOINT);
}

/** Creates (or replaces) the signed-in account's key. Requires privileged step-up. */
export async function createRecoveryKey() {
  const response = await apiPost(CREATE_KEY_ENDPOINT);
  return { key: issuedKey(response), createdAt: typeof response.created_at === 'string' ? response.created_at : null };
}

/** The key as typed: case, spaces and dashes don't matter. Display groups of four. */
export function formatKey(key) {
  const compact = String(key).toUpperCase().replace(/[^0-9A-Z]/g, '');
  return compact.match(/.{1,4}/g)?.join('-') ?? '';
}
