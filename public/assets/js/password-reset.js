// Forgot password (#155): set a new password with the recovery key, on the
// sign-in card. A wrong username or key gets one generic answer, so it never
// reveals whether an account exists. Accounts with a second factor pass it
// before anything changes; cancelling then changes nothing.
import { ApiError } from './api.js';
import { buildKeyStep } from './recovery-key-step.js';
import { continueResetWithMfa, hideMfaPanel } from './mfa-login.js';
import { createRegistrationChallenge } from './registration-challenge.js';
import { RESET_CHALLENGE_ENDPOINT, RESET_FAILED, cancelReset, issuedKey, resetPassword } from './recovery.js';

const challenge = createRegistrationChallenge(RESET_CHALLENGE_ENDPOINT);
let elements = null;
let resetUsername = '';

window.addEventListener('DOMContentLoaded', () => {
  const ids = {
    section: 'password-reset',
    form: 'password-reset-form',
    back: 'password-reset-back',
    username: 'reset-username',
    key: 'reset-key',
    password: 'reset-password',
    toggle: 'reset-password-toggle',
    submit: 'reset-submit',
    status: 'password-reset-status',
    link: 'forgot-password-link',
    slot: 'recovery-key-slot',
  };
  const found = {};
  for (const [name, id] of Object.entries(ids)) {
    found[name] = document.getElementById(id);
    if (!found[name]) return;
  }
  elements = found;
  elements.link.addEventListener('click', (event) => {
    event.preventDefault();
    showReset();
  });
  elements.back.addEventListener('click', hideReset);
  elements.toggle.addEventListener('click', togglePassword);
  elements.form.addEventListener('submit', submit);
});

/**
 * Opens the reset form for /?reset=1 (the link in the downloaded key file).
 * The sign-in page calls this once it has decided what it shows.
 */
export function openResetIfRequested({ signedIn = false } = {}) {
  const parameters = new URLSearchParams(window.location.search);
  if (!parameters.has('reset')) return false;
  parameters.delete('reset');
  const query = parameters.toString();
  window.history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
  if (signedIn) return false;
  showReset();
  return true;
}

// The card's mode hides the sign-in parts with CSS (recovery.css). Toggling
// their classes would not stick: auth-tabs.js re-syncs the tab panels.
function setMode(mode) {
  const card = elements?.section.closest('.auth-card');
  if (!card) return;
  if (mode) card.dataset.mode = mode;
  else delete card.dataset.mode;
}

export function showReset({ message = '' } = {}) {
  if (!elements) return;
  setMode('reset');
  elements.slot.classList.add('hidden');
  elements.section.classList.remove('hidden');
  document.getElementById('auth-error').textContent = '';
  elements.status.textContent = message;
  challenge.prepare().catch(() => {});
  elements.username.focus();
}

function hideReset() {
  elements.section.classList.add('hidden');
  elements.form.reset();
  setPasswordVisible(false);
  restoreSignIn();
  document.getElementById('login-username')?.focus();
}

// Back to the ordinary sign-in card. The second-factor panel hides the tabs
// and the sign-in form itself, so undo that too.
function restoreSignIn() {
  setMode(null);
  document.querySelector('.auth-tabs')?.classList.remove('hidden');
  const login = document.getElementById('login-form');
  if (login && document.getElementById('login-tab')?.getAttribute('aria-selected') !== 'false') {
    login.hidden = false;
    login.classList.remove('hidden');
  }
}

function togglePassword() {
  setPasswordVisible(elements.password.type === 'password');
}

function setPasswordVisible(visible) {
  elements.password.type = visible ? 'text' : 'password';
  elements.toggle.setAttribute('aria-pressed', String(visible));
  elements.toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
}

async function submit(event) {
  event.preventDefault();
  elements.status.textContent = '';
  setBusy(true);
  resetUsername = elements.username.value.trim();
  try {
    const proof = await challenge.solution();
    const result = await resetPassword({
      username: resetUsername,
      recoveryKey: elements.key.value,
      newPassword: elements.password.value,
      proof,
    });
    if (result.status === 'pending_mfa') {
      elements.section.classList.add('hidden');
      setMode('mfa');
      continueResetWithMfa({
        title: 'One more step',
        text: 'Confirm it’s you with your passkey or a recovery code. Your password changes after this.',
        onComplete: (response) => finished(issuedKey(response)),
        onCancel: async () => {
          let acknowledged = true;
          try {
            await cancelReset();
          } catch {
            acknowledged = false;
          }
          hideMfaPanel();
          // Only an acknowledged cancel may promise that nothing changed.
          showReset({
            message: acknowledged
              ? 'Nothing was changed.'
              : 'We couldn’t reach the server to cancel. Your password changes only if the second step is finished, and the reset expires on its own soon.',
          });
        },
        onUncertain: () => uncertainOutcome(),
      });
      return;
    }
    finished(result.key);
  } catch (error) {
    // Without a second factor the first request may already have committed.
    if (error instanceof TypeError) {
      uncertainOutcome();
      return;
    }
    elements.status.textContent = failureMessage(error);
  } finally {
    // Each attempt uses up its puzzle.
    challenge.reset();
    setBusy(false);
  }
}

// The final request didn't answer. It is never repeated automatically:
// the person checks by signing in, and can create a fresh key afterwards.
function uncertainOutcome() {
  hideMfaPanel();
  elements.section.classList.add('hidden');
  elements.form.reset();
  restoreSignIn();
  document.getElementById('auth-error').textContent = 'We couldn’t tell whether your password changed. Try signing in with the new password, and if that doesn’t work, with your old one. Once you’re in, you can create a new recovery key in Account.';
  document.getElementById('login-username')?.focus();
}

function finished(key) {
  elements.form.reset();
  if (!key) {
    window.location.assign('/');
    return;
  }
  showKeyStepInCard({
    eyebrow: 'Password changed',
    title: 'Save your new recovery key',
    intro: 'Your old key is used up. You were signed out on all other devices.',
    key,
    username: resetUsername,
    continueLabel: `Continue to ${appName()}`,
    onContinue: () => window.location.assign('/'),
  });
}

/** Puts the "Save your recovery key" step on the sign-in card (registration and reset). */
export function showKeyStepInCard({ eyebrow, title, intro, notice = null, key, username, continueLabel, onContinue }) {
  if (!elements) return false;
  setMode('key');
  elements.section.classList.add('hidden');
  hideMfaPanel();
  const step = buildKeyStep({
    eyebrow, title, intro, notice, key, username, appName: appName(), continueLabel,
    onContinue: () => {
      setMode(null);
      elements.slot.classList.add('hidden');
      onContinue();
    },
  });
  elements.slot.replaceChildren(step.root);
  elements.slot.classList.remove('hidden');
  step.focus();
  return true;
}

function failureMessage(error) {
  if (error instanceof ApiError) {
    if (error.code === RESET_FAILED) return 'That username and recovery key don’t match. Check both and try again.';
    if (error.status === 429) {
      const minutes = error.retryAfterSeconds ? Math.max(1, Math.ceil(error.retryAfterSeconds / 60)) : null;
      return minutes
        ? `Too many attempts. Try again in ${minutes} ${minutes === 1 ? 'minute' : 'minutes'}.`
        : 'Too many attempts. Try again in a few minutes.';
    }
    return error.message;
  }
  return error instanceof Error ? error.message : 'The password could not be reset.';
}

function setBusy(busy) {
  for (const control of elements.form.querySelectorAll('input, button')) control.disabled = busy;
  elements.submit.textContent = busy ? 'Setting new password…' : 'Set new password';
}

function appName() {
  return document.querySelector('meta[name="application-name"]')?.content || 'ChitChat';
}
