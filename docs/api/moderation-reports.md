# Participant reports and moderation queue API

ChitChat lets a signed-in participant report one specific room/direct message they can read, or a person whose profile they can access. Reports create authorization-scoped moderation cases; they are not an administrative search or general private-message inspection mechanism.

All state-changing endpoints require authentication and the current `X-CSRF-Token`. Queue reads require authentication and a moderation role. Request throttles are documented in [`../operations/rate-limiting.md`](../operations/rate-limiting.md).

## Evidence and privacy model

A successful **message** report stores an immutable snapshot containing:

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

Person cases preserve only the name and the already sanitized 256x256 WebP at
the **first** report, plus the selected room context. The copied image is at
most 128 KiB, enforced in the database; upload originals and other profile
data are never copied. A new report on the same case does not replace that
snapshot. Its bytes live with the case in PostgreSQL and follow the existing
case/audit retention cascade, independently of avatar file replacement,
account closure or attachment cleanup. A reporter's personal export includes
their own report metadata/details, never these moderator-only image bytes or
another reporter's details.

## `POST /api/v1/reports/user.php`

Authentication (member or guest) and CSRF are required:

```json
{
  "user_id": 7,
  "category": "offensive_username",
  "details": "Optional explanation",
  "context": {"room_id": 4}
}
```

Members may omit `context` or send `null` for a global report. Guests **must**
provide a room context. Each room context requires current history access,
minimum-age/guest eligibility, and a link to the subject through membership,
unexpired visible presence, or a message in that room visible to the reporter.
Leaving the room does not prevent reporting from an old message's profile
card. An unrelated, private/unreadable, or deleted room is refused; it does
not silently become a global report. Ignoring/blocking does not remove this
safety action. Members can globally report any active profile, matching the
existing profile visibility rule. Self-reporting is refused; closed/closing
subjects return 404.

Person categories include the existing message categories plus
`offensive_username` and `offensive_profile_picture`. These two are
**person-only**; the latter is invalid when the live account has no picture.
Details are trimmed, valid UTF-8 and at most 1,000 characters. Success is
HTTP 201 with the existing `{case:{id,status,report_count}}` shape.

Cases are keyed by `(subject, immutable scope)`: `room:<id>` or `global`.
One report per reporter applies **per case**, including after closure.
The advisory `reported_by_me` stays true in every status while that case is retained.
Another reporter may reopen a closed case without replacing its original
snapshot. Separate rooms and global reports never share counts, details,
assignment or resolution.

Errors:

- 400 `validation_error`: invalid IDs/context/category/details, or a picture
  category without a current picture;
- 403 `report_not_allowed`: self, missing guest context or invalid room access/link;
- 404 `user_not_found`: unavailable subject;
- 409 `already_reported`: this identity already reported this case;
- 409 `report_snapshot_unavailable`: stored picture missing or over the
  128 KiB evidence cap; the transaction leaves no partial case/report;
- 429 `rate_limited`: dedicated `user_report` attempt budget (default 10/hour),
  including matching `Retry-After` and `error.retry_after_seconds`.

Reporting remains available while new rules await acceptance.

## Authorization

### Room reports

The participant must pass the same room-history and minimum-age checks as an ordinary history request. The message must be undeleted, authored by another user, and not a system message.

Room owners and room moderators may list and review cases only for rooms where they currently hold `owner` or `moderator` membership. Global roles may review all room cases.

### Person cases

Global moderation roles can review every person case. Room owners/moderators
can review only cases whose **live** room reference is in their current scope.
The same check protects list/detail, claim/release, resolution and copied
avatar serving. No outside-scope report counts or hints are returned.

A room-scoped person's `room` remains `{id,name,available}` after physical
purge, using its original snapshot with `available:false`; clients show the
name as historical and do not link to it. Only global reviewers retain access
after purge. `room:null` means global scope only. Message-case room fields
are unchanged.

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
- `subject_kind`: optional `message` or `user`; omitted means both;
- `before_id`: optional exclusive case-ID cursor;
- `limit`: 1-100; default 50.

The response contains only cases within the moderator's current authorization scope:

```json
{
  "cases": [
    {
      "id": 9,
      "subject_kind": "message",
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

Person items add `subject_kind:"user"`; their `message_kind` and `message_id`
are null. Their subject uses the current account name, and their counts,
timestamps and assignment have the same shape. Existing message items add
`subject_kind:"message"` and retain their existing fields.

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


## Person detail and copied profile picture

Person `case.php` details add:

```json
{
  "snapshot": {
    "username": "Name at first report",
    "avatar_url": "/api/v1/moderation/case-avatar.php?case_id=9"
  },
  "avatar_changed_since_report": true
}
```

`avatar_url` is null if there was no first-report picture. The change flag
compares the first picture's version/key with the live avatar, including
replacement/removal/closure. Person reports contain `id`, `reporter`,
`category`, `details` and `created_at`, with no `evidence_body` or message
evidence object.

`GET /api/v1/moderation/case-avatar.php?case_id=9` rechecks the same case
authorization before loading bytes. Success is fixed `image/webp` with
`Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`, and
same-origin resource protection. There is no ETag/304 shortcut around
authorization. Anonymous requests receive 401; out-of-scope/revoked viewers
403; a missing case or picture 404. It grants no access to the subject's
other pictures or conversations.

Resolution adds `profile_picture_removed` and `username_change_required` for
person cases only. `moderation/require-username-change.php` with `case_id`
resolves the case with `username_change_required` in the same step as replacing
the name ([required username changes](username-change.md)). Resolution
records an outcome; actual avatar removal, muting or banning still use their
own existing authorization/endpoints. Being allowed to see a snapshot never
implies permission to act on the account. Reporters receive no outcome
notification.

Client check: presence of `profile.can_report` advertises person reporting;
case items advertise their shape with `subject_kind`. Missing fields on an
older server mean unknown. Deploy the paired person-report web UI before
relying on its browser entry points and evidence presentation.

## Subject history filter

`GET /api/v1/moderation/cases.php?subject_user_id=N` selects both message and
person cases whose stored subject is N. Existing `status` (including `all`),
`subject_kind`, `before_id`, `limit`, list items and pagination fields keep
their meanings. An absent subject parameter preserves the previous response.
Nonpositive IDs return 400 `validation_error`; no matching subject returns an
empty list and zero counts.

When `subject_user_id` is supplied, the response adds:

```json
{"counts":{"open":1,"in_review":0,"resolved":0,"dismissed":1}}
```

Counts cover that subject and the optional `subject_kind`, across statuses and
pages: they ignore `status`, `before_id` and `limit`. Both rows and counts use
the same existing review-scope predicate. Room moderators see only cases in
rooms they currently own or moderate, never direct-message or global person
cases; global review access is unchanged. Counts do not reveal cases outside
the caller's scope. Queue access is still required even for an empty result.

This is a filtered queue summary, not a case-detail endpoint. It does not add
message bodies, report explanations, reporter identities, snapshot bytes or
other evidence to the list. Case details and snapshot-avatar access retain
their own authorization.

Client check: `counts` in a successful subject-filtered response identifies
subject-history support; missing counts mean unknown/older support. Do not
treat older unfiltered results as an account's history.
