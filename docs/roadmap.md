# ChitChat roadmap

This roadmap records the agreed direction after the stable `v1.2.0` release. It is directional rather than a promise of dates: security, privacy, data integrity, release safety, and operational simplicity take precedence over feature count.

## Guiding principles

- Keep the supported deployment understandable for a small self-hosted installation.
- Prefer explicit, auditable policy over hidden administrator capability.
- Preserve shared-conversation integrity when implementing user data controls.
- Re-evaluate authorization at read and action time rather than trusting browser state.
- Add horizontal complexity only when a measured deployment need justifies it.

## Stable foundation

### Release publication

**Status:** implemented in `v1.2.0`.

The protected manual workflow validates one exact `main` commit, required checks, version declarations, changelog and committed release notes before creating an immutable annotated tag and GitHub release.

### Security, throttling, backup and operations

**Status:** implemented in `v1.2.0`.

- Bounded deployment-configurable PostgreSQL-backed rate-limit policies and privacy-preserving aggregate counters.
- Supported manifest-bound PostgreSQL-plus-attachment backup, verification and staged restore commands.
- Release archive, upgrade, backup/restore and real Nginx/PHP-FPM SSE rehearsal.
- Administrator system status, optional Prometheus metrics, maintenance records and scheduled-operation examples.

### Account lifecycle and authentication

**Status:** implemented in `v1.2.0`.

- Step-up-protected account closure with a 14-day restoration period and maintenance-driven tombstoning.
- Optional password-first WebAuthn MFA with multiple passkeys and one-time recovery codes.
- Optional passkey-MFA enforcement for global administrative roles.
- Durable participant-facing privacy notifications for selected sensitive actions and policy changes.

### Accessibility and browser quality

**Status:** automated implementation complete; release-specific manual assistive-technology sign-off remains a human task.

The committed gates cover structural and keyboard accessibility across Chromium, Firefox and WebKit, plus Chromium axe-core, reflow, forced-colors, reduced-motion and selected screenshot regression. These checks do not claim a completed manual WCAG audit or real NVDA/VoiceOver sign-off.

## Post-v1.2 product work

### Authorization-aware participant message search

**Status:** implemented after `v1.2.0` as migration `0018_message_search.sql`.

- Search current, undeleted room and direct-message bodies using PostgreSQL full-text search.
- Enforce room discoverability, membership, invitation, minimum-age and DM-participant rules inside the query.
- Never search retained revision bodies.
- Send terms in a CSRF-protected request body and keep them out of ChitChat audits, rate-limit identifiers, aggregate metrics and URL history.
- Link results to the exact message through ordinary authorized history APIs.

### Participant reporting and moderation queue

**Status:** implemented after participant search as migration `0019_moderation_reports.sql`.

- Let a participant report one specific visible, undeleted room message or incoming direct message.
- Aggregate reports by canonical message while allowing each participant one report per case.
- Preserve immutable exact-message snapshots so later edits or ordinary message retention cannot erase active evidence.
- Restrict room owners and moderators to cases from rooms they currently moderate.
- Restrict DM cases to global moderation roles and expose only submitted snapshots, never surrounding private history.
- Support open, in-review, resolved and dismissed cases with explicit assignment and bounded outcome notes.
- Keep underlying deletion, warning, ban and account-control actions separate so they retain their own authorization and audit trails.
- Include an account's own submitted reports in personal-data export without disclosing other reports, queue assignments or private moderator notes.
- Retain active evidence independently of canonical messages, then bind closed evidence to the exact closure audit so configured audit retention also bounds the case.

### Replies and mentions

**Status:** backend implemented per [ADR 0004](architecture/0004-replies-and-mentions.md) as migration `0020_replies_mentions.sql` — reply storage/resolution, mention parsing/authorization/notifications, and personal-data export. No composer/browser-facing UI yet: message composition, @mention autocomplete and reply affordances remain to be built before this reaches participants.

- Store durable reply references by canonical message ID rather than copying a second authoritative body.
- Render an authorization-aware preview and a clear placeholder when the referenced message is unavailable, deleted or expired.
- Add `@username` mentions, plus room-scoped `@room`/`@here` broadcast mentions, only where the mentioned account (or, for a broadcast, each current room member) can access the conversation. Broadcast mentions are throttled by a dedicated rate-limit policy independent of ordinary message sending.
- Deliver durable in-app mention notifications without making realtime delivery a correctness requirement.
- Define edit, deletion, retention, export and moderation behavior before implementation.

### Reactions

**Status:** planned after replies and mentions.

- Use a deliberately small controlled emoji vocabulary.
- Make add/remove operations idempotent with at most one reaction of each kind per user and message.
- Expose aggregate counts without creating an unrestricted participant-discovery surface.
- Keep PostgreSQL authoritative and deliver realtime updates through the existing event system.
- Define behavior for deleted messages, blocked DM relationships, retention and account tombstoning.

### Notification preferences and optional Web Push

**Status:** possible later work.

Per-category preferences, quiet hours and privacy-preserving Web Push are candidates after replies/mentions establish a broader user-notification model. Push payloads should omit message bodies by default.

### Optional OpenID Connect login

**Status:** possible later work.

External identity may be linked to local accounts while ChitChat retains local authorization, room roles, lifecycle rules and at least one local break-glass Super-Administrator.

## Deliberately deferred

### End-to-end-encrypted direct messages

This would conflict with current server-side history, revision retention, reporting, moderation, export and administrative-inspection contracts. It requires a separately designed product mode rather than an incremental toggle.

### Horizontal scaling

The supported target remains one application server. Multi-node operation should begin only when measured capacity or availability requirements justify shared sessions, shared/object attachment storage, cross-node SSE wake-ups, maintenance leadership, deployment draining and migration coordination. Redis or another coordination service will not be added merely to advertise scalability.
