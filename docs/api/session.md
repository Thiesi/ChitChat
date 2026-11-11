# Session bootstrap and public installation metadata

`GET /api/v1/session.php` returns the ordinary PHP session/CSRF bootstrap. The
[authentication API](authentication.md#csrf-bootstrap) describes cookies, CSRF,
user and privileged step-up state. The additions below are available before
sign-in and remain present for members and guests; every existing v1 field stays.

## Application and capabilities

```json
{
  "application": {"name": "The Common Room", "version": "3.3.0"},
  "capabilities": {
    "schema_version": 1,
    "signed_in_sessions": true,
    "moderated_username_change": true,
    "native_passkey_origins": false,
    "native_auth_handoff": false
  }
}
```

- `application.name` is the same effective public name the browser displays,
  including a database override and falling back to `APP_NAME`. No admin role or
  HTML scraping is required. The browser health endpoint keeps its existing
  environment-default branding contract.
- `application.version` is `Config.applicationVersion`: by default the deployed
  release version, with `APP_VERSION` available as an explicit custom-build
  override. Leave it unset for ordinary installations and remove stale pins
  when upgrading. There is no
  independent API version constant. Published release values use SemVer, which
  clients compare semantically rather than lexicographically. A custom operator
  value that cannot be parsed means unknown for version comparisons; capability
  presence is the safer feature check. The `3.3.0` above is an example, not a
  forced override of the configured version during development.
- `capabilities.schema_version` identifies this additive discovery schema.
  Clients ignore unknown capability keys and continue to accept additive fields.
- **moderated_username_change** is true on servers where a moderator can
  require a new username: `user.username_change`, `403 username_change_required`
  and `POST /api/v1/account/username.php`. See [Required username changes](username-change.md).
- **native_passkey_origins** is true only when WebAuthn is enabled and
  **NATIVE_ANDROID_APPS** contains at least one trusted package/certificate pair.
  It describes configured server support, not proof of Digital Asset Links
  deployment or trust in any particular APK. See the [MFA API](mfa.md#native-android-passkey-clients).
- **native_auth_handoff** is true only with trusted Android apps, an enabled
  Google/Twitch provider, explicit HTTPS **APP_ORIGIN**, the same
  **OIDC_REDIRECT_ORIGIN**, and the integrated browser confirmation page.
  It describes server support, not APK attestation, app delivery or a completed
  sign-in. See [Native browser authentication](native-auth.md).

On older servers, missing `application` or `capabilities` means unknown, never
confirmed support. Clients fall back to host/default branding and disable new
unadvertised native-authentication flows; CCM can explain that the server needs
an update. False means this installation does not currently offer the feature.
Feature discovery guides presentation and never replaces server authorization.
No private settings, credentials, credential IDs, user handles, device identifiers
or other-user state are included. Existing `web_push.enabled` and
`web_push.vapid_public_key` remain the shared Web Push discovery contract.

The independent top-level `attachments` policy is documented in
[upload policy discovery](attachments.md#upload-policy-discovery), when present.

## Revision-review privacy disclosure

`privacy.message_revisions` retains its existing fields but corrects
`participant_notification` to **true**, matching the successful-review audit
trigger. An authorized successful review creates a durable `revision_review`
notification for the room message's author, or for both direct-message
participants. Accounts already closed are excluded. This does not notify every
member of a room. Failed or unauthorized reviews do not create successful-access
notifications. Push delivery and notification read state remain separate.

`admin_review_enabled` and `admin_review_role` still reflect installation
configuration. `reason_required` and `audit_each_review` stay true. This correction
neither enables review nor expands who may inspect revisions, and it does not
change the non-E2EE and retention disclosures for direct messages.

## Installation policies

Public `policies` and authenticated `user.rules` are documented in
[installation policies and rules acceptance](policies.md). Missing fields mean
unknown on older servers; None defaults preserve existing behavior.

See [password recovery](password-recovery.md) for the private `user.recovery_key` status, once-only key disclosure, dedicated reset MFA flow, and password-change/admin-reset rotation policy.

The signed_in_sessions capability advertises the [registered-login list and revocation API](signed-in-sessions.md). Missing or false means unsupported. It does not assert that a device is online.

The additive capabilities.totp flag is true only with integrated enrollment UI and a usable key source. Missing means unknown; false means enrollment is unavailable. It does not mean an account's enabled MFA is bypassed. See [TOTP](totp.md).

Session bootstrap adds mfa_pending: {methods:[...]} | null for the session's valid pending MFA login/restoration, including provider redirects to /?mfa=continue. It returns only the existing method names, no account identity or primary-proof data. Absent, expired, version-invalid or wrong-transport contexts return null; native continuation retains its session/transaction binding and may change networks. Reconciliation happens before returning csrf_token, since clearing expired context rotates that token. Use this field on provider return instead of guessing from installation capabilities.
