# Message editing and author deletion API

Migration `0012_message_mutations.sql` adds author editing and delete-for-everyone semantics to room messages and direct messages. All mutation endpoints require authentication, the current `X-CSRF-Token`, and a positive message ID. Metadata endpoints require authentication and accept at most 100 comma-separated message IDs.

## Security model

The message row is always the authorization authority. A client cannot prove ownership by supplying a room ID, peer ID, username, or sender field.

- Only the original author may edit or author-delete a message.
- Deleted messages cannot be restored or edited.
- Room mutations require current room membership and current minimum-age eligibility.
- Room system messages cannot be changed by an author.
- Existing moderator room deletion remains separate and continues to require moderation authorization.
- Direct-message editing is unavailable while either participant has blocked the other.
- Direct-message deletion remains available to the sender while blocked.
- Attachment captions may be edited; the stored file cannot be replaced through an edit.

Mutation responses never include earlier message bodies.

## Immutable revisions

PostgreSQL `BEFORE UPDATE` triggers append a revision whenever a supported message body changes or a message crosses from active to deleted. This protects the revision record even when an existing moderation service performs the update.

`room_message_revisions` stores:

- message ID;
- `edit` or `delete` action;
- actor user ID when still available;
- message type;
- body before the mutation;
- body after an edit, or `NULL` for deletion;
- revision timestamp.

`direct_message_revisions` stores the corresponding direct-message fields.

Revision rows are not participant-facing. They remain linked to the parent message and are deleted when configured message retention permanently removes that parent. Audit records contain the action and identifiers, but do not duplicate message bodies.

## Room-message metadata

### `GET /api/v1/rooms/message-mutations.php`

Query parameters:

- `room_id`: positive room ID;
- `message_ids`: 1-100 comma-separated positive message IDs.

Only rows in the requested authorized room are returned.

```json
{
  "messages": [
    {
      "id": 42,
      "body": "Corrected text",
      "type": "text",
      "edited_at": "2026-07-17T08:00:00+00:00",
      "deleted": false,
      "deletion_kind": null,
      "can_edit": true,
      "can_delete": true
    }
  ]
}
```

For deleted messages, `body` is `null`, controls are false, and `deletion_kind` is `author` or `moderator`.

### `POST /api/v1/rooms/edit-message.php`

```json
{
  "message_id": 42,
  "body": "Corrected text"
}
```

The body must contain 1-4,000 characters after trimming. An unchanged body returns HTTP 409 with `message_unchanged`. Success writes the revision and audit entry, updates the message, and publishes a normal room-scoped `room_message` event in one transaction.

### `POST /api/v1/rooms/delete-own-message.php`

```json
{"message_id": 42}
```

Success soft-deletes the message and any attached file metadata, writes the immutable revision and audit entry, and publishes the existing room-scoped `message_deleted` event with `deletion_kind: "author"`. The browser displays “Message deleted by its author.” Moderator deletion continues to display a distinct placeholder.

## Direct-message metadata

### `GET /api/v1/direct-messages/message-mutations.php`

Query parameter:

- `message_ids`: 1-100 comma-separated positive message IDs.

Only messages involving the authenticated account are returned.

```json
{
  "messages": [
    {
      "id": 77,
      "body": "Corrected private text",
      "edited_at": "2026-07-17T08:00:00+00:00",
      "deleted": false,
      "can_edit": true,
      "can_delete": true
    }
  ]
}
```

`can_edit` becomes false while messaging is blocked in either direction. The sender may still receive `can_delete: true` for an active message.

### `POST /api/v1/direct-messages/edit.php`

```json
{
  "message_id": 77,
  "body": "Corrected private text"
}
```

The operation serializes with send, attachment upload, block, unblock, and delete operations through the existing unordered user-pair PostgreSQL advisory lock. After acquiring the lock it rechecks blocking and ownership. Success publishes separate perspective-correct `direct_message` events to sender and recipient.

### `POST /api/v1/direct-messages/delete.php`

```json
{"message_id": 77}
```

The sender may delete an active message even while either participant has blocked the other. The revision trigger preserves the previous body and replaces the canonical body with the fixed non-sensitive string `Message deleted.` for compatibility with older clients. Mutation-aware clients receive `deleted: true` and render “Message deleted by sender.”

## Attachment deletion and retention

Deleting a room or direct message immediately marks associated attachment metadata deleted. Metadata enrichment stops returning the file, and download attempts return HTTP 410 with `attachment_deleted`. The binary is deliberately not removed inside the user request so moderation evidence and backup consistency are preserved.

The configured `deleted_attachment_retention_days` policy applies to both room and direct-message attachments. A maintenance dry-run counts both types. Once the cutoff is reached, maintenance deletes the metadata and binary in the same run. Until then, both remain known to orphan detection and are not removed as untracked files.

## Realtime and compatibility

The canonical room and direct-message event types are unchanged:

- room edits use `room_message`;
- room deletions use `message_deleted`;
- DM edits and deletions use targeted `direct_message` events.

Existing clients continue to receive valid message structures. Mutation-aware browser modules fetch bounded authoritative metadata for visible message IDs, add controls and edited markers, and settle idempotently after each DOM update.

Once migration `0012` has been applied, older ChitChat code must not be run against the advanced database.
