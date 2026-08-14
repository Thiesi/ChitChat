# ADR 0005: Reactions data model and authorization boundary

- Status: Proposed (product decisions resolved; implementation not started)
- Date: 2026-08-14

## Context

The roadmap lists reactions as the next post-`v1.3` feature, planned after replies and mentions (`0020_replies_mentions.sql`) established the durable-notification and per-kind-table patterns this proposal reuses directly. The roadmap requirement is specific: a small controlled emoji vocabulary, idempotent add/remove with at most one reaction of each kind per user and message, aggregate counts without an unrestricted participant-discovery surface, PostgreSQL-authoritative state with realtime delivery through the existing event system, and defined behavior for deleted messages, blocked DM relationships, retention and account tombstoning.

## Decision

### Schema: per-kind tables, cascading, uniqueness enforced by the database

Add `room_message_reactions` and `direct_message_reactions`, each with a real foreign key `ON DELETE CASCADE` into its own message table and into `users(id)` — the same choice ADR 0004 made for mentions and for the identical reason: a reaction has no reason to outlive the message or account it's attached to. Unlike mentions, a reaction also has no reason to outlive being explicitly removed, so:

```sql
CREATE TABLE room_message_reactions (
    id BIGSERIAL PRIMARY KEY,
    message_id BIGINT NOT NULL REFERENCES room_messages(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    emoji VARCHAR(8) NOT NULL CHECK (emoji IN ('👍', '❤️', '😂', '😮', '😢', '🎉')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (message_id, user_id, emoji)
);
```

(`direct_message_reactions` mirrors this against `direct_messages`.) The roadmap's "idempotent... at most one reaction of each kind per user and message" is enforced by the `UNIQUE` constraint itself, not by an application-level check that could drift from the schema: adding is `INSERT ... ON CONFLICT (message_id, user_id, emoji) DO NOTHING`, removing is a plain `DELETE` matching all three columns and is a no-op if nothing matches. Two explicit endpoints (add / remove) rather than one "toggle" endpoint, matching the existing `block.php`/`unblock.php` precedent — a toggle would require the client to already know current state to pick a direction, where explicit add/remove doesn't.

### Vocabulary

`VARCHAR(8) CHECK (emoji IN (...))` mirrors how `moderation_reports.category` already enumerates a fixed set inline in the migration rather than a separate lookup table — consistent with this codebase's existing preference for small controlled vocabularies. The vocabulary is confirmed as 👍 ❤️ 😂 😮 😢 🎉 (see Decisions below). Changing the set later is a small forward-only migration (`DROP CONSTRAINT` / `ADD CONSTRAINT`, the same pattern `0006`, `0007`, `0012` and `0020` already used to extend other enumerated columns), not a schema redesign.

### Authorization boundary

Reacting requires exactly the same authorization as reading the message: `RoomAuthorization::requireHistory` plus `RoomEligibility::requireMinimumAge` for rooms (the same pair `MessageService::history()` already requires), or being a participant in the direct message. No new authorization concept is introduced — if a participant can see a message, they can react to it, and removing a reaction only ever requires it being their own row (`user_id = actor.id`, enforced in the `DELETE`'s `WHERE` clause, not by a separate ownership check).

Adding a reaction to an already-deleted message is rejected with the same `409 message_already_deleted` `RoomMessageMutationService::requireAuthorMutation` and the moderation-report flow already use for the same situation — reacting to a message whose body has been replaced by a deleted-state placeholder has no sensible meaning. Existing reactions on a message that gets deleted afterward are left alone (not retracted); the client simply stops rendering the reaction bar once a message is marked deleted, matching how it already stops rendering edit/delete/report actions on deleted messages. This is a display decision, not a data one — the rows persist until the message itself is hard-deleted by retention, at which point they cascade away automatically with no separate cleanup job, exactly like mentions.

### Reactor identity is exposed, scoped the same as authorship already is

A message's `reactions` field is `list<array{emoji:string, users:list<array{id:int, username:string}>, reacted_by_me:bool}>` (`count` is `users.length`, not tracked separately). Confirmed per Decisions below: reactor identity is shown, the same way message authorship already is — a room member who has never posted becomes visible to other viewers the first time they react, which is an accepted tradeoff here rather than one this ADR forecloses on its own.

This introduces no new query-authorization surface: the reactor list resolves through the same authorization-scoped read as the message itself (if a participant can already see the message and its author's username, they can see who reacted to it), and a reactor's username is a snapshot resolved the same way an author's is — shown regardless of whether that account is still a room member, and rendered however a tombstoned account's username already renders elsewhere, with no special-casing needed.

### Realtime delivery

A new `message_reaction_changed` event type, added to `EventRepository::TYPES` alongside the existing eight. Payload carries `message_kind`, `message_id`, `room_id` (rooms only), and the message's current full `reactions` array (not a delta) — small, and lets the client just replace its rendered reaction bar rather than reconciling adds/removes against potentially-missed events, consistent with how `message_deleted` already just carries the terminal state rather than a diff. Published with `roomId` for room reactions (visible to current room members, matching `room_message`) or twice with `targetUserId` per participant for direct-message reactions (matching how `DirectMessageService::send()` already publishes twice). Per the roadmap, this reuses the existing event system rather than adding a new transport.

### Interaction with blocked DM relationships

Reacting to an *existing*, already-retained direct message is allowed even if the participants are now blocked, for the same reason ADR 0004 decided reply previews keep rendering after a block: a block affects *new* messages, not interaction with history a participant could already see. `DirectMessageBlockService::requireMessagingAvailable()` (the gate `DirectMessageService::send()` uses) is deliberately **not** reused for react/unreact — reacting isn't sending a new message, and gating it on current block state would make an old, already-visible message's available actions flicker based on an unrelated, later relationship change.

### Retention and account tombstoning

No new behavior is needed here beyond what the schema already implies: reactions reference `users(id)`, the same column every other author/actor reference in this schema already uses, and account closure's "maintenance-driven profile tombstoning" (per the roadmap's account-lifecycle section) already scrubs credentials and identifiers while retaining the row and its id. A reaction from a since-closed account therefore behaves exactly like a message or mention from one already does — no special-casing to design or implement.

### Rate limiting

Reuses the existing `room_message_mutation` / `direct_message_mutation` policies rather than adding new ones. A reaction is a lightweight mutation of an existing message's state, the same category edit/delete already fall into, and unlike `@room`/`@here` broadcasts it has no fan-out that could justify a dedicated policy.

## Consequences

- Two new small tables with real foreign keys and a `UNIQUE` constraint keep idempotency and retention correctness enforced by the database, not application logic that could drift from it.
- No new authorization concept, rate-limit policy, or transport is introduced; reactions compose entirely from mechanisms ADR 0004 and earlier features already established.
- Reactor identity is visible the same way authorship already is; this is an accepted, explicitly confirmed tradeoff for a small self-hosted deployment, not a silent default.

## Decisions

The two questions this ADR originally left open have been resolved:

1. **Emoji vocabulary confirmed: 👍 ❤️ 😂 😮 😢 🎉.**
2. **Reactor identity is shown, not aggregate-only.** `reactions` carries the list of participants (`id`, `username`) per emoji, matching how message authorship is already visible to every viewer. A never-posting room member becomes visible to others the first time they react — an accepted tradeoff, not a gap.

No open product decisions remain; the next step is implementation, starting with migration `0021_reactions.sql`.
