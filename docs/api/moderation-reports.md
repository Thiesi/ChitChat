# Participant reports and moderation queue API

ChitChat lets a signed-in participant report one specific room or direct message that the account is already authorized to read. Reports create authorization-scoped moderation cases; they are not an administrative search or general private-message inspection mechanism.

All state-changing endpoints require authentication and the current `X-CSRF-Token`. Queue reads require authentication and a moderation role. Request throttles are documented in [`../operations/rate-limiting.md`](../operations/rate-limiting.md).

## Evidence and privacy model

A successful report stores an immutable snapshot containing:

- the exact current message body or attachment caption, limited by the canonical 4,000-character message bound;
- message creation and optional edit timestamps;
- room message type and room name for a room report;
- optional attachment display name, MIME type, and size.

It does not store or expose:

- historical revision bodies;
- surrounding room or direct-message history;
- attachment bytes or opaque storage keys;
- passkeys, passwords, session data, IP addresses, or recovery material.

Report submission audits contain structural identifiers and the fixed category, but not the message snapshot or the participant's free-text details. Claim, release, and closure audits similarly omit evidence bodies and resolution notes.

Reports for the same canonical message aggregate into one case. Each participant may submit at most one report for that case. A new report reopens a previously closed case transactionally.

## Authorization

### Room reports

The participant must pass the same room-history and minimum-age checks as an ordinary history request. The message must be undeleted, authored by another user, and not a system message.

Room owners and room moderators may list and review cases only for rooms where they currently hold `owner` or `moderator` membership. Global roles may review all room cases.

### Direct-message reports

The requesting account must be a participant in the direct conversation and must be the recipient of the reported message. A sender cannot report their own outgoing DM. The message must be undeleted and contain a body/caption or attachment.

Only global roles may review DM cases:

- Super-Administrator;
- Administrator;
- Chat Admin;
- Global Moderator.

The queue returns only submitted snapshots for the exact DM. It never loads adjacent messages or grants attachment-download rights.

## `POST /api/v1/reports/message.php`

```json
{
  "message_kind": "direct",
  "message_id": 42,
  "category": "harassment",
  "details": "Optional participant explanation"
}
```

`message_kind` must be `room` or `direct`. Categories are:

- `spam`;
- `harassment`;
- `hate`;
- `threats`;
- `sexual_content`;
- `privacy`;
- `impersonation`;
- `other`.

`details` is optional, whitespace-trimmed, and limited to 1,000 characters. A successful response is HTTP 201:

```json
{
  "case": {
    "id": 9,
    "status": "open",
    "report_count": 2
  }
}
```

Important errors include:

- `message_not_found`: the message does not exist or the requester cannot access it;
- `message_not_reportable`: deleted, system-authored, self-authored, or empty content;
- `report_recipient_required`: a DM participant is not the recipient;
- `message_already_reported`: the same account already reported this case;
- `rate_limited`: the `message_report` policy rejected the attempt.

## `GET /api/v1/moderation/cases.php`

Query parameters:

- `status`: `open`, `in_review`, `resolved`, `dismissed`, or `all`; default `open`;
- `before_id`: optional exclusive case-ID cursor;
- `limit`: 1-100; default 50.

The response contains only cases within the moderator's current authorization scope:

```json
{
  "cases": [
    {
      "id": 9,
      "message_kind": "room",
      "message_id": 148,
      "room": {"id": 4, "name": "Operations"},
      "subject": {"id": 7, "username": "Alice"},
      "status": "open",
      "assigned_to": null,
      "resolution_code": null,
      "report_count": 2,
      "first_reported_at": "2026-07-18T12:00:00+00:00",
      "last_reported_at": "2026-07-18T12:03:00+00:00",
      "resolved_at": null
    }
  ],
  "has_more": false,
  "next_before_id": null
}
```

## `GET /api/v1/moderation/case.php`

Required query parameter: `case_id`.

Returns the case summary plus `resolution_note`, `resolved_by`, and ordered report entries. Each report entry contains the reporter, fixed category, optional details, immutable `evidence_body`, bounded structural `evidence`, and submission timestamp.

For a DM case, no sender/recipient conversation export or adjacent history is returned.

## `POST /api/v1/moderation/claim.php`

```json
{
  "case_id": 9,
  "claim": true
}
```

Claiming an open case sets it to `in_review` and assigns the current moderator. Claiming a case already assigned to another moderator returns `moderation_case_assigned`. `claim: false` releases the assignment and returns the case to `open`; the assigned moderator or a global moderator may release it.

Closed cases cannot be assigned.

## `POST /api/v1/moderation/resolve.php`

Resolve a case after the underlying moderation action has been performed through the ordinary room or account controls:

```json
{
  "case_id": 9,
  "status": "resolved",
  "resolution_code": "user_warned",
  "resolution_note": "Handled through the account moderation workflow."
}
```

`status` must be `resolved` or `dismissed`. Resolution codes are:

- `no_violation` — required for `dismissed`;
- `content_removed`;
- `user_warned`;
- `account_restricted`;
- `other` — requires a nonempty resolution note.

The endpoint records the moderation outcome; it does not itself delete content, warn a user, or change account state. Those actions remain explicit operations with their own authorization and audit behavior.

## Retention

Open and in-review evidence survives canonical room-message or DM cleanup so a pending case cannot lose its evidence through ordinary retention.

Closing a case creates `moderation.case_closed` and links the case to that exact audit row inside the same transaction. PostgreSQL prevents a closed case without this link. When audit retention later deletes the closure row, the case and report snapshots are deleted by foreign-key cascade.

A newly submitted report reopens a closed case and clears the old audit link, preventing an earlier closure record from deleting active evidence. See [`../operations/maintenance.md`](../operations/maintenance.md).
