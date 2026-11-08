// Community rules in the browser (#160): agreeing when an account or guest
// visit starts, and pausing writing (never reading) after a material change
// until the person agrees again. Server shapes live in policies.js.
import { ApiError, apiGet } from './api.js';
import {
  RULES_ACCEPTANCE_REQUIRED,
  RULES_VERSION_OUTDATED,
  acceptRules,
  acceptanceField,
  fetchRules,
  readPolicies,
  readRulesStatus,
} from './policies.js';

const DIALOG_SHOWN_KEY = 'chitchat-rules-dialog-shown';

let policies = { rules: null, privacy: null, legalNotice: null };
let signedIn = null;
let gated = false;
let dialog = null;

// ---- Signed out: registration and guest visits ----

/** Shows or hides the registration agreement and the guest note for this installation's documents. */
export function renderSignedOutAgreements(session) {
  policies = readPolicies(session);
  const block = document.getElementById('register-agreement');
  const checkbox = document.getElementById('register-rules-accept');
  if (block && checkbox) {
    const rules = policies.rules;
    block.classList.toggle('hidden', !rules && !policies.privacy);
    document.getElementById('register-rules-field')?.classList.toggle('hidden', !rules);
    checkbox.required = Boolean(rules);
    checkbox.checked = false;
    checkbox.setCustomValidity('');
    setLink('register-rules-link', rules?.url);
    setLink('register-privacy-link', policies.privacy?.url);
    document.getElementById('register-links-separator')?.classList.toggle('hidden', !rules || !policies.privacy);
    document.getElementById('register-privacy-only')?.classList.toggle('hidden', Boolean(rules) || !policies.privacy);
    setLink('register-privacy-only-link', policies.privacy?.url);
  }
  for (const note of document.querySelectorAll('[data-guest-rules]')) {
    note.classList.toggle('hidden', !policies.rules);
    const link = note.querySelector('a');
    if (link && policies.rules) link.href = policies.rules.url;
  }
}

function setLink(id, url) {
  const link = document.getElementById(id);
  if (!link) return;
  link.classList.toggle('hidden', !url);
  if (url) link.href = url;
}

/**
 * The agreement fields for register.php, OIDC sign-up and guest start.
 * Throws a readable error when the rules exist and the box isn't ticked.
 */
export function registrationAgreement() {
  if (!policies.rules) return {};
  const checkbox = document.getElementById('register-rules-accept');
  if (checkbox && !checkbox.checked) {
    checkbox.focus();
    throw new Error('Please agree to the community rules to create your account.');
  }
  return acceptanceField(policies.rules.version);
}

/** A guest visit agrees by pressing "Look around as a guest" next to the note. */
export function guestAgreement() {
  return policies.rules ? acceptanceField(policies.rules.version) : {};
}

/**
 * Turns a refused registration or guest start into plain words when the
 * rules were the reason. Refreshes the rules so the next attempt is current.
 * Returns null for any other error.
 */
export async function agreementErrorMessage(error, { guest = false } = {}) {
  if (!(error instanceof ApiError)) return null;
  if (error.code !== RULES_VERSION_OUTDATED && error.code !== RULES_ACCEPTANCE_REQUIRED) return null;
  try {
    renderSignedOutAgreements(await apiGet('/api/v1/session.php'));
  } catch {
    // Keep the old links; the next attempt reports again.
  }
  // The form is still busy (disabled) here; focus once it is enabled again.
  if (!guest) window.setTimeout(() => document.getElementById('register-rules-accept')?.focus(), 0);
  if (guest) return 'The community rules were just updated. Have a look before you continue.';
  return error.code === RULES_VERSION_OUTDATED
    ? 'The community rules were just updated. Please read them again before creating your account.'
    : 'Please agree to the community rules to create your account.';
}

window.addEventListener('DOMContentLoaded', () => {
  const checkbox = document.getElementById('register-rules-accept');
  checkbox?.addEventListener('invalid', () => {
    checkbox.setCustomValidity(checkbox.checked ? '' : 'Please agree to the community rules to create your account.');
  });
  checkbox?.addEventListener('change', () => checkbox.setCustomValidity(''));
});

// ---- Signed in: pausing writes after a material change ----

/**
 * Starts the writing pause for a signed-in member or guest whose accepted
 * version is behind. The dialog opens once per browser session; the banner
 * and the composer notice stay until they agree.
 */
export function startRulesGate(user) {
  signedIn = user ?? null;
  const status = readRulesStatus(user);
  setGated(status.required);
  if (status.required && !dialogShownThisSession()) void openRulesDialog();
}

export function stopRulesGate() {
  signedIn = null;
  setGated(false);
  dialog?.close();
}

window.addEventListener('chitchat:rules-required', () => {
  if (!signedIn) return;
  setGated(true);
  void openRulesDialog();
});

document.addEventListener('click', (event) => {
  if (event.target instanceof Element && event.target.closest('[data-rules-review]')) {
    event.preventDefault();
    void openRulesDialog();
  }
});

function setGated(value) {
  gated = value;
  document.documentElement.classList.toggle('rules-gated', value);
  for (const node of document.querySelectorAll('[data-rules-gate]')) node.classList.toggle('hidden', !value);
}

function dialogShownThisSession() {
  try {
    return window.sessionStorage.getItem(DIALOG_SHOWN_KEY) === '1';
  } catch {
    return false;
  }
}

function rememberDialogShown() {
  try {
    window.sessionStorage.setItem(DIALOG_SHOWN_KEY, '1');
  } catch {
    // Without storage it may open again on the next page; harmless.
  }
}

function buildDialog() {
  const root = document.createElement('dialog');
  root.className = 'room-dialog rules-dialog';
  root.setAttribute('aria-labelledby', 'rules-dialog-title');
  root.innerHTML = `
    <div class="form-stack">
      <header class="dialog-header"><h2 id="rules-dialog-title" tabindex="-1"></h2></header>
      <p class="rules-change-summary hidden"></p>
      <p class="rules-note hidden" role="status"></p>
      <div class="rules-body" tabindex="0" aria-label="Community rules"></div>
      <p class="rules-external hidden"><a target="_blank" rel="noopener">Read the community rules<span class="visually-hidden"> (opens another site)</span><span aria-hidden="true"> ↗</span></a></p>
      <p class="rules-explanation">Agree to keep writing. You can still read either way.</p>
      <p class="error-text" role="alert"></p>
      <div class="action-row">
        <button class="secondary-button" type="button" data-rules-later>Not now</button>
        <button class="primary-button" type="button" data-rules-accept>I agree</button>
      </div>
      <p class="rules-leave account-muted hidden">Don't agree? You can <a href="/account.php#account-closure-heading">close your account</a> in Account.</p>
    </div>`;
  root.querySelector('[data-rules-later]').addEventListener('click', () => root.close());
  root.querySelector('[data-rules-accept]').addEventListener('click', () => void agree());
  document.body.append(root);
  return root;
}

let current = null;

async function openRulesDialog() {
  dialog ??= buildDialog();
  rememberDialogShown();
  const title = dialog.querySelector('#rules-dialog-title');
  const firstTime = readRulesStatus(signedIn).acceptedVersion === null;
  title.textContent = firstTime ? 'Please agree to the community rules' : 'The community rules have changed';
  dialog.querySelector('.rules-leave').classList.toggle('hidden', signedIn?.guest === true);
  if (!dialog.open) dialog.showModal();
  title.focus();
  await loadRules();
}

async function loadRules({ updatedAgain = false } = {}) {
  const body = dialog.querySelector('.rules-body');
  const external = dialog.querySelector('.rules-external');
  const summary = dialog.querySelector('.rules-change-summary');
  const note = dialog.querySelector('.rules-note');
  const accept = dialog.querySelector('[data-rules-accept]');
  const error = dialog.querySelector('.error-text');
  error.textContent = '';
  accept.disabled = true;
  body.classList.remove('hidden');
  body.replaceChildren(Object.assign(document.createElement('p'), {
    className: 'account-muted',
    textContent: 'Loading the community rules…',
  }));
  external.classList.add('hidden');
  note.classList.toggle('hidden', !updatedAgain);
  note.textContent = updatedAgain ? 'The rules were updated again just now.' : '';
  try {
    current = await fetchRules();
  } catch {
    current = null;
    body.replaceChildren(Object.assign(document.createElement('p'), {
      textContent: 'The rules couldn’t be loaded. Close this and try again in a moment.',
    }));
    return;
  }
  summary.classList.toggle('hidden', !current.changeSummary);
  summary.textContent = current.changeSummary ? `What changed: “${current.changeSummary}”` : '';
  if (current.source === 'external' || !current.html) {
    body.classList.add('hidden');
    external.classList.remove('hidden');
    external.querySelector('a').href = current.url ?? policies.rules?.url ?? '#';
  } else {
    // Server-rendered with raw HTML escaped and unsafe links dropped (#160).
    body.innerHTML = current.html;
    for (const link of body.querySelectorAll('a[href]')) {
      link.target = '_blank';
      link.rel = 'noopener';
    }
  }
  accept.disabled = !current.version;
}

async function agree() {
  if (!current?.version) return;
  const accept = dialog.querySelector('[data-rules-accept]');
  const error = dialog.querySelector('.error-text');
  accept.disabled = true;
  error.textContent = '';
  try {
    await acceptRules(current.version);
    if (signedIn) {
      signedIn = { ...signedIn, rules: { ...(signedIn.rules ?? {}), accepted_version: current.version, acceptance_required: false } };
    }
    setGated(false);
    dialog.close();
    announce('Thanks. You can write again.');
    window.dispatchEvent(new CustomEvent('chitchat:rules-accepted', { detail: { version: current.version } }));
  } catch (failure) {
    if (failure instanceof ApiError && failure.code === RULES_VERSION_OUTDATED) {
      await loadRules({ updatedAgain: true });
      return;
    }
    error.textContent = failure instanceof Error ? failure.message : 'That didn’t work. Try again.';
    accept.disabled = false;
  }
}

function announce(message) {
  let region = document.getElementById('rules-announcer');
  if (!region) {
    region = document.createElement('div');
    region.id = 'rules-announcer';
    region.className = 'visually-hidden';
    region.setAttribute('role', 'status');
    document.body.append(region);
  }
  region.textContent = '';
  window.setTimeout(() => { region.textContent = message; }, 50);
}

/** Whether writing is paused right now (for features that want to skip a doomed request). */
export function writingPaused() {
  return gated;
}
