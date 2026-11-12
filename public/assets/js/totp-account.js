// The authenticator-app part of You's two-step sign-in card (#204). Setup
// shows a QR code drawn here on the page and the same secret as text, asks
// for one code to confirm, and shows any first recovery codes once. The
// secret and its otpauth:// URI never leave this page: they are not put in a
// URL, an attribute or a log, and the setup is gone once the panel closes.
import { ApiError, apiPost } from './api.js';
import { confirmAction } from './confirm-dialog.js';
import { codeInput, describeTotpError as describe } from './totp-common.js';
import { formatDateTime } from './datetime.js';
import { withPrivilegedStepUp } from './step-up.js';
import qrcode from '/assets/vendor/qrcode-generator/qrcode.js';

const BASE = '/api/v1/account/mfa/totp';

/**
 * @param {{ onStatus: (mfa: object) => void, onRecoveryCodes: (codes: string[]) => void }} hooks
 */
export function createTotpBlock(hooks) {
  const root = document.createElement('section');
  root.className = 'mfa-totp';
  root.setAttribute('aria-labelledby', 'mfa-totp-heading');
  root.hidden = true;

  // Inside Passkeys and recovery codes (h3) under Sign-in and security (h2).
  const heading = document.createElement('h4');
  heading.id = 'mfa-totp-heading';
  heading.textContent = 'Authenticator app';
  heading.tabIndex = -1;
  const summary = document.createElement('p');
  summary.className = 'account-muted';
  const actions = document.createElement('div');
  actions.className = 'account-action-row';
  const panel = document.createElement('div');
  panel.className = 'totp-setup';
  panel.hidden = true;
  const feedback = document.createElement('p');
  feedback.className = 'account-muted';
  feedback.setAttribute('role', 'status');
  feedback.setAttribute('aria-live', 'polite');
  const error = document.createElement('p');
  error.className = 'error-text';
  error.setAttribute('role', 'alert');
  root.append(heading, summary, actions, panel, feedback, error);

  let current = null;
  let enrollment = null;
  let expiryTimer = null;
  // A confirm in flight: the local five-minute timer then waits for the server's answer.
  let confirming = false;
  let expiredMeanwhile = false;

  function render(mfa, capability) {
    current = mfa;
    const totp = mfa?.totp ?? {};
    // Shown when the server offers authenticator apps, or when this account already has one.
    root.hidden = !(capability || totp.enabled);
    if (root.hidden) return;
    actions.replaceChildren();
    if (enrollment) {
      summary.textContent = '';
      return;
    }
    if (totp.enabled && totp.available === false) {
      summary.textContent = 'Codes from your authenticator app can’t be checked on this server right now. Use a passkey or a recovery code instead; your app is still set up.';
      return;
    }
    if (totp.enabled) {
      const used = totp.last_used_at ? `last used ${formatDateTime(totp.last_used_at)}` : 'not used yet';
      summary.textContent = `Set up ${formatDateTime(totp.created_at)}, ${used}. When you sign in, you can enter the six-digit code your app shows.`;
      actions.append(
        button('Replace with a new app', 'secondary-button', () => void begin(true)),
        button('Remove authenticator app', 'danger-button', () => void remove()),
      );
      return;
    }
    if (totp.available === false) {
      summary.textContent = 'Authenticator apps aren’t available on this server right now.';
      return;
    }
    summary.textContent = 'Use six-digit codes from an authenticator app on your phone as a second step when you sign in. Passkeys resist phishing better; an app is a good addition or alternative.';
    actions.append(button('Set up an authenticator app', 'primary-button', () => void begin(false)));
  }

  async function begin(replacing) {
    clear();
    setBusy(true);
    try {
      const response = await withPrivilegedStepUp(() => apiPost(`${BASE}/begin.php`, {}));
      enrollment = { id: response.enrollment_id, expiresAt: Date.parse(response.expires_at), replacing };
      showSetup(response);
    } catch (failure) {
      error.textContent = describe(failure);
    } finally {
      setBusy(false);
    }
  }

  function showSetup(response) {
    actions.replaceChildren();
    summary.textContent = '';
    panel.replaceChildren();

    const intro = document.createElement('p');
    intro.textContent = enrollment.replacing
      ? 'Scan this code with your new authenticator app. Your current app keeps working until you confirm.'
      : 'Scan this code with your authenticator app, or type the key into it.';

    const figure = document.createElement('figure');
    figure.className = 'totp-qr-figure';
    figure.append(drawQr(response.otpauth_uri));
    const caption = document.createElement('figcaption');
    caption.className = 'account-muted';
    caption.textContent = 'Drawn on this page. The key is never sent anywhere else.';
    figure.append(caption);

    const keyBlock = document.createElement('div');
    keyBlock.className = 'totp-key';
    const keyLabel = document.createElement('p');
    keyLabel.className = 'totp-key-label';
    keyLabel.textContent = 'Or enter this key in the app (time-based, six digits, 30 seconds):';
    const key = document.createElement('code');
    key.className = 'totp-key-value';
    // Groups of four, as most apps show them; spaces are ignored when typed.
    key.textContent = String(response.secret ?? '').replace(/(.{4})(?=.)/g, '$1 ');
    const copy = button('Copy key', 'secondary-button', async () => {
      try {
        await navigator.clipboard.writeText(String(response.secret ?? ''));
        feedback.textContent = 'Key copied.';
      } catch {
        feedback.textContent = 'Copy didn’t work. Select the key and copy it.';
      }
    });
    keyBlock.append(keyLabel, key, copy);

    const form = document.createElement('form');
    form.className = 'totp-confirm';
    const label = document.createElement('label');
    label.htmlFor = 'totp-setup-code';
    label.textContent = 'Code from the app';
    const input = codeInput('totp-setup-code');
    const confirm = document.createElement('button');
    confirm.type = 'submit';
    confirm.className = 'primary-button';
    confirm.textContent = 'Confirm';
    const cancel = button('Cancel', 'secondary-button', () => void cancelSetup());
    const row = document.createElement('div');
    row.className = 'account-action-row';
    row.append(confirm, cancel);
    const expiry = document.createElement('p');
    expiry.className = 'account-muted';
    expiry.textContent = 'This setup ends in 5 minutes if it isn’t confirmed.';
    form.append(label, input, row, expiry);
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      void confirmSetup(input);
    });

    panel.append(intro, figure, keyBlock, form);
    panel.hidden = false;
    window.clearTimeout(expiryTimer);
    expiryTimer = window.setTimeout(() => {
      if (!enrollment) return;
      // Never cut off a confirm the server may already have accepted.
      if (confirming) {
        expiredMeanwhile = true;
        return;
      }
      closeSetup();
      error.textContent = 'The setup ended before it was confirmed. Start again to get a new key.';
    }, Math.max(0, enrollment.expiresAt - Date.now()));
    input.focus();
  }

  async function confirmSetup(input) {
    clear();
    // Captured before anything is awaited: step-up or a slow answer may outlast the local timer.
    const attempt = enrollment;
    if (!attempt) return;
    const code = input.value;
    confirming = true;
    setBusy(true);
    try {
      const response = await withPrivilegedStepUp(() => apiPost(`${BASE}/confirm.php`, { enrollment_id: attempt.id, code }));
      const replacing = attempt.replacing;
      // An acknowledged success is always shown, with its once-only recovery codes.
      expiredMeanwhile = false;
      closeSetup();
      hooks.onStatus(response.mfa);
      feedback.textContent = replacing ? 'Your new authenticator app is set up. The old one no longer works.' : 'Your authenticator app is set up.';
      if (Array.isArray(response.recovery_codes) && response.recovery_codes.length > 0) hooks.onRecoveryCodes(response.recovery_codes);
      else heading.focus();
    } catch (failure) {
      if (failure instanceof ApiError && failure.code === 'totp_enrollment_expired') {
        closeSetup();
        error.textContent = 'The setup ended before it was confirmed. Start again to get a new key.';
        return;
      }
      if (expiredMeanwhile) {
        closeSetup();
        error.textContent = 'The setup ended before it was confirmed. Start again to get a new key.';
        return;
      }
      error.textContent = describe(failure);
      input.select();
    } finally {
      confirming = false;
      expiredMeanwhile = false;
      setBusy(false);
    }
  }

  async function cancelSetup() {
    const id = enrollment?.id;
    closeSetup();
    feedback.textContent = 'Setup cancelled.';
    heading.focus();
    if (!id) return;
    try {
      await apiPost(`${BASE}/cancel.php`, { enrollment_id: id });
    } catch {
      // The pending setup expires on its own within five minutes.
    }
  }

  async function remove() {
    const confirmed = await confirmAction({
      title: 'Remove your authenticator app?',
      text: 'Its codes stop working right away.',
      confirmLabel: 'Remove app',
      announce: null,
    });
    if (!confirmed) return;
    clear();
    setBusy(true);
    try {
      await withPrivilegedStepUp(() => apiPost(`${BASE}/remove.php`, {}));
      feedback.textContent = 'Authenticator app removed.';
      hooks.onStatus(null);
    } catch (failure) {
      error.textContent = describe(failure);
    } finally {
      setBusy(false);
    }
  }

  function closeSetup() {
    window.clearTimeout(expiryTimer);
    enrollment = null;
    // Nothing of the setup (QR, key) stays in the page.
    panel.replaceChildren();
    panel.hidden = true;
    render(current, true);
  }

  function clear() {
    feedback.textContent = '';
    error.textContent = '';
  }

  function setBusy(busy) {
    for (const control of root.querySelectorAll('button, input')) control.disabled = busy;
  }

  return { root, render };
}

/** The QR code as inline SVG built from the module grid; the URI is only encoded, never stored. */
function drawQr(uri) {
  const qr = qrcode(0, 'M');
  qr.addData(String(uri ?? ''), 'Byte');
  qr.make();
  const count = qr.getModuleCount();
  const quiet = 4;
  const size = count + quiet * 2;
  let path = '';
  for (let row = 0; row < count; row += 1) {
    for (let col = 0; col < count; col += 1) {
      if (qr.isDark(row, col)) path += `M${col + quiet} ${row + quiet}h1v1h-1z`;
    }
  }
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('class', 'totp-qr');
  svg.setAttribute('viewBox', `0 0 ${size} ${size}`);
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', 'QR code for your authenticator app');
  svg.setAttribute('shape-rendering', 'crispEdges');
  const shape = document.createElementNS(ns, 'path');
  shape.setAttribute('d', path);
  svg.append(shape);
  return svg;
}

function button(text, className, onClick) {
  const node = document.createElement('button');
  node.type = 'button';
  node.className = className;
  node.textContent = text;
  node.addEventListener('click', onClick);
  return node;
}
