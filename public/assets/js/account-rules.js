// Account → "Rules and privacy" (#160): when you agreed, links to both
// documents, and a way to agree after a change.
import { apiGet } from './api.js';
import { formatDateTime } from './datetime.js';
import { readPolicies, readRulesStatus } from './policies.js';
import { startRulesGate } from './rules.js';

let policies = null;
let status = null;

window.addEventListener('DOMContentLoaded', async () => {
  const card = document.getElementById('rules-card');
  if (!card) return;
  let session;
  try {
    session = await apiGet('/api/v1/session.php');
  } catch {
    return;
  }
  if (!session.user || session.user.guest) return;
  policies = readPolicies(session);
  status = readRulesStatus(session.user);
  render();
  startRulesGate(session.user);
});

window.addEventListener('chitchat:rules-accepted', (event) => {
  if (!status) return;
  status = { ...status, required: false, acceptedVersion: event.detail?.version ?? status.acceptedVersion, acceptedAt: new Date().toISOString() };
  render();
});

function render() {
  const card = document.getElementById('rules-card');
  const { rules, privacy } = policies;
  card.classList.toggle('hidden', !rules && !privacy);
  if (!rules && !privacy) return;

  const line = document.getElementById('rules-status');
  if (!rules) {
    line.textContent = 'This installation publishes a privacy policy.';
  } else if (status.required) {
    line.textContent = status.acceptedVersion === null
      ? 'Please agree to the community rules to keep writing.'
      : 'The community rules have changed since you agreed.';
  } else if (status.acceptedAt) {
    line.textContent = `You agreed to the community rules on ${formatDateTime(status.acceptedAt, { dateStyle: 'long', timeStyle: null })}.`;
  } else {
    line.textContent = 'You agreed to the community rules.';
  }
  document.getElementById('rules-pending').classList.toggle('hidden', !rules || !status.required);

  link('rules-card-rules', rules?.url);
  link('rules-card-privacy', privacy?.url);
  document.getElementById('rules-card-separator').classList.toggle('hidden', !rules || !privacy);
}

function link(id, url) {
  const node = document.getElementById(id);
  node.classList.toggle('hidden', !url);
  if (url) node.href = url;
}
