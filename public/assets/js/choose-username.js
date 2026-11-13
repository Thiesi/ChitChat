// Choosing a new username after a moderator required one (#164;
// docs/api/username-change.md). Signed-out people and guests go back to the
// start; an account with nothing to change is told so. On success the
// conversation opens with the new name.

import { ApiError, apiGet, apiPost, isPageLeft, setCsrfToken } from './api.js';

const $ = (id) => document.getElementById(id);
const PATTERN = /^[A-Za-z0-9][A-Za-z0-9._-]{2,31}$/;
const RULE = 'Use 3–32 letters, digits, dots, underscores or hyphens, starting with a letter or digit.';

document.addEventListener('DOMContentLoaded', () => {
  $('choose-username-form').addEventListener('submit', submit);
  $('choose-username-input').addEventListener('input', () => showError(''));
  void load();
});

async function load() {
  let session;
  try {
    session = await apiGet('/api/v1/session.php');
  } catch {
    $('choose-username-loading').textContent = 'This page couldn’t load. Reload to try again.';
    return;
  }
  setCsrfToken(session.csrf_token);
  const user = session.user;
  if (!user || user.guest) {
    window.location.replace('/');
    return;
  }
  $('choose-username-loading').classList.add('hidden');
  if (user.username_change?.required !== true) {
    showDone(`Nothing to change: your username is ${user.username}.`, 'Your username is fine');
    return;
  }
  const reason = user.username_change.reason;
  $('choose-username-reason').textContent = typeof reason === 'string' && reason ? `The moderator’s reason: ${reason}` : '';
  $('choose-username-reason').classList.toggle('hidden', !reason);
  $('choose-username-current').textContent = `Until then you’re shown as ${user.username}. You can sign in with that name or your previous one.`;
  $('choose-username-required').classList.remove('hidden');
  $('choose-username-input').focus();
}

async function submit(event) {
  event.preventDefault();
  const input = $('choose-username-input');
  const name = input.value.trim();
  if (!PATTERN.test(name)) {
    showError(RULE);
    input.focus();
    return;
  }
  setBusy(true);
  try {
    const response = await apiPost('/api/v1/account/username.php', { username: name });
    window.location.assign('/');
    $('choose-username-required').classList.add('hidden');
    showDone(`You’re ${response.user?.username ?? name} now.`, 'Username chosen');
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      window.location.replace('/');
      return;
    }
    if (error instanceof ApiError && error.code === 'username_change_not_required') {
      $('choose-username-required').classList.add('hidden');
      showDone('Nothing to change: your username is already set.', 'Your username is fine');
      return;
    }
    showError(message(error));
    input.focus();
  } finally {
    setBusy(false);
  }
}

function message(error) {
  // A request the page aborted on leaving clears the line rather than showing a fallback (#270).
  if (isPageLeft(error)) return '';
  if (!(error instanceof ApiError)) return 'That didn’t work. Check your connection and try again.';
  switch (error.code) {
    case 'invalid_username':
    case 'validation_error':
      return RULE;
    case 'username_taken':
      return 'That name isn’t available. Try another.';
    case 'rate_limited':
      return 'Too many tries for now. Wait a little, then try again.';
    default:
      return error.message || 'That didn’t work. Try again.';
  }
}

function showError(text) {
  const error = $('choose-username-error');
  error.textContent = text;
  error.classList.toggle('hidden', !text);
  $('choose-username-input').setAttribute('aria-invalid', String(Boolean(text)));
}

function showDone(text, heading) {
  $('choose-username-title').textContent = heading;
  $('choose-username-done-text').textContent = text;
  $('choose-username-done').classList.remove('hidden');
  $('choose-username-title').focus();
}

function setBusy(busy) {
  $('choose-username-submit').disabled = busy;
  $('choose-username-input').readOnly = busy;
}
