# Participant message-search API

ChitChat provides signed-in full-text search over current message bodies that the requesting account is permitted to discover and read. Search is a participant feature, not an administrative inspection mechanism.

## Privacy and authorization model

The search query applies the same substantive boundaries as ordinary history access before a result can be returned:

- ordinary users may search public rooms;
- private rooms require current membership;
- unlisted rooms require membership or a current invitation so search does not reveal an otherwise undiscoverable room;
- room minimum-age requirements apply unless the account has a global room-moderation role;
- direct messages require the requesting account to be either sender or recipient.

Only the canonical current body is indexed and returned. Messages with `deleted_at` set are excluded. The append-only room and direct-message revision tables are never joined, so previous edited bodies and deleted historical bodies cannot be discovered through participant search.

Search terms are consumed by the named `message_search` rate-limit policy. The plaintext term is not copied into the audit log, rate-limit identifier row, aggregate rate-limit counters, or Prometheus labels. The browser sends the term in a CSRF-protected JSON POST body and does not place it in the address bar, reducing exposure through browser history and ordinary URL-based reverse-proxy access logs. Operators remain responsible for avoiding request-body logging at the proxy, PHP, APM, or debugging layer.

## `POST /api/v1/search/messages.php`

Authentication and the current `X-CSRF-Token` are required.

Request body:

```json
{
  "query": "deployment rehearsal",
  "scope": "all",
  "limit": 25,
  "offset": 0,
  "room_id": 4
}
```

Fields:

- `query`: required, 2-200 Unicode characters after whitespace normalization, containing at least one letter or number;
- `scope`: optional `all`, `rooms`, or `direct`; default `all`;
- `limit`: optional, 1-50; default 25;
- `offset`: optional, 0-5000; default 0;
- `room_id`: optional; searches only that room (#283). `scope` must then be `all` or `rooms` (`direct` is a `400 validation_error`), and no direct messages are returned.

**A `room_id` the caller can't search returns `404 room_not_found`**, exactly as for a room that doesn't exist. That covers a deleted room, a private room without membership, an unlisted room without membership or a current invitation, and a room whose minimum age the caller doesn't meet (global room moderators bypass the visibility and age checks, as everywhere in search). The filter uses the same rules as the results, so it never confirms that a room exists. This differs from `GET /api/v1/rooms/messages.php`, which answers `403 room_forbidden` for a private room, because search is stricter than history.

Search uses PostgreSQL `websearch_to_tsquery` and the `simple` text-search configuration. This provides quoted phrases and ordinary web-style term syntax without language-specific stemming. Results are ordered by full-text relevance, then newest first.

Example response:

```json
{
  "results": [
    {
      "kind": "room",
      "message_id": 148,
      "excerpt": "The deployment rehearsal completed successfully.",
      "excerpt_matches": [{"start": 4, "length": 10}],
      "sender": {"id": 7, "username": "Alice"},
      "room": {"id": 4, "key": "operations", "name": "Operations"},
      "peer": null,
      "outgoing": null,
      "created_at": "2026-07-18T10:45:00+00:00",
      "edited_at": null
    },
    {
      "kind": "direct",
      "message_id": 92,
      "excerpt": "I saved the deployment notes in the shared folder.",
      "excerpt_matches": [{"start": 12, "length": 10}],
      "sender": {"id": 7, "username": "Alice"},
      "room": null,
      "peer": {"id": 12, "username": "Bob"},
      "outgoing": true,
      "created_at": "2026-07-18T09:12:00+00:00",
      "edited_at": "2026-07-18T09:14:00+00:00"
    }
  ],
  "has_more": false,
  "next_offset": null
}
```

### Excerpt and matches

`excerpt` is plain text, whitespace-normalized, and at most 320 characters (Unicode code points). It never contains markup, so clients must render it as text.

- **A message of up to 320 characters** is returned whole.
- **A longer message** gets a window around its **first match** (#283): up to about 100 characters of context before the match, starting at a word, then as much as fits, cut back to a word boundary. `… ` is added at the start and ` …` at the end only where text was cut. Both count towards the 320. When the match is near the end, the window ends at the end of the message and uses the whole budget. Before #283, the excerpt was always the first 320 characters, so a match further in wasn't visible.
- **If no match position can be determined**, the excerpt is the first 319 characters plus `…`, as before, and `excerpt_matches` is empty.

`excerpt_matches` lists every match that lies wholly inside the excerpt, in order, as `{"start", "length"}`. **Offsets and lengths are in UTF-16 code units**, the unit that JavaScript (`String.prototype.slice`) and Kotlin/Java strings index by, and they already account for a leading `… `. An emoji counts as two:

```json
{ "excerpt": "Party 🎉 deployment done", "excerpt_matches": [{"start": 9, "length": 10}] }
```

Here `excerpt.slice(9, 19)` is `"deployment"`: `Party ` is 6 units, 🎉 is 2, and the space is 1.

**What counts as a match:** PostgreSQL decides it, with the same `websearch_to_tsquery('simple', …)` as the search itself: whole words, case-insensitive, no stemming.
- Every occurrence of an included word is a match.
- A quoted phrase marks each of its words wherever they occur, not only where they form the phrase.
- With `OR`, the alternatives that occur are marked.
- A word excluded with `-` is never marked, even when the message matched through `OR`, for example `apple OR -banana`.

Matches are computed only for the page of results returned (in the order of 5 ms for 50 messages of about 4,700 characters).

For a room result, `room` is present and `peer`/`outgoing` are null. For a direct-message result, `peer` identifies the other participant and `outgoing` is calculated from the requesting account's perspective.

When another page exists, `has_more` is true and `next_offset` contains the offset for the next request. Offset pagination is deliberately bounded; the endpoint is designed for interactive retrieval rather than bulk export.
