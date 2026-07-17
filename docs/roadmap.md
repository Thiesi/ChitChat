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

**Status:** implemented by the roadmap's first development change.

Replace disposable publication pull requests with a permanent manually dispatched workflow that:

- validates a full commit SHA reachable from `main`;
- verifies version declarations, changelog entry, and committed release notes;
- requires every release check to have succeeded on that exact commit;
- supports validation-only, stable, and pre-release modes;
- uses a protected `release` environment for the publication job;
- creates an annotated immutable tag and a GitHub release from the committed notes;
- is safe to resume when tag creation succeeded but release creation did not.

### 2. Dependency and security automation

- Run Composer and npm vulnerability audits in CI and on a schedule.
- Enable Dependabot for Composer, npm, and GitHub Actions.
- Evaluate CodeQL for the PHP and JavaScript code paths.
- Keep automated dependency updates small and independently reviewable.

### 3. Configurable and observable throttling

- Replace fixed internal limits with named per-action policies.
- Cover registration, login, password step-up, exports, messages, uploads, invitations, pings, and sensitive administrative lookups.
- Support safe environment defaults and bounded administrator configuration where appropriate.
- Expose aggregate allowed/rejected counters without identifiers or message content.

### 4. First-class backup tooling

Provide supported commands for backup, verification, and restore. A backup set should bind PostgreSQL and attachment storage through a manifest containing application version, migration state, timestamps, checksums, and restore metadata. Supply ready-to-adapt `systemd` service and timer units.

## Next: account and authentication lifecycle

### 5. Account closure

Implement step-up-protected account closure as a lifecycle rather than immediate row deletion:

- invalidate sessions and prevent future login immediately;
- use a documented cooling-off period;
- preserve shared messages, revision evidence, and audits according to retention policy;
- define profile tombstoning, restoration, and username-reuse rules explicitly;
- avoid copying unnecessary personal data into closure audits.

### 6. Multi-factor authentication

Prefer WebAuthn/passkeys with recovery codes, with TOTP considered as a compatibility fallback. Allow operators to require MFA for administrative roles. A recent strong assertion should be able to satisfy privileged step-up without weakening CSRF, role, reason, target, and audit checks.

## Later: transparency and quality

### 7. Participant-facing privacy notifications

Add durable in-app notifications for security- and privacy-relevant events such as administrative revision review, moderator deletion, administrative password reset, and material policy changes. Operators may need configurable timing, but policy disclosure must remain unavoidable.

### 8. Deeper accessibility and visual regression

- Add axe-core as a supplementary automated layer.
- Perform manual NVDA and VoiceOver journeys.
- Validate high zoom, high contrast, reduced motion, and narrow layouts.
- Add targeted screenshot regression for critical states without making harmless rendering differences block releases indiscriminately.

## Deliberately deferred

### Horizontal scaling

The supported target remains one application server. Multi-node operation should begin only when measured capacity or availability requirements justify shared sessions, shared/object attachment storage, cross-node SSE wake-ups, maintenance leadership, deployment draining, and migration coordination. Redis or another coordination service will not be added merely to claim scalability.

## Release grouping

The operational-foundation items can land as compatible `v1.1.x` improvements where they do not alter user-facing contracts. Account closure, MFA, participant notifications, and any schema-heavy policy work are candidates for `v1.2.0` or later.
