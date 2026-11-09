// Operational settings → "Installation icon" (#211). The server renders every
// size; this page only shows what it returns. Choosing a file asks for a
// preview (dry_run, nothing saved); "Use this icon" uploads the same file
// again for real, and only while the preview on screen matches the current
// file and background. A background change on an existing icon previews live
// from the transparent 512 at the server's maskable_scale, then saves on request.
import { ApiError, apiGet, apiUpload, apiPost } from './api.js';
import { formatDateTime } from './datetime.js';
import { withPrivilegedStepUp } from './step-up.js';

const BASE = '/api/v1/admin/settings/application-icon/';
const NIGHT = '#14111C';
const PRESETS = ['#14111C', '#FBF7F1'];
const PREVIEW_DELAY_MS = 500;
const INVALID_COLOUR = 'Enter a colour as #RRGGBB, for example #14111C.';

const $ = (id) => document.getElementById(id);
let icon = null; // the saved description
let preview = null; // {sequence, background}: the dry run on screen, if it's current
// Bumped by anything that makes a pending or shown preview stale: a new file, a
// new background, Choose another, or any save or reset. Late answers are ignored.
let previewSequence = 0;
let previewTimer = 0;
let busy = false;

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
  $('icon-retry').addEventListener('click', () => load());
  load();
});

async function load() {
  $('icon-load-error').classList.add('hidden');
  $('icon-unavailable').classList.add('hidden');
  try {
    const response = await apiGet(`${BASE}get.php`);
    icon = response?.application_icon ?? null;
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 403)) {
      $('icon-unavailable').classList.remove('hidden');
    } else {
      // A network failure or server error: say so, and offer to try again.
      $('icon-load-error').classList.remove('hidden');
      $('icon-retry').focus({ preventScroll: true });
    }
    return;
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

/** The chosen background, or null when the custom field doesn't hold a colour. */
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
  $('icon-hex').removeAttribute('aria-invalid');
}

function setImages(sources) {
  for (const image of document.querySelectorAll('#icon-previews img[data-icon-variant]')) {
    const source = sources[image.dataset.iconVariant];
    if (source) image.src = source;
  }
}

function uploadsAllowed() {
  const policy = icon?.upload_policy ?? {};
  return policy.enabled !== false && policy.max_bytes !== 0;
}

/** Shows the saved icon, as every device gets it now, and forgets any preview. */
function showSaved() {
  invalidatePreview();
  $('icon-file').value = '';
  const custom = icon.state === 'custom';
  const renditions = icon.renditions ?? {};
  clearLive();
  setImages({
    maskable: renditions.maskable?.url, apple_touch: renditions.apple_touch?.url, favicon: renditions.favicon?.url,
  });
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
  const uploads = uploadsAllowed();
  $('icon-file-label').classList.toggle('hidden', !uploads);
  $('icon-file-help').textContent = uploads
    ? `A square PNG or WebP image, at least ${policy.minimum_side ?? 512} × ${policy.minimum_side ?? 512} pixels`
      + `${policy.maximum_side ? ` and at most ${policy.maximum_side} × ${policy.maximum_side}` : ''}`
      + `${policy.max_bytes > 0 ? `, up to ${megabytes(policy.max_bytes)}` : ''}.`
    : policy.max_bytes === 0
      ? 'Uploads aren’t available on this server: its PHP upload limits don’t allow them.'
      : 'Uploads aren’t available on this server right now. Ask the server operator.';

  $('icon-discard').classList.add('hidden');
  $('icon-save-background').classList.add('hidden');
  $('icon-reset').classList.toggle('hidden', !custom);
  syncControls();
}

function megabytes(bytes) {
  const value = bytes / (1024 * 1024);
  return `${value >= 10 ? Math.round(value) : Math.round(value * 10) / 10} MB`;
}

/** Makes any pending or shown preview stale, so it can't be saved. */
function invalidatePreview() {
  window.clearTimeout(previewTimer);
  ++previewSequence;
  preview = null;
  syncControls();
}

/** "Use this icon" only for the preview on screen; the background only when it can apply. */
function syncControls() {
  const file = $('icon-file').files?.length > 0;
  const current = preview !== null && preview.sequence === previewSequence;
  $('icon-use').classList.toggle('hidden', !file);
  $('icon-use').disabled = busy || !current;
  $('icon-save-background').disabled = busy || chosenBackground() === null;
  document.querySelector('.icon-background').disabled = busy || (icon?.state !== 'custom' && !uploadsAllowed());
  for (const id of ['icon-file', 'icon-discard', 'icon-reset']) $(id).disabled = busy;
}

function backgroundChanged() {
  $('icon-error').textContent = '';
  const background = chosenBackground();
  $('icon-hex').toggleAttribute('aria-invalid', background === null);
  if ($('icon-file').files?.length) {
    invalidatePreview();
    if (background === null) {
      $('icon-status').textContent = '';
      $('icon-error').textContent = INVALID_COLOUR;
      return;
    }
    requestPreview(PREVIEW_DELAY_MS);
    return;
  }
  if (icon?.state !== 'custom') {
    if (background === null) $('icon-error').textContent = INVALID_COLOUR;
    syncControls();
    return;
  }
  if (background === null) {
    $('icon-error').textContent = INVALID_COLOUR;
    syncControls();
    return;
  }
  const changed = background !== normalize(icon.background);
  if (!changed) {
    showSaved();
    return;
  }
  $('icon-save-background').classList.remove('hidden');
  $('icon-discard').classList.remove('hidden');
  $('icon-discard').textContent = 'Keep the current background';
  showLive(background);
  syncControls();
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
  form.append('background', background);
  if (dryRun) form.append('dry_run', '1');
  return form;
}

function upload(path, makeForm) {
  return withPrivilegedStepUp(() => apiUpload(path, makeForm()));
}

function requestPreview(delay) {
  invalidatePreview();
  $('icon-error').textContent = '';
  if (!$('icon-file').files?.length) {
    showSaved();
    return;
  }
  const background = chosenBackground();
  if (background === null) {
    $('icon-error').textContent = INVALID_COLOUR;
    return;
  }
  const sequence = previewSequence;
  $('icon-status').textContent = 'Preparing a preview…';
  previewTimer = window.setTimeout(() => runPreview(sequence, background), delay);
}

async function runPreview(sequence, background) {
  const file = $('icon-file').files?.[0];
  if (!file || sequence !== previewSequence) return;
  try {
    const response = await upload(`${BASE}update.php`, () => formWith(file, background, true));
    if (sequence !== previewSequence) return;
    preview = { sequence, background };
    clearLive();
    setImages(response.preview ?? {});
    $('icon-previews').dataset.mode = 'preview';
    $('icon-preview-label').textContent = 'Preview: not saved yet';
    $('icon-meta').textContent = '';
    $('icon-discard').classList.remove('hidden');
    $('icon-discard').textContent = 'Choose another';
    $('icon-save-background').classList.add('hidden');
    $('icon-reset').classList.add('hidden');
    $('icon-status').textContent = 'Here’s how it will look. Nothing is saved until you choose “Use this icon”.';
    syncControls();
  } catch (error) {
    if (sequence !== previewSequence) return;
    $('icon-status').textContent = '';
    showError(error);
    syncControls();
  }
}

async function useIcon(event) {
  event.preventDefault();
  const file = $('icon-file').files?.[0];
  // Only the preview on screen, for exactly this file and background, can be saved.
  if (!file || preview === null || preview.sequence !== previewSequence || preview.background !== chosenBackground()) return;
  const { background } = preview;
  await mutate(
    () => upload(`${BASE}update.php`, () => formWith(file, background, false)),
    'Icon saved. Pages and new installs use it the next time they load.',
  );
}

async function saveBackground() {
  const background = chosenBackground();
  if (!background) {
    $('icon-error').textContent = INVALID_COLOUR;
    return;
  }
  await mutate(() => upload(`${BASE}update.php`, () => formWith(null, background, false)), 'Background saved.');
}

async function reset() {
  if (!window.confirm('Use the ChitChat icon again? Your uploaded icon will be removed.')) return;
  await mutate(() => withPrivilegedStepUp(() => apiPost(`${BASE}reset.php`, {})), 'Back to the ChitChat icon.');
}

function discard() {
  $('icon-status').textContent = '';
  $('icon-error').textContent = '';
  showSaved();
  $('icon-file').focus();
}

async function mutate(operation, success) {
  // Any preview still on its way describes a state this save replaces.
  const kept = preview;
  window.clearTimeout(previewTimer);
  ++previewSequence;
  busy = true;
  syncControls();
  $('icon-error').textContent = '';
  $('icon-status').textContent = 'Saving…';
  try {
    const response = await operation();
    icon = response.application_icon;
    busy = false;
    showSaved();
    $('icon-status').textContent = success;
  } catch (error) {
    busy = false;
    $('icon-status').textContent = '';
    // Someone else's change wins: show it. Any other failure keeps the choices to retry.
    if (error instanceof ApiError && error.code === 'application_icon_changed') {
      const fresh = await apiGet(`${BASE}get.php`).catch(() => null);
      if (fresh?.application_icon) icon = fresh.application_icon;
      showSaved();
    } else if (kept) {
      // Nothing changed on screen, so the same preview can be saved again.
      preview = { ...kept, sequence: previewSequence };
    }
    showError(error);
  } finally {
    busy = false;
    syncControls();
  }
}

function showError(error) {
  $('icon-error').textContent = errorText(error);
}

export function errorText(error) {
  if (!(error instanceof ApiError)) return 'That didn’t work. Check your connection and try again.';
  const tooMany = () => {
    const minutes = error.retryAfterSeconds ? Math.max(1, Math.ceil(error.retryAfterSeconds / 60)) : null;
    return minutes ? `Too many tries. Try again in ${minutes} min.` : 'Too many tries. Try again later.';
  };
  switch (error.code) {
    case 'attachment_too_large':
      return icon?.upload_policy?.max_bytes > 0
        ? `That image is too large. The limit is ${megabytes(icon.upload_policy.max_bytes)}.`
        : 'That image is too large for this server.';
    case 'application_icon_changed':
      return 'Someone else changed the icon just now. This is the current one; try again if you still want yours.';
    case 'application_icon_unavailable':
      return 'This server can’t process images right now. Try again later, or ask the server operator.';
    default:
      if (error.status === 429) return tooMany();
      return error.message || 'That didn’t work.';
  }
}
