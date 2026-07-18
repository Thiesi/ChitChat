# ChitChat roadmap

This roadmap records the agreed direction after the `v1.1.0` release. It is directional rather than a promise of dates: security, privacy, data integrity, release safety, and operational simplicity take precedence over feature count.

## Guiding principles

- Keep the supported deployment understandable for a small self-hosted installation.
- Prefer explicit, auditable policy over hidden administrator capability.
- Preserve shared-conversation integrity when implementing user data controls.
- Automate repeatable release and operating procedures without weakening approval boundaries.
- Add horizontal complexity only when a measured deployment need justifies it.

## Current work: operational foundations

### 1. Permanent release publication workflow

**Status:** implemented.

Replace disposable publication pull requests with a permanent manually dispatched workflow that:

- validates a full commit SHA reachable from `main`;
- verifies version declarations, changelog entry, and committed release notes;
- requires every release check to have succeeded on that exact commit;
- supports validation-only, stable, and pre-release modes;
- uses a protected `release` environment for the publication job;
- creates an annotated immutable tag and a GitHub release from the committed notes;
- is safe to resume when tag creation succeeded but release creation did not.

### 2. Dependency and security automation

**Status:** implemented.

- Run locked Composer and npm vulnerability audits for pull requests, pushes to `main`, a weekly schedule, and manual dispatches.
- Require the dependency audit on the exact release commit before publication.
- Configure separate weekly Dependabot update streams for Composer, npm, and GitHub Actions.
- Keep automated dependency updates small and independently reviewable.
- Record the CodeQL evaluation: JavaScript and Actions are supported, but PHP is not, so whole-application CodeQL is deferred rather than presenting materially incomplete coverage as comprehensive analysis.

### 3. Configurable and observable throttling

**Status:** implemented.

- Replace fixed internal limits with bounded named policies loaded from deployment environment variables.
- Cover login, registration, password step-up, exports, messages, pings, mutations, uploads, invitations, participant and administrative searches, DM inspection, and revision review.
- Preserve all previously enforced defaults while adding deliberately generous controls to formerly unbounded search and invitation paths.
- Keep policy mutation outside the browser administration surface so a compromised administrative session cannot weaken deployment anti-abuse controls.
- Expose effective policies and aggregate allowed/rejected counters through Administrator system status and Prometheus without storing account, IP, room, message, search-term, or request-body identifiers in the aggregate ledger.

### 4. First-class backup tooling

**Status:** implemented.

- Provide supported backup, verification, and restore commands that bind PostgreSQL and attachment storage into one versioned backup set.
- Record application version, ordered migration state, timestamps, database metadata, attachment inventory, external tool versions, exact sizes, and SHA-256 checksums in `manifest.json`.
- Verify dump readability and reject attachment archives containing traversal paths, links, or special files.
- Restore into staged attachment storage and a newly created database by default, then compare restored inventory and migration state before publishing the target path.
- Require explicit flags for destructive replacement and configured production targets; preserve replaced attachment storage instead of deleting it.
- Exercise the exact commands in dedicated backup-rehearsal CI and provide ready-to-adapt daily `systemd` service/timer units.

## Account and authentication lifecycle

### 5. Account closure

**Status:** implemented.

- Require recent privileged step-up before requesting closure.
- Invalidate all sessions, revoke global roles, and prevent normal login immediately.
- Reserve the original username during a fixed 14-day cooling-off period and provide an explicit, independently throttled credential-based restoration path.
- Keep MFA-enabled accounts closure-pending until the second factor succeeds, then recheck the deadline and current role policy in the restoration transaction.
- Restore the saved global-role snapshot only when restoration succeeds before the deadline and the account still satisfies current administrative-MFA policy.
- Finalize expired closures through ordinary maintenance by tombstoning username, canonical login name, password, birth date and last-login metadata.
- Release the original username for reuse only after finalization.
- Preserve shared room and direct-message history, immutable revisions, attachment evidence, room membership and ownership attribution, bans, and audits according to existing retention policy.
- Remove invitations, live presence/SSE leases, block preferences, login-attempt rows tied to the old canonical username, and global privileges at the appropriate lifecycle stage.
- Keep closure audit metadata limited to user/closure IDs, deadline, policy duration, and restored or withheld role names rather than copying usernames or content.
- Refuse closure by the final active Super-Administrator.

### 6. Multi-factor authentication

**Status:** implemented.

- Keep the password as the first factor and require a WebAuthn passkey or one-time recovery code before creating the authenticated session.
- Support multiple ES256 or RS256 passkeys with required user verification, exact origin/RP/challenge validation, backup-state tracking, and signature counters.
- Generate ten 96-bit one-time recovery codes, store only hashes, and reveal each replacement set once.
- Allow passkey enrollment, labels, additional credentials, recovery-code rotation, and policy-aware disablement from the account security surface.
- Use the strongest configured factor for privileged step-up: password for non-MFA accounts, passkey or recovery code for MFA accounts.
- Allow Super-Administrators to require passkey MFA for Super-Administrator, Administrator, Chat Administrator, and Global Moderator roles.
- Validate policy activation transactionally and enforce later protected-role grants again through a PostgreSQL invariant.
- Preserve MFA during closure cooling-off and irreversibly destroy credential and recovery material when the account is tombstoned.
- Exercise protocol fixtures and a real Chromium virtual authenticator while retaining Firefox and WebKit regression coverage for compatible paths.

## Later: transparency and quality

### 7. Participant-facing privacy notifications

**Status:** implemented.

- Derive durable participant-facing notifications atomically from selected append-only audit actions so an audited event and its disclosure commit together.
- Notify the affected room-message author or direct-message participants when retained revision history is reviewed.
- Notify an author when a moderator deletes a room message and notify an account when an administrator resets its password.
- Notify every active account when material registration, administrative-MFA, retention, cleanup, realtime, or login-history policy values change.
- Store only a fixed event kind, recipient, audit reference, bounded structural context, and read timestamps; do not copy message bodies, administrator reasons, IP addresses, credentials, or recovery material.
- Provide an authenticated paginated notification center, account-scoped read state, mark-all-read, and an unread badge without making realtime delivery a correctness requirement.
- Preserve notifications during account-closure cooling-off where relevant and delete the private notification history when tombstoning becomes irreversible.

### 8. Deeper accessibility and visual regression

**Status:** automated implementation complete; release-specific manual assistive-technology sign-off remains a human task.

- Run pinned `@axe-core/playwright` WCAG A/AA analysis against core signed-out and signed-in user surfaces as a supplementary Chromium gate.
- Retain structural and keyboard accessibility smoke coverage across Chromium, Firefox, and WebKit.
- Validate document reflow at 640- and 320-CSS-pixel widths, forced-colors affordances, and reduced-motion behavior without treating emulation as equivalent to real platform testing.
- Protect deliberately stable authentication and account layouts with targeted pinned-Chromium/Linux screenshot baselines and a small antialiasing tolerance rather than snapshotting volatile message or realtime content.
- Provide explicit NVDA, VoiceOver, keyboard, browser-zoom, Windows contrast-theme, and reduced-motion manual journeys with versioned result recording.
- Do not claim manual NVDA or VoiceOver validation merely because automated checks pass; complete and record those journeys for the release being assessed.

## Deliberately deferred

### Horizontal scaling

The supported target remains one application server. Multi-node operation should begin only when measured capacity or availability requirements justify shared sessions, shared/object attachment storage, cross-node SSE wake-ups, maintenance leadership, deployment draining, and migration coordination. Redis or another coordination service will not be added merely to claim scalability.

## Release grouping

The operational-foundation items can land as compatible `v1.1.x` improvements where they do not alter user-facing contracts. Account closure and MFA are schema- and contract-heavy changes intended for `v1.2.0`; participant notifications and later quality work follow separately.
