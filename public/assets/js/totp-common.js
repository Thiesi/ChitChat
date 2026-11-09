// Shared pieces for authenticator codes (#204): the code field, the form
// sign-in panels use, and plain words for the documented outcomes. Kept
// free of heavy imports so the sign-in pages can load it cheaply.
import { ApiError } from './api.js';

/** A six-digit code field: numeric keyboard, one-time-code autofill; the server ignores spaces. */
export function codeInput(id) {
  const input = document.createElement('input');
  input.id = id;
  input.name = 'code';
  input.type = 'text';
  input.inputMode = 'numeric';
  input.autocomplete = 'one-time-code';
  input.maxLength = 12;
  input.spellcheck = false;
  input.required = true;
  input.className = 'totp-code-input';
  return input;
}

/** The "code from your authenticator app" form used on sign-in, restoration and reset. */
export function buildTotpForm(id, onSubmit) {
  const form = document.createElement('form');
  form.id = `${id}-form`;
  form.className = 'form-stack totp-entry';
  form.hidden = true;
  const label = document.createElement('label');
  label.htmlFor = id;
  label.textContent = 'Code from your authenticator app';
  const input = codeInput(id);
  const submit = document.createElement('button');
  submit.type = 'submit';
  submit.className = 'primary-button';
  submit.textContent = 'Use code';
  form.append(label, input, submit);
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    onSubmit();
  });
  return { form, input, submit };
}

/**
 * The second-step methods for a pending sign-in. The server lists them as
 * passkey, recovery_code and totp; where no list came with the page (a
 * Google or Twitch sign-in returning here), the authenticator option is
 * offered when the server supports it, and a wrong guess just fails.
 */
export function pendingMethods(methods, session) {
  if (Array.isArray(methods)) return methods;
  if (Array.isArray(session?.mfa_pending?.methods)) return session.mfa_pending.methods;
  return session?.capabilities?.totp === true ? ['passkey', 'recovery_code', 'totp'] : ['passkey', 'recovery_code'];
}

/** Plain words for the documented outcomes; anything else keeps the server's message. */
export function describeTotpError(failure) {
  if (failure instanceof ApiError) {
    if (failure.code === 'invalid_totp') return 'That code didn’t work. Wait for a new code in your app, then enter it.';
    if (failure.code === 'last_mfa_method') return 'This is your only second step. Add a passkey first, or turn off two-step sign-in below.';
    if (failure.code === 'totp_not_found') return 'There’s no authenticator app to remove.';
    if (failure.code === 'totp_unavailable') return 'Authenticator codes can’t be checked on this server right now. Use a passkey or a recovery code.';
    if (failure.code === 'rate_limited') {
      const minutes = Math.max(1, Math.ceil((failure.retryAfterSeconds ?? 60) / 60));
      return `Too many tries. Wait ${minutes} ${minutes === 1 ? 'minute' : 'minutes'}, then try again.`;
    }
  }
  return failure instanceof Error && failure.message ? failure.message : 'That didn’t work. Try again.';
}
