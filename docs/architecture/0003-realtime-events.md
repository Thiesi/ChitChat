# ADR 0003: Use database-backed Server-Sent Events

- Status: Accepted
- Date: 2026-07-16

## Context

ChitChat needs ordered realtime delivery, reconnect support and compatibility with multiple PHP workers without introducing a separate message broker in the first release.

## Decision

The browser will receive realtime events through Server-Sent Events. Persistent events will use one monotonically increasing event ID stored in PostgreSQL. Browsers reconnect with the last confirmed event ID.

Room history is fetched through a paginated JSON endpoint. SSE delivers new events and does not replace history pagination.

The stream will:

- authenticate the session when opened;
- filter events by user and room visibility;
- send heartbeat comments;
- periodically revalidate sessions and bans;
- support forced logout events;
- tolerate duplicate delivery at the transport layer while clients deduplicate by event ID.

## Consequences

The initial implementation remains operationally simple. A future Redis or WebSocket transport can replace event delivery without changing the logical event contract.
