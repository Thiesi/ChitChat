# Installation policies and community-rules acceptance

Policy discovery is public. Acceptance is required only for community rules;
privacy and legal-notice links are informational. The defaults are **None** for
all three documents: no links are published and no new write gate applies.
These are additive API v1 fields; missing fields on older servers mean unknown.

## Discovery and account status

`GET /api/v1/session.php` adds:

```json
{
  "policies": {
    "rules": {"url": "https://chat.example/rules.php", "version": 1, "updated_at": "2026-11-08T12:00:00+00:00"},
    "privacy": {"url": "https://chat.example/privacy.php", "updated_at": "2026-11-08T12:00:00+00:00"},
    "legal_notice": null
  },
  "user": {
    "rules": {"accepted_version": 0, "accepted_at": null, "acceptance_required": true}
  }
}
```

Each discovery document is `null` when None. URLs are absolute: an admin-saved
HTTPS external URL, or the hosted page URL. Hosted pages and privacy-notification
links use the first configured origin in this order: `APP_ORIGIN`,
`OIDC_REDIRECT_ORIGIN`, `WEBAUTHN_ORIGIN`, then the validated request Host and direct
HTTPS state. Set `APP_ORIGIN` to the canonical public scheme, host and optional
port (no path), especially behind TLS termination: CCM opens these absolute URLs
directly, and PHP might otherwise see HTTP. Its scheme/host are normalized and
default ports removed; HTTPS is required outside loopback development/tests.
Forwarded host/protocol headers are not trusted. CLI reads without a configured
origin or HTTP host use localhost; these are not public deployment URLs.

`user` remains `null` while signed out. Signed-in members **and guests** receive
`user.rules`, including in login, registration and guest-start response users.
No acceptance record means version 0 and `accepted_at: null`. Existing user and
session fields remain present. Discovery guides the UI; the server checks the
current database policy again for every gated mutation.

## Read and accept

`GET /api/v1/policies/show.php?document=rules|privacy` is public and returns:

```json
{"document":{"source":"hosted","url":"https://chat.example/rules.php","html":"<h2>Community rules</h2>\n","markdown":"# Community rules","version":1,"updated_at":"2026-11-08T12:00:00+00:00","change_summary":"Be kind."}}
```

External documents have `source: "external"`, their configured URL, and **null**
`html` and `markdown`. The server never fetches external documents. None returns
`404 policy_document_not_found`; an unknown document name returns
`400 validation_error`. Clients follow discovery URLs rather than constructing
hosted paths. The browser UI's public `/rules.php` and `/privacy.php` templates
use the same `ChitChat\Policy\PolicyService::publicDocument()` read: hosted data,
302 to the saved external HTTPS URL, or 404 for None. Those templates and the
acceptance UI are delivered in the separate web-UI PR; the footer also uses
`PolicyService::legalNoticeUrl(): ?string` for the optional saved legal URL. Enable hosted policies
when the matching UI is deployed.

`POST /api/v1/policies/accept.php {"version":1}` requires an authenticated member
or guest and the current `X-CSRF-Token`. It returns `{"rules":{...}}` with the
new account status. `409 rules_version_outdated` means the supplied version is
no longer current, or rules are no longer published; refresh discovery before
asking again. Repeated acceptance of the same current version is idempotent and
preserves the original acceptance timestamp.

## Account creation

Password registration (`register.php`), provider signup completion
(**`oidc/register.php`**) and `guest/start.php` accept the optional JSON integer
`accepted_rules_version`. With rules configured, it must match the current
version:

- absent: `400 rules_acceptance_required`;
- stale: `409 rules_version_outdated`;
- wrong JSON type: `400 validation_error`.

The order is existing abuse throttle/challenge/honeypot, then rules acceptance,
then account/guest/provider mutation. A rejected attempt does not create an
account or link an identity, and a verified provider signup remains pending for
a retry. Creation and acceptance are committed in the same transaction, with a
rules-row lock preventing an intervening publication. Clients refresh the
existing one-use challenge before retrying a rejected signup.

## Gated writes and exceptions

Until current rules are accepted, these operations return
`403 rules_acceptance_required`: room and DM sends, edits, attachment uploads,
adding reactions, private pings, and uploading a profile picture (including a
cropped provider picture). Rules-row locks serialize checks with the content
transaction and a material publication. Existing membership, authorization,
ban/mute, file-limit and rate-limit checks continue to apply.

These controls stay available: reading, discovery and search; sign-in and
sign-out; accepting rules; block/unblock and ignore/unignore; reporting;
removing one's messages, reactions or avatar; password, MFA, passkey and identity
management; device/push revocation and preferences; privacy/display preferences;
export, account closure and restoration; all moderation and administration,
including policy editing. Internal moderation notices remain available. A
pending rules acceptance does not grant any extra permission for these actions.
No new SSE event or automatic content replay is introduced.

The same write set is gated by `403 username_change_required` while a moderator
requires a new username; `rules_acceptance_required` takes precedence when both
apply ([required username changes](username-change.md)).

## Administration

All administration routes require a member with the **Super-Administrator**
role, consistent with other installation settings:

- `GET /api/v1/admin/policies.php` returns
  an object with `policies.rules`, `policies.privacy` and `policies.legal_notice`.
  Rules/privacy contain `source`, `markdown`, `url`, `version`, `updated_at`
  and `change_summary`; legal notice contains only `url` (nullable).
- `POST /api/v1/admin/policies/update.php` requires CSRF and privileged step-up.
  Body: `{"document":"rules","source":"hosted","markdown":"# Rules","url":null,"material":true,"change_summary":"What changed"}`.
  `document` is rules, privacy or legal_notice; `source` is none, hosted or
  external. Legal notice uses only `url` (null/empty removes it). Returns
  `{"document":{...}}` in the administration shape.
- `POST /api/v1/admin/policies/preview.php {"markdown":"# Rules"}` requires
  CSRF and the same role, **without step-up**. It stores nothing and returns
  `{"html":"<h2>Rules</h2>\n"}`. Its dedicated `policy_preview` rate limit
  defaults to 30 attempts per 60 seconds, configured with the usual
  `RATE_LIMIT_POLICY_PREVIEW_MAX_ATTEMPTS` / `_WINDOW_SECONDS` variables.

Markdown publication and preview share a **65,536-byte UTF-8 limit**. Change
summaries have a 200-character limit. External URLs must be absolute HTTPS,
at most 2048 bytes, without credentials or control characters/whitespace.

The maintained [CommonMark renderer](https://commonmark.thephpleague.com/2.x/configuration/)
escapes raw HTML, restricts links to HTTP/HTTPS/mailto, omits images, and bounds
nesting/delimiter parsing. Page-owned h1 is preserved by rendering Markdown h1
as h2. Headings, paragraphs, lists, emphasis, links and horizontal rules are
shared across the service read, JSON and preview; no client Markdown parser is
needed for the web UI.

## Versions, privacy notices, export and audit

Versions are server-assigned positive integers, monotonic per rules/privacy
document. First publication is version 1 and always material. A small fix keeps
the version while updating text and timestamp; a material edit increments it.
None removes discovery/gating but preserves the counter. Re-enabling is always
material and increments it. Switching hosted/external follows the material
choice. Acceptance is required exactly when the latest accepted version is less
than the currently published rules version.

For privacy, a material save means "tell members": one
`privacy_policy_updated` timeline notification per non-closed member, with the
optional summary. Guests are excluded. These notifications are **never pushed**
and do not require acceptance. A small privacy fix creates no notice. Rules
publication does not automatically create a privacy-policy notification.

Personal-data export adds `account.rules_acceptances`, containing only that
account's version/timestamp records. Final account tombstoning removes them.
Audits record publication source/version/materiality and acceptance versions;
they never contain document Markdown or change-summary text. Existing retention
and privacy controls are unchanged.
