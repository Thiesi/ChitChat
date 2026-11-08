// The one place that knows how the server describes community rules and the
// privacy policy (#160). Everything else works with the normalised shapes
// returned here, so a renamed field or endpoint changes only this file.
import { apiGet, apiPost } from './api.js';

export const RULES_ACCEPTANCE_REQUIRED = 'rules_acceptance_required';
export const RULES_VERSION_OUTDATED = 'rules_version_outdated';
export const POLICY_NOT_FOUND = 'policy_document_not_found';

const SHOW_ENDPOINT = '/api/v1/policies/show.php';
const ACCEPT_ENDPOINT = '/api/v1/policies/accept.php';
const ADMIN_ENDPOINT = '/api/v1/admin/policies.php';
const ADMIN_UPDATE_ENDPOINT = '/api/v1/admin/policies/update.php';
const ADMIN_PREVIEW_ENDPOINT = '/api/v1/admin/policies/preview.php';
const ACCEPTED_VERSION_FIELD = 'accepted_rules_version';

/** The server's limit for a hosted document, in UTF-8 bytes (not characters). */
export const MARKDOWN_MAX_BYTES = 65536;
export const CHANGE_SUMMARY_MAX_CHARS = 200;

export function markdownBytes(text) {
  return new TextEncoder().encode(text).length;
}

function positiveInteger(value) {
  return Number.isInteger(value) && value > 0 ? value : null;
}

function text(value) {
  return typeof value === 'string' && value !== '' ? value : null;
}

/**
 * The installation's public documents, from the session bootstrap.
 * Each is null when the installation has not configured it.
 */
export function readPolicies(session) {
  const policies = session?.policies ?? {};
  const rules = policies.rules ?? null;
  const privacy = policies.privacy ?? null;
  const legal = policies.legal_notice ?? null;
  return {
    rules: rules && text(rules.url) && positiveInteger(rules.version)
      ? { url: rules.url, version: rules.version, updatedAt: text(rules.updated_at) }
      : null,
    privacy: privacy && text(privacy.url) ? { url: privacy.url, updatedAt: text(privacy.updated_at) } : null,
    legalNotice: legal && text(legal.url) ? { url: legal.url } : null,
  };
}

/** Where the signed-in account (or guest) stands with the current rules. */
export function readRulesStatus(user) {
  const rules = user?.rules ?? null;
  return {
    acceptedVersion: positiveInteger(rules?.accepted_version),
    acceptedAt: text(rules?.accepted_at),
    required: rules?.acceptance_required === true,
  };
}

/** The request field that records agreement when an account or guest visit is created. */
export function acceptanceField(version) {
  return positiveInteger(version) ? { [ACCEPTED_VERSION_FIELD]: version } : {};
}

/** The current rules for the re-acceptance dialog. Hosted rules carry server-rendered, sanitised HTML. */
export async function fetchRules() {
  const response = await apiGet(`${SHOW_ENDPOINT}?document=rules`);
  const document = response.document ?? response;
  return {
    source: document.source === 'external' ? 'external' : 'hosted',
    url: text(document.url),
    html: document.source === 'external' ? null : text(document.html),
    version: positiveInteger(document.version),
    updatedAt: text(document.updated_at),
    changeSummary: text(document.change_summary),
  };
}

export function acceptRules(version) {
  return apiPost(ACCEPT_ENDPOINT, { version });
}

// ---- Administration ----

function readAdminDocument(document) {
  const source = ['hosted', 'external'].includes(document?.source) ? document.source : 'none';
  return {
    source,
    markdown: typeof document?.markdown === 'string' ? document.markdown : '',
    url: typeof document?.url === 'string' ? document.url : '',
    version: Number.isInteger(document?.version) ? document.version : 0,
    updatedAt: text(document?.updated_at),
    publicUrl: text(document?.public_url),
  };
}

/** Both documents plus the legal-notice link, as an administrator edits them. */
export async function fetchAdminPolicies() {
  const response = await apiGet(ADMIN_ENDPOINT);
  const policies = response.policies ?? {};
  return {
    rules: readAdminDocument(policies.rules),
    privacy: readAdminDocument(policies.privacy),
    legalNoticeUrl: typeof policies.legal_notice?.url === 'string' ? policies.legal_notice.url : '',
  };
}

/**
 * Saves one document. `material` asks members to agree again (rules) or
 * tells them about the change (privacy); the server assigns the version.
 */
export async function saveAdminPolicy(kind, { source, markdown, url, material, changeSummary }) {
  const response = await apiPost(ADMIN_UPDATE_ENDPOINT, {
    document: kind,
    source,
    markdown: source === 'hosted' ? markdown : null,
    url: source === 'external' ? url : null,
    material: Boolean(material),
    change_summary: material && changeSummary ? changeSummary : null,
  });
  return readAdminDocument(response.policies?.[kind] ?? response.document);
}

export function saveLegalNoticeUrl(url) {
  return apiPost(ADMIN_UPDATE_ENDPOINT, { document: 'legal_notice', source: url ? 'external' : 'none', url: url || null });
}

export async function previewMarkdown(markdown) {
  const response = await apiPost(ADMIN_PREVIEW_ENDPOINT, { markdown });
  return typeof response.html === 'string' ? response.html : '';
}
