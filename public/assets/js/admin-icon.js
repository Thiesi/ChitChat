// Operational settings → "Installation icon" (#211). The server renders every
// size; this page only shows what it returns. Choosing a file asks for a
// preview (dry_run, nothing saved); "Use this icon" uploads the same file
// again for real. A background change on an existing icon previews live from
// the transparent 512 at the server's maskable_scale, then saves on request.
import { ApiError, apiGet, apiUpload, apiPost } from './api.js';
import { formatDateTime } from './datetime.js';
import { withPrivilegedStepUp } from './step-up.js';

const BASE = '/api/v1/admin/settings/application-icon/';
const NIGHT = '#14111C';
const PRESETS = ['#14111C', '#FBF7F1'];
const PREVIEW_DELAY_MS = 500;

const $ = (id) => document.getElementById(id);
let icon = null; // the saved description
let preview = null; // {preview, maskable_scale, background} for the chosen file
let previewSequence = 0;
let previewTimer = 0;

window.addEventListener('DOMContentLoaded', () => {
  const card = $('icon-settings');
  if (!card) return;
  const name = document.querySelector('meta[name="application-name"]')?.content || 'ChitChat';
  for (const node of card.querySelectorAll('[data-icon-name]')) node.textContent = name;

  $('icon-safe-ring').addEventListener('change', (event) => {
    $('icon-previews').classList.toggle('show-ring', event.target.checked);
  });
  $('icon-file').addEventListener('change', () => requestPreview(0));
  for (const radio of card.querySelectorAll('input[name="icon-background"]')) radio.addEventListener('change', backgroundChanged);
  $('icon-color').addEventListener('input', () => {
    $('icon-hex').value = $('icon-color').value.toUpperCase();
    selectCustom();
    backgroundChanged();
  });
  $('icon-hex').addEventListener('input', () => {
    const value = normalize($('icon-hex').value);
    if (value) $('icon-color').value = value;
    selectCustom();
    backgroundChanged();
  });
  $('icon-form').addEventListener('submit', useIcon);
  $('icon-discard').addEventListener('click', discard);
  $('icon-save-background').addEventListener('click', saveBackground);
  $('icon-reset').addEventListener('click', reset);
  load().catch(() => {});
});

async function load() {
  try {
    const response = await apiGet(`${BASE}get.php`);
    icon = response?.application_icon ?? null;
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 403)) {
      $('icon-unavailable').classList.remove('hidden');
      return;
    }
    throw error;
  }
  if (!icon || typeof icon !== 'object' || !icon.renditions) {
    $('icon-unavailable').classList.remove('hidden');
    return;
  }
  $('icon-body').classList.remove('hidden');
  showSaved();
}

/** "#abc" or "#AABBCC" (with or without #) → "#AABBCC", else null. */
export function normalize(value) {
  const raw = String(value).trim().replace(/^#/, '');
  if (/^[0-9a-f]{3}$/i.test(raw)) return `#${raw.split('').map((c) => c + c).join('')}`.toUpperCase();
  if (/^[0-9a-f]{6}$/i.test(raw)) return `#${raw}`.toUpperCase();
  return null;
}

function chosenBackground() {
  const choice = document.querySelector('input[name="icon-background"]:checked')?.value ?? NIGHT;
  return choice === 'custom' ? normalize($('icon-hex').value) : choice;
}

function selectCustom() {
  const custom = document.querySelector('input[name="icon-background"][value="custom"]');
  if (!custom.checked) custom.checked = true;
}

function setBackgroundControls(background) {
  const value = normalize(background) ?? NIGHT;
  const preset = PRESETS.includes(value) ? value : 'custom';
  document.querySelector(`input[name="icon-background"][value="${preset}"]`).checked = true;
  $('icon-color').value = value;
  $('icon-hex').value = value;
}

function setImages(sources) {
  for (const image of document.querySelectorAll('#icon-previews img[data-icon-variant]')) {
    const source = sources[image.dataset.iconVariant];
    if (source) image.src = source;
  }
}

/** Shows the saved icon, as every device gets it now. */
function showSaved() {
  preview = null;
  $('icon-file').value = '';
  const custom = icon.state === 'custom';
  const renditions = icon.renditions ?? {};
  setImages({
    maskable: renditions.maskable?.url, apple_touch: renditions.apple_touch?.url, favicon: renditions.favicon?.url,
  });
  clearLive();
  $('icon-previews').dataset.mode = 'current';
  $('icon-preview-label').textContent = custom ? 'Current icon' : 'Current icon: the ChitChat icon';
  setBackgroundControls(icon.background);

  const meta = [];
  if (custom) {
    if (icon.source_width && icon.source_height) meta.push(`From a ${icon.source_width} × ${icon.source_height} image`);
    if (icon.updated_at) {
      meta.push(`uploaded ${formatDateTime(icon.updated_at)}${icon.updated_by?.username ? ` by ${icon.updated_by.username}` : ''}`);
    }
  }
  $('icon-meta').textContent = meta.length ? `${meta.join(', ')}.` : '';

  const policy = icon.upload_policy ?? {};
  const uploads = policy.enabled !== false && policy.max_bytes !== 0;
  $('icon-file-label').classList.toggle('hidden', !uploads);
  $('icon-file-help').textContent = uploads
    ? `A square PNG or WebP image, at least ${policy.minimum_side ?? 512} × ${policy.minimum_side ?? 512} pixels`
      + `${policy.maximum_side ? ` and at most ${policy.maximum_side} × ${policy.maximum_side}` : ''}`
      + `${policy.max_bytes > 0 ? `, up to ${megabytes(policy.max_bytes)}` : ''}.`
    : 'Uploads aren’t available on this server: its PHP upload limits don’t allow them.';

  $('icon-use').classList.add('hidden');
  $('icon-discard').classList.add('hidden');
  $('icon-save-background').classList.add('hidden');
  $('icon-reset').classList.toggle('hidden', !custom);
  // Without a custom icon or uploads, there's nothing for a background to surround.
  syncBackgroundAvailability();
}

function megabytes(bytes) {
  const value = bytes / (1024 * 1024);
  return `${value >= 10 ? Math.round(value) : Math.round(value * 10) / 10} MB`;
}

function backgroundChanged() {
  $('icon-error').textContent = '';
  const background = chosenBackground();
  if (!background) return;
  if ($('icon-file').files?.length) {
    requestPreview(PREVIEW_DELAY_MS);
    return;
  }
  if (icon?.state !== 'custom') return;
  const changed = background !== normalize(icon.background);
  $('icon-save-background').classList.toggle('hidden', !changed);
  $('icon-discard').classList.toggle('hidden', !changed);
  $('icon-discard').textContent = 'Keep the current background';
  if (changed) showLive(background);
  else showSaved();
}

/** Live background preview: the transparent 512, scaled as the server will, on the chosen colour. */
function showLive(background) {
  const scale = Number(icon.maskable_scale) || 0.56;
  const art = icon.renditions?.icon_512?.url;
  for (const mask of document.querySelectorAll('#icon-previews [data-icon-mask]')) {
    mask.style.setProperty('--icon-live-background', background);
    mask.style.setProperty('--icon-live-scale', String(scale));
    mask.querySelector('img').src = art;
    mask.classList.add('is-live');
  }
  const home = document.querySelector('#icon-previews .icon-home');
  home.style.setProperty('--icon-live-background', background);
  home.querySelector('img').src = art;
  home.classList.add('is-live');
  $('icon-previews').dataset.mode = 'preview';
  $('icon-preview-label').textContent = 'Preview: not saved yet';
}

function clearLive() {
  for (const node of document.querySelectorAll('#icon-previews .is-live')) {
    node.classList.remove('is-live');
    node.style.removeProperty('--icon-live-background');
    node.style.removeProperty('--icon-live-scale');
  }
}

function formWith(file, background, dryRun) {
  const form = new FormData();
  if (file) form.append('icon', file);
  if (background) form.append('background', background);
  if (dryRun) form.append('dry_run', '1');
  return form;
}

function upload(path, makeForm) {
  return withPrivilegedStepUp(() => apiUpload(path, makeForm()));
}

function requestPreview(delay) {
  window.clearTimeout(previewTimer);
  previewTimer = window.setTimeout(runPreview, delay);
}

async function runPreview() {
  const file = $('icon-file').files?.[0];
  $('icon-error').textContent = '';
  if (!file) {
    showSaved();
    return;
  }
  const background = chosenBackground() ?? NIGHT;
  const sequence = ++previewSequence;
  $('icon-status').textContent = 'Preparing a preview…';
  try {
    const response = await upload(`${BASE}update.php`, () => formWith(file, background, true));
    if (sequence !== previewSequence) return;
    preview = response;
    clearLive();
    setImages(response.preview ?? {});
    $('icon-previews').dataset.mode = 'preview';
    $('icon-preview-label').textContent = 'Preview: not saved yet';
    $('icon-meta').textContent = '';
    $('icon-use').classList.remove('hidden');
    $('icon-discard').classList.remove('hidden');
    $('icon-discard').textContent = 'Choose another';
    $('icon-save-background').classList.add('hidden');
    $('icon-reset').classList.add('hidden');
    $('icon-status').textContent = 'Here’s how it will look. Nothing is saved until you choose “Use this icon”.';
  } catch (error) {
    if (sequence !== previewSequence) return;
    $('icon-status').textContent = '';
    showError(error);
    if (!(error instanceof ApiError && error.code === 'step_up_required')) {
      $('icon-use').classList.add('hidden');
    }
  }
}

async function useIcon(event) {
  event.preventDefault();
  const file = $('icon-file').files?.[0];
  if (!file || !preview) return;
  const background = chosenBackground() ?? NIGHT;
  await mutate(
    () => upload(`${BASE}update.php`, () => formWith(file, background, false)),
    'Icon saved. Pages and new installs use it the next time they load.',
  );
}

async function saveBackground() {
  const background = chosenBackground();
  if (!background) {
    $('icon-error').textContent = 'Enter a colour as #RRGGBB, for example #14111C.';
    return;
  }
  await mutate(() => upload(`${BASE}update.php`, () => formWith(null, background, false)), 'Background saved.');
}

async function reset() {
  if (!window.confirm('Use the ChitChat icon again? Your uploaded icon will be removed.')) return;
  await mutate(() => withPrivilegedStepUp(() => apiPost(`${BASE}reset.php`, {})), 'Back to the ChitChat icon.');
}

function discard() {
  window.clearTimeout(previewTimer);
  ++previewSequence;
  $('icon-status').textContent = '';
  $('icon-error').textContent = '';
  showSaved();
  $('icon-file').focus();
}

async function mutate(operation, success) {
  const form = $('icon-form');
  const controls = [...form.querySelectorAll('button, input, fieldset')];
  for (const control of controls) control.disabled = true;
  $('icon-error').textContent = '';
  $('icon-status').textContent = 'Saving…';
  try {
    const response = await operation();
    icon = response.application_icon;
    showSaved();
    $('icon-status').textContent = success;
  } catch (error) {
    $('icon-status').textContent = '';
    // Someone else's change wins: show it. Any other failure keeps the preview to retry.
    if (error instanceof ApiError && error.code === 'application_icon_changed') {
      const fresh = await apiGet(`${BASE}get.php`).catch(() => null);
      if (fresh) icon = fresh.application_icon;
      showSaved();
    }
    showError(error);
  } finally {
    for (const control of controls) control.disabled = false;
    syncBackgroundAvailability();
  }
}

function syncBackgroundAvailability() {
  const policy = icon?.upload_policy ?? {};
  const uploads = policy.enabled !== false && policy.max_bytes !== 0;
  document.querySelector('.icon-background').disabled = icon?.state !== 'custom' && !uploads;
}

function showError(error) {
  $('icon-error').textContent = errorText(error);
}

export function errorText(error) {
  if (!(error instanceof ApiError)) return 'That didn’t work. Check your connection and try again.';
  switch (error.code) {
    case 'attachment_too_large':
      return icon?.upload_policy?.max_bytes > 0
        ? `That image is too large. The limit is ${megabytes(icon.upload_policy.max_bytes)}.`
        : 'That image is too large for this server.';
    case 'application_icon_changed':
      return 'Someone else changed the icon just now. This is the current one; try again if you still want yours.';
    case 'application_icon_unavailable':
      return 'This server can’t process images right now. Try again later, or ask the server operator.';
    case 'rate_limited':
    case 'too_many_requests': {
      const minutes = error.retryAfterSeconds ? Math.max(1, Math.ceil(error.retryAfterSeconds / 60)) : null;
      return minutes ? `Too many tries. Try again in ${minutes} min.` : 'Too many tries. Try again later.';
    }
    default:
      if (error.status === 429) {
        const minutes = error.retryAfterSeconds ? Math.max(1, Math.ceil(error.retryAfterSeconds / 60)) : null;
        return minutes ? `Too many tries. Try again in ${minutes} min.` : 'Too many tries. Try again later.';
      }
      return error.message || 'That didn’t work.';
  }
}
