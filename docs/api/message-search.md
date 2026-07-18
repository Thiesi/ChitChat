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
  "offset": 0
}
```

Fields:

- `query`: required, 2-200 Unicode characters after whitespace normalization, containing at least one letter or number;
- `scope`: optional `all`, `rooms`, or `direct`; default `all`;
- `limit`: optional, 1-50; default 25;
- `offset`: optional, 0-5000; default 0.

Search uses PostgreSQL `websearch_to_tsquery` and the `simple` text-search configuration. This provides quoted phrases and ordinary web-style term syntax without language-specific stemming. Results are ordered by full-text relevance, then newest first.

Example response:

```json
{
  "results": [
    {
      "kind": "room",
      "message_id": 148,
      "excerpt": "The deployment rehearsal completed successfully.",
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

`excerpt` is whitespace-normalized and limited to 320 characters. It is plain text rather than server-generated HTML and contains no markup highlighting. Clients must render it as text.

For a room result, `room` is present and `peer`/`outgoing` are null. For a direct-message result, `peer` identifies the other participant and `outgoing` is calculated from the requesting account's perspective.

When another page exists, `has_more` is true and `next_offset` contains the offset for the next request. Offset pagination is deliberately bounded; the endpoint is designed for interactive retrieval rather than bulk export.
