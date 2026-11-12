// The sign-in page's seasonal decoration (#242; docs/api/system-settings.md),
// chosen by hand by a Super-Administrator. The preview draws the same
// decoration the sign-in page will (sign-in-season.js) around a small lockup;
// nothing is saved until "Save decoration", which needs a fresh step-up.

import { apiGet, apiPost } from './api.js';
import { renderSeason } from './sign-in-season.js';
import { withPrivilegedStepUp } from './step-up.js';

const BASE = '/api/v1/admin/settings/sign-in-decoration/';
const LABELS = {
  off: 'None',
  winter: 'Winter: snowflakes',
  spring: 'Spring: blossoms',
  celebration: 'Celebration: confetti',
};

const $ = (id) => document.getElementById(id);
let saved = 'off';
let busy = false;

window.addEventListener('DOMContentLoaded', () => {
  const card = $('decoration-settings');
  if (!card) return;
  $('decoration-select').addEventListener('change', () => {
    $('decoration-status').textContent = $('decoration-select').value === saved ? '' : 'Not saved yet.';
    $('decoration-error').textContent = '';
    preview();
    sync();
  });
  $('decoration-save').addEventListener('click', save);
  void load();
});

async function load() {
  try {
    render((await apiGet(`${BASE}get.php`)).sign_in_decoration);
    $('decoration-settings').hidden = false;
  } catch {
    // Not a Super-Administrator, or an older server: the card stays away.
  }
}

function render(decoration) {
  const select = $('decoration-select');
  const options = Array.isArray(decoration?.options) && decoration.options.length ? decoration.options : Object.keys(LABELS);
  select.replaceChildren(...options.map((value) => new Option(LABELS[value] ?? value, value)));
  saved = options.includes(decoration?.value) ? decoration.value : 'off';
  select.value = saved;
  preview();
  sync();
}

function preview() {
  const value = $('decoration-select').value;
  renderSeason(value === 'off' ? null : value, document.querySelector('[data-decoration-layer]'));
}

function sync() {
  $('decoration-select').disabled = busy;
  $('decoration-save').disabled = busy || $('decoration-select').value === saved;
}

async function save() {
  const value = $('decoration-select').value;
  busy = true;
  sync();
  $('decoration-error').textContent = '';
  $('decoration-status').textContent = 'Saving…';
  try {
    const response = await withPrivilegedStepUp(() => apiPost(`${BASE}update.php`, { sign_in_decoration: value }));
    render(response.sign_in_decoration);
    $('decoration-status').textContent = saved === 'off'
      ? 'Saved. The sign-in page has no decoration.'
      : `Saved. The sign-in page shows the ${(LABELS[saved] ?? saved).split(':')[0].toLowerCase()} decoration from its next load.`;
  } catch (error) {
    // Cancelling the step-up isn't a failure: the choice simply wasn't saved.
    const cancelled = error?.code === 'step_up_cancelled';
    $('decoration-status').textContent = cancelled ? 'Not saved.' : '';
    $('decoration-error').textContent = cancelled ? '' : 'That didn’t save. Try again.';
  } finally {
    busy = false;
    sync();
  }
}
