// Where "Report an accessibility problem" leads (#219; docs/api/system-settings.md), chosen by a
// Super-Administrator on Operational settings. Three choices map onto the endpoint's body:
//   the project's form → {enabled: true, url: null}
//   my own address     → {enabled: true, url}
//   don't show a link  → {enabled: false, url: <the saved address, kept>}
// The server validates the address; its plain message is shown under the field and tied to it.
// Saving needs a fresh step-up, like every Super-Administrator setting.

import { apiGet, apiPost } from './api.js';
import { feedbackDestination } from './accessibility-feedback.js';
import { withPrivilegedStepUp } from './step-up.js';

const BASE = '/api/v1/admin/settings/accessibility-feedback/';
const $ = (id) => document.getElementById(id);

let saved = null;
let busy = false;

window.addEventListener('DOMContentLoaded', () => {
  const form = $('accessibility-feedback-settings');
  if (!form) return;
  for (const radio of choices()) radio.addEventListener('change', changed);
  $('feedback-url').addEventListener('input', changed);
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    void save();
  });
  void load();
});

function choices() {
  return [...document.querySelectorAll('input[name="feedback-choice"]')];
}

function choice() {
  return choices().find((radio) => radio.checked)?.value ?? 'project';
}

function savedChoice() {
  if (!saved?.enabled) return 'off';
  return saved.url === null ? 'project' : 'own';
}

async function load() {
  try {
    render((await apiGet(`${BASE}get.php`)).accessibility_feedback);
    $('accessibility-feedback-settings').hidden = false;
  } catch {
    // Not a Super-Administrator, or an older server: the card stays away.
  }
}

function render(feedback) {
  saved = {
    enabled: feedback?.enabled !== false,
    url: typeof feedback?.url === 'string' && feedback.url !== '' ? feedback.url : null,
    effective: typeof feedback?.effective_url === 'string' ? feedback.effective_url : null,
  };
  const current = savedChoice();
  for (const radio of choices()) radio.checked = radio.value === current;
  $('feedback-url').value = saved.url ?? '';
  $('feedback-current').textContent = describe(saved.effective);
  clearError();
  sync();
}

/** What members see now, in words. */
function describe(url) {
  const destination = feedbackDestination(url);
  if (!destination) return 'Now: no link is shown.';
  if (destination.where === 'GitHub' && saved.url === null) return 'Now: the link opens the ChitChat project’s form on GitHub.';
  if (destination.where === 'email') return `Now: the link writes an email to ${url.slice('mailto:'.length).split('?')[0]}.`;
  return `Now: the link opens ${url}`;
}

/** The body the endpoint expects for the current choice, or null when the address is missing. */
function body() {
  const value = choice();
  if (value === 'project') return { enabled: true, url: null };
  if (value === 'off') return { enabled: false, url: saved?.url ?? null };
  const url = $('feedback-url').value.trim();
  return url === '' ? null : { enabled: true, url };
}

function dirty() {
  const value = choice();
  if (value !== savedChoice()) return true;
  return value === 'own' && $('feedback-url').value.trim() !== (saved?.url ?? '');
}

function changed() {
  clearError();
  $('feedback-status').textContent = dirty() ? 'Not saved yet.' : '';
  sync();
}

function sync() {
  const own = choice() === 'own';
  $('feedback-own').hidden = !own;
  for (const radio of choices()) radio.disabled = busy;
  $('feedback-url').disabled = busy;
  $('feedback-save').disabled = busy || !dirty();
}

function showError(message) {
  $('feedback-error').textContent = message;
  if (choice() === 'own') $('feedback-url').setAttribute('aria-invalid', 'true');
}

/** After an error about the address, the field gets focus (once it's enabled again). */
function focusAddress() {
  if (choice() === 'own') $('feedback-url').focus();
}

function clearError() {
  $('feedback-error').textContent = '';
  $('feedback-url').removeAttribute('aria-invalid');
}

async function save() {
  if (busy || !dirty()) return;
  const payload = body();
  if (payload === null) {
    showError('Enter an address that starts with https:// or mailto:.');
    focusAddress();
    return;
  }
  busy = true;
  sync();
  clearError();
  $('feedback-status').textContent = 'Saving…';
  let failed = false;
  try {
    const response = await withPrivilegedStepUp(() => apiPost(`${BASE}update.php`, payload));
    render(response.accessibility_feedback);
    $('feedback-status').textContent = saved.enabled
      ? 'Saved. The link leads there from the next page load.'
      : 'Saved. No link is shown from the next page load.';
  } catch (error) {
    // Cancelling the step-up isn't a failure: the choice simply wasn't saved.
    if (error?.code === 'step_up_cancelled') {
      $('feedback-status').textContent = 'Not saved.';
    } else {
      $('feedback-status').textContent = '';
      failed = true;
      showError(error?.code === 'validation_error' && error.message ? error.message : 'That didn’t save. Try again.');
    }
  } finally {
    busy = false;
    sync();
    if (failed) focusAddress();
  }
}
