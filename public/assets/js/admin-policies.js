// Operational settings → "Rules and privacy" (#160). The server assigns
// versions: the administrator only says whether a change is one members must
// agree to again (rules) or be told about (privacy). Publishing a document
// for the first time, or again after it was removed, always counts as such.
import { ApiError } from './api.js';
import { formatDateTime } from './datetime.js';
import {
  MARKDOWN_MAX_BYTES,
  fetchAdminPolicies,
  markdownBytes,
  previewMarkdown,
  saveAdminPolicy,
  saveLegalNoticeUrl,
} from './policies.js';

const saved = {};

window.addEventListener('DOMContentLoaded', () => {
  const card = document.querySelector('.policies-card');
  if (!card) return;
  for (const form of card.querySelectorAll('form[data-policy]')) wireForm(form);
  document.getElementById('legal-notice-form')?.addEventListener('submit', saveLegalNotice);
  load(card).catch(() => {});
});

async function load(card) {
  let policies;
  try {
    policies = await fetchAdminPolicies();
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 403)) {
      for (const form of card.querySelectorAll('form')) form.classList.add('hidden');
      document.getElementById('policies-unavailable').classList.remove('hidden');
    }
    return;
  }
  for (const kind of ['rules', 'privacy']) {
    saved[kind] = policies[kind];
    fill(card.querySelector(`form[data-policy="${kind}"]`), policies[kind]);
  }
  document.getElementById('legal-notice-url').value = policies.legalNoticeUrl;
}

function parts(form) {
  return {
    kind: form.dataset.policy,
    source: () => form.querySelector('input[type="radio"][name$="-source"]:checked')?.value ?? 'none',
    markdown: form.querySelector('.policy-markdown'),
    size: form.querySelector('.policy-size'),
    url: form.querySelector('.policy-url'),
    hosted: form.querySelector('.policy-hosted'),
    external: form.querySelector('.policy-external'),
    meta: form.querySelector('.policy-meta'),
    change: form.querySelector('.policy-change'),
    material: () => form.querySelector('input[name$="-material"]:checked')?.value === '1',
    summary: form.querySelector('.policy-summary'),
    summaryInput: form.querySelector('.policy-summary-input'),
    first: form.querySelector('.policy-first'),
    noneWarning: form.querySelector('.policy-none-warning'),
    preview: form.querySelector('.policy-preview'),
    previewButton: form.querySelector('[data-policy-preview]'),
    error: form.querySelector('.error-text[role="alert"]'),
    submit: form.querySelector('button[type="submit"]'),
  };
}

function wireForm(form) {
  const p = parts(form);
  form.addEventListener('change', () => sync(form));
  form.addEventListener('input', () => sync(form));
  p.previewButton.addEventListener('click', () => void togglePreview(form));
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    void save(form);
  });
}

function fill(form, document) {
  const p = parts(form);
  form.querySelector(`input[name$="-source"][value="${document.source}"]`).checked = true;
  p.markdown.value = document.markdown;
  p.url.value = document.url;
  form.querySelector('input[name$="-material"][value="0"]').checked = true;
  p.summaryInput.value = '';
  closePreview(form);
  sync(form);
}

/** Shows what applies to the current choice, and nothing else. */
function sync(form) {
  const p = parts(form);
  const before = saved[p.kind] ?? { source: 'none', version: 0, markdown: '', url: '' };
  const source = p.source();
  p.hosted.classList.toggle('hidden', source !== 'hosted');
  p.external.classList.toggle('hidden', source !== 'external');

  const publishing = source !== 'none';
  const wasPublished = before.source !== 'none';
  // First publish, or publishing again after "None": always material.
  const alwaysMaterial = publishing && !wasPublished;
  const changed = publishing && wasPublished && (
    source !== before.source
    || (source === 'hosted' && p.markdown.value !== before.markdown)
    || (source === 'external' && p.url.value.trim() !== before.url)
  );
  p.change.classList.toggle('hidden', !changed);
  p.summary.classList.toggle('hidden', !changed || !p.material());
  p.first.classList.toggle('hidden', !alwaysMaterial);
  p.noneWarning.classList.toggle('hidden', publishing || !wasPublished);

  renderSize(p);

  const path = p.meta.dataset.path;
  if (!wasPublished) {
    p.meta.textContent = 'Not published.';
  } else {
    const when = before.updatedAt ? formatDateTime(before.updatedAt, { dateStyle: 'medium', timeStyle: null }) : null;
    const where = before.source === 'hosted' ? `Shown at ${path}` : 'Links to your page';
    p.meta.textContent = `${where} · last published ${when ?? 'earlier'} (version ${before.version})`;
  }
}

async function togglePreview(form) {
  const p = parts(form);
  if (!p.preview.classList.contains('hidden')) {
    closePreview(form);
    return;
  }
  if (markdownBytes(p.markdown.value) > MARKDOWN_MAX_BYTES) {
    p.error.textContent = `The text is too long to preview: ${kilobytes(markdownBytes(p.markdown.value))} of at most ${kilobytes(MARKDOWN_MAX_BYTES)}.`;
    return;
  }
  p.previewButton.setAttribute('aria-expanded', 'true');
  p.previewButton.textContent = 'Hide preview';
  p.preview.classList.remove('hidden');
  p.preview.textContent = 'Rendering preview…';
  try {
    // Rendered and sanitised by the same server renderer as the public page.
    p.preview.innerHTML = await previewMarkdown(p.markdown.value) || '<p class="admin-muted">Nothing to preview yet.</p>';
  } catch (error) {
    p.preview.textContent = error instanceof Error ? error.message : 'The preview could not be rendered.';
  }
}

function closePreview(form) {
  const p = parts(form);
  p.preview.classList.add('hidden');
  p.preview.replaceChildren();
  p.previewButton.setAttribute('aria-expanded', 'false');
  p.previewButton.textContent = 'Preview';
}

// Shown once the text is past three quarters of the limit, so it stays quiet normally.
function renderSize(p) {
  const bytes = markdownBytes(p.markdown.value);
  const near = bytes >= MARKDOWN_MAX_BYTES * 0.75;
  p.size.classList.toggle('hidden', !near);
  p.size.textContent = near ? `${kilobytes(bytes)} of ${kilobytes(MARKDOWN_MAX_BYTES)} used.` : '';
  p.size.classList.toggle('policy-size-over', bytes > MARKDOWN_MAX_BYTES);
}

function kilobytes(bytes) {
  return `${Math.ceil(bytes / 1024)} KB`;
}

function validationError(p, source) {
  if (source === 'hosted' && p.markdown.value.trim() === '') {
    return { message: 'Write the text first, or choose None.', focus: p.markdown };
  }
  if (source === 'hosted' && markdownBytes(p.markdown.value) > MARKDOWN_MAX_BYTES) {
    return { message: `The text is too long: ${kilobytes(markdownBytes(p.markdown.value))} of at most ${kilobytes(MARKDOWN_MAX_BYTES)}. Shorten it, or link to a page you host elsewhere.`, focus: p.markdown };
  }
  if (source === 'external') {
    let url = null;
    try {
      url = new URL(p.url.value.trim());
    } catch {
      url = null;
    }
    if (!url || url.protocol !== 'https:') {
      return { message: 'Enter the full address of the page, starting with https://.', focus: p.url };
    }
  }
  return null;
}

async function save(form) {
  const p = parts(form);
  const source = p.source();
  const before = saved[p.kind] ?? { source: 'none' };
  p.error.textContent = '';
  const invalid = validationError(p, source);
  if (invalid) {
    p.error.textContent = invalid.message;
    invalid.focus.focus();
    return;
  }
  const material = source !== 'none' && (before.source === 'none' || p.material());
  p.submit.disabled = true;
  try {
    saved[p.kind] = await saveAdminPolicy(p.kind, {
      source,
      markdown: p.markdown.value,
      url: p.url.value.trim(),
      material,
      changeSummary: p.summaryInput.value.trim(),
    });
    fill(form, saved[p.kind]);
    toast(savedMessage(p.kind, source, material));
  } catch (error) {
    p.error.textContent = error instanceof Error ? error.message : 'Saving didn’t work. Try again.';
  } finally {
    p.submit.disabled = false;
  }
}

function savedMessage(kind, source, material) {
  if (source === 'none') return kind === 'rules' ? 'Community rules removed.' : 'Privacy policy removed.';
  if (kind === 'rules') return material ? 'Community rules published. Members will be asked to agree.' : 'Community rules updated.';
  return material ? 'Privacy policy published. Members will be told about it.' : 'Privacy policy updated.';
}

async function saveLegalNotice(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const input = document.getElementById('legal-notice-url');
  const error = form.querySelector('.error-text');
  const value = input.value.trim();
  error.textContent = '';
  if (value !== '') {
    let url = null;
    try {
      url = new URL(value);
    } catch {
      url = null;
    }
    if (!url || url.protocol !== 'https:') {
      error.textContent = 'Enter the full address of the page, starting with https://, or leave it empty.';
      input.focus();
      return;
    }
  }
  const button = form.querySelector('button[type="submit"]');
  button.disabled = true;
  try {
    await saveLegalNoticeUrl(value);
    toast(value ? 'Legal notice link saved.' : 'Legal notice link removed.');
  } catch (failure) {
    error.textContent = failure instanceof Error ? failure.message : 'Saving didn’t work. Try again.';
  } finally {
    button.disabled = false;
  }
}

function toast(message) {
  const region = document.getElementById('toast-region');
  if (!region) return;
  const item = document.createElement('div');
  item.className = 'toast';
  item.textContent = message;
  region.append(item);
  window.setTimeout(() => item.remove(), 5000);
}
