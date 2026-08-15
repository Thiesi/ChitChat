# ADR 0004: Replies and mentions data model and authorization boundary

- Status: Accepted; implemented as migration `0020_replies_mentions.sql`, released in `v1.3.0`
- Date: 2026-08-14

## Context

The roadmap lists replies and mentions as the next post-`v1.2` feature, after authorization-aware search (`0018_message_search.sql`) and the moderation queue (`0019_moderation_reports.sql`). It requires defining edit, deletion, retention, export and moderation behavior before implementation, not just the happy-path schema. This ADR proposes that data model and authorization boundary so implementation can follow the same reviewable pattern as the two features already shipped.

Room messages and direct messages remain two separate tables (`room_messages`, `direct_messages`) with independently evolving columns (`edited_at`/`edited_by`/`deleted_at`/`deleted_by` per `0012_message_mutations.sql`). There is no unified `messages` table. Any cross-kind reference — a reply, a mention, a moderation case — has had to choose between a polymorphic `(message_kind, message_id)` pair with no foreign key (the `moderation_cases`/`moderation_reports` precedent in `0019`) or two kind-specific tables with real foreign keys. This ADR uses both, deliberately, because replies and mentions have opposite lifecycle requirements.

## Decision

### Reply references: polymorphic, non-cascading, by canonical message ID

Add nullable `reply_to_message_kind VARCHAR(16) CHECK (... IN ('room','direct'))` and `reply_to_message_id BIGINT` columns to both `room_messages` and `direct_messages`, mirroring the `moderation_cases` pattern. No foreign key is possible across two candidate parent tables, so referential integrity is enforced at write time by the sending service, not the database — the same trust boundary `moderation_cases.message_id` already accepts.

A reply target must be in the same conversation as the reply itself: the same `room_id` for room messages, or the same sender/recipient pair for direct messages. A reply cannot point across rooms or across a different DM thread. This keeps the authorization question trivial — if a participant can see the reply, they were already authorized into the same room or DM thread the target lives in, so no separate access check or access-oracle risk is introduced by resolving the preview.

Replies are deliberately **not** cascade-deleted when their target is later removed, because the reply's own body is independent content with its own value, edit/deletion history, and (soon) reports. Two cases arise when resolving a preview:

- the target still exists but is soft-deleted (room messages keep their row; direct messages already replace `body` with the fixed placeholder `Message deleted.` per `0012`) — render the target's own existing deleted-state placeholder, identical to how it already renders in ordinary history;
- the target's row no longer exists at all, because retention hard-deleted it — render a distinct fixed placeholder (e.g. `Original message no longer available.`) that does not reveal whether it was deleted by policy, deleted by a moderator, or aged out, avoiding a new disclosure surface beyond what already exists for ordinary deleted messages.

Resolve the preview through the same authorization-scoped read path history already uses, not a new privileged lookup — this is the same principle `0018`'s search enforces (authorization inside the query, never a separate trusted-client check).

### Mentions: per-kind tables, cascading, resolved and authorized at send time

Add two tables — `room_message_mentions` and `direct_message_mentions` — each with a real `message_id` foreign key `ON DELETE CASCADE` into its own message table, rather than one polymorphic table. Unlike a reply, a mention has no reason to outlive the message that contains it: if the message is hard-deleted by retention, the mention record is exactly as disposable as the message body itself, and a real foreign key removes an entire class of maintenance-job bookkeeping the moderation-evidence design had to solve deliberately for the opposite reason (`moderation_cases` must *not* cascade with the message; mentions should).

`@username` mentions are parsed and resolved once, at send time, inside the same service call that inserts the message — not re-derived from the body text at render time, and not performed inside a database trigger. Resolution requires exactly the kind of authorization decision (`RoomAuthorization`/`RoomEligibility`, DM-block state) that already lives in application services, not SQL. For each `@username` token that resolves to an existing account:

1. check that account currently has access to this room (membership, or discoverability/invitation rules identical to those search and history already enforce) or, for a DM, is the message's recipient;
2. if authorized, insert a mention row and a durable notification in the same transaction as the message;
3. if not authorized (the named account can't see this conversation), silently drop the token as a mention — render it as plain text, not a broken link, and do not notify. This mirrors the roadmap's explicit requirement ("only where the mentioned account can access the conversation") and avoids using `@username` as a way to probe room membership or DM reachability.

Only currently-resolvable, currently-authorized accounts become mentions. A later room-membership change does not retroactively add or remove a mention from a message already sent — mentions are a snapshot of authorization at send time, consistent with how revisions and moderation evidence are snapshots rather than live-recomputed views.

No per-message cap on individual mention count is introduced (see Decisions below); fan-out is bounded by the existing per-account send rate limits rather than a count check at write time.

#### `@room` and `@here` broadcast mentions

Room messages (not direct messages — a DM already has exactly one implicit recipient, so a broadcast token there is meaningless and is rendered as plain text) additionally recognize the literal tokens `@room` and `@here` as broadcast mentions, resolved at send time to every account currently a member of that room:

- the sender is excluded from their own broadcast (no self-notification);
- each resolved member gets an ordinary `room_message_mentions` row and `account_notifications` row, exactly as an individual `@username` mention would — a broadcast is not a new data shape, it is `@username` resolution applied to the room's current membership instead of a single parsed name;
- `context_json` carries `"broadcast": true` so the notification center and any future digest can read "mentioned everyone in #room" instead of "mentioned you" without a schema change;
- because broadcast size is bounded by room membership rather than by a per-message token count, it is not covered by any per-mention cap (there is none) or by the ordinary per-message send rate limit alone — see the new `RATE_LIMIT_ROOM_BROADCAST_MENTION` policy below, which bounds how often broadcast mentions themselves can be used, independent of how often the account sends ordinary messages.

### Notifications

Extend `account_notifications.kind` with `'mentioned'` and a `context_json` shaped like the existing `revision_review` kind (`message_kind`, `message_id`, `room_id` where applicable, `broadcast` for `@room`/`@here`). Unlike the `0017` notifications, mention notifications are not derived from `audit_log` via a trigger, because ordinary message sends are not audited and should not become audited merely to support this feature — audit volume is reserved for administrative and security-sensitive actions. Instead, the sending service inserts the notification row directly in the same transaction as the message and mention rows, preserving the existing invariant that a notification can't commit without the action that caused it.

Per the roadmap, realtime delivery is not a correctness requirement: no new `realtime_events.event_type` is required for `v1` of this feature. The existing notification-center pagination and unread badge (already polled/refreshed on reconnect) are sufficient; a `mention_notified` SSE event can be added later as a pure UX improvement without changing the notification's durable source of truth.

### Rate limiting for broadcast mentions

Add a new named policy, `RATE_LIMIT_ROOM_BROADCAST_MENTION`, following the existing `RateLimitPolicySet` pattern used for every other sensitive path (`RATE_LIMIT_MESSAGE_REPORT`, `RATE_LIMIT_MODERATION_ACTION`, etc.). It is checked when a message contains `@room` or `@here`, independent of — and in addition to — the ordinary `RATE_LIMIT_ROOM_SEND` check every message already passes through. This is the mechanism the mention-cap decision below relies on: instead of counting mentions per message, the *use of broadcast mentions* is itself throttled per account, since broadcast is the only case where one message's fan-out isn't bounded by a token count.

Proposed default, consistent with the other low-frequency/high-impact policies already in `.env.example` (e.g. `RATE_LIMIT_MESSAGE_REPORT_MAX_ATTEMPTS=10` per hour): `RATE_LIMIT_ROOM_BROADCAST_MENTION_MAX_ATTEMPTS=5`, `RATE_LIMIT_ROOM_BROADCAST_MENTION_WINDOW_SECONDS=3600`. Aggregate allowed/rejected counts surface in Administrator system status and Prometheus exactly like every other policy — no account, room, or message identifiers.

### Interaction with edit, deletion, retention, export and moderation

- **Edit:** editing a message does not re-resolve its mentions or replies. A mention is fixed at send time; if an edit adds a new `@username`, it is rendered as plain text and does not retroactively notify (re-resolving on every edit would let an author repeatedly re-ping the same account, and would require re-running authorization checks on an update path that today only appends a revision, not a new authorization decision).
- **Deletion:** a deleted message's reply-to reference and mention rows are unaffected — see the non-cascading behavior above for replies; mentions naturally disappear only when the message itself is hard-deleted, per the cascading foreign key.
- **Retention:** governed entirely by the existing per-message hard-delete path; no new retention policy is introduced. Mentions cascade with their message automatically; replies survive and fall back to the "no longer available" placeholder described above.
- **Export:** a participant's personal-data export (`PersonalDataExportService`) should include the participant's own sent mentions of others and mentions of the participant, following the same shape as the existing moderation-report export addition — the participant's own data only, never another participant's message body or unrelated context.
- **Moderation:** a report against a message that is itself a reply should capture only that message's own immutable evidence snapshot, as `0019` already does; it does not need to separately snapshot the reply target, which resolves through the ordinary authorization-scoped read path like any other reply.

## Consequences

- Two new small tables with real foreign keys (mentions) keep retention and maintenance free of new cleanup jobs; two new nullable columns (reply references) keep the non-cascading reply behavior consistent with existing history and deleted-message rendering.
- Mention authorization is enforced once, at send time, by application services that already own the relevant authorization logic, rather than duplicated into a database trigger or re-checked at render time.
- No new realtime event type or external service is required.

## Decisions

The three questions this ADR originally left open have been resolved:

1. **No per-message mention-count cap.** Fan-out for individual `@username` mentions is bounded implicitly (a message body has a fixed maximum length, so the number of distinct tokens it can contain is already bounded), and is otherwise left to the existing per-account send rate limits rather than an additional count check at write time. Broadcast mentions are the one case where fan-out isn't bounded by token count, so they get their own dedicated rate-limit policy instead — see `RATE_LIMIT_ROOM_BROADCAST_MENTION` above. This keeps the mitigation consistent (rate limiting, not per-message counting) rather than mixing two different bounding mechanisms.
2. **`@room` and `@here` broadcast mentions are in scope for `v1`,** alongside individual `@username` mentions, per the design above. This is a wider scope than the roadmap bullet's literal wording ("Add `@username` mentions only where...") and than this ADR originally proposed; `docs/roadmap.md` has been updated to say so explicitly.
3. **Reply previews render normally for retained history regardless of a later block.** Blocking affects new messages only, consistent with how DM blocking already behaves for existing history today. No special-case check against current block state is added to preview resolution.

No open product decisions remained; implementation followed as migration `0020_replies_mentions.sql`, released in `v1.3.0`.
