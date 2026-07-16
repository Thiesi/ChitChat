# ADR 0002: Freeze the initial v1 product decisions

- Status: Accepted
- Date: 2026-07-16

## Decisions

1. PostgreSQL is the only database supported by v1. MySQL may be added after v1 is stable.
2. Direct-message inspection is configurable, restricted to Super-Administrators by default, disclosed to users and fully audited.
3. Private rooms are invitation-only.
4. Room and direct-message history is retained permanently by default. Configurable retention may be added later.
5. Normal message editing is not part of v1. Audited moderator deletion may be added.
6. The only chat commands in the first release are `/me` and `/ping`.
7. Authorization is enforced on the server. Hidden or disabled browser controls are never security boundaries.
8. All timestamps are stored in UTC; user timezones only affect display.
9. Attachments are stored outside the public web root and served through an authorization-aware endpoint.
