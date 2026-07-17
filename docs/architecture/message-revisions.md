# Immutable message revisions

ChitChat treats a participant-facing edit or deletion as a mutation of the canonical message plus an append-only revision record.

## Why database triggers

Revision insertion is implemented with PostgreSQL `BEFORE UPDATE` triggers rather than only inside the new author-facing services. The existing room moderator deletion path predates author editing and deletion. A trigger ensures that both old and new application paths, plus any future correctly authorized update path, cannot change a message body or cross into deleted state without preserving the previous content.

The application still writes a normal audit entry for each supported service action. The audit log records who acted, the subject, contextual identifiers, and the request IP. Message bodies are stored only in the revision ledger, not duplicated into audit JSON.

## Canonical versus historical data

The canonical message tables remain optimized for participant history and realtime delivery:

- room messages retain their body but participant history suppresses it after deletion;
- direct messages replace a deleted body with the fixed compatibility placeholder `Message deleted.`;
- `edited_at` identifies the latest edit;
- `deleted_at` and `deleted_by` identify deletion state.

The revision tables preserve each previous body and, for edits, the new body at that revision. Repeated edits therefore form an ordered chain without rewriting earlier entries.

## Retention

Revision rows reference their canonical message with `ON DELETE CASCADE`. Participant deletion is soft deletion and does not remove revisions. Configured room or direct-message retention eventually hard-deletes the canonical message and its revisions together.

Attachment binaries follow the separate deleted-attachment retention policy. They become inaccessible immediately after message deletion, but remain tracked until maintenance removes their metadata and bytes.

## Access boundary

Revision bodies are not exposed to ordinary participants. The current administrative direct-message inspection workflow also reads canonical retained history and does not silently gain revision or binary access. A future revision-review workflow must define its own role, reason, audit, and disclosure requirements rather than inheriting access accidentally.
