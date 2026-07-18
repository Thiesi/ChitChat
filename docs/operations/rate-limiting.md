# Rate limiting

ChitChat uses PostgreSQL-backed fixed-window rate limiting. Every PHP worker therefore applies the same policy without requiring Redis or in-process state.

## Security and privacy model

Each limited action has a fixed, named policy. The active row in `request_rate_limits` stores only:

- the policy name;
- a SHA-256 hash of the account/IP-derived identifier;
- the current window start;
- the attempt count and update time.

The separate `rate_limit_counters` table stores only aggregate policy outcomes. It contains no account ID, username, IP address, room ID, message ID, attachment name, search term, or request body.

A request that exceeds a policy receives HTTP 429 with `rate_limited`, except for the established login path, which continues to return `login_throttled`.

## Configuration

Every policy is configured by two environment variables:

```text
RATE_LIMIT_<POLICY>_MAX_ATTEMPTS
RATE_LIMIT_<POLICY>_WINDOW_SECONDS
```

Values are validated at application startup. Invalid integers and values outside the policy-specific safety bounds prevent startup rather than silently disabling protection.

`LOGIN_MAX_ATTEMPTS` and `LOGIN_LOCK_MINUTES` remain supported for compatibility. They supply the default named `login` policy when the corresponding `RATE_LIMIT_LOGIN_*` variables are absent. The named variables take precedence.

| Policy | Default | Protected actions |
| --- | ---: | --- |
| `login` | 10 / 900 s | Login requests after the existing username/IP failed-attempt lookup |
| `registration` | 5 / 3600 s | Account registration by source IP |
| `privileged_step_up` | 10 / 900 s | Current-password step-up by account and IP |
| `personal_data_export` | 5 / 3600 s | Personal-data export |
| `account_restore` | 5 / 3600 s | Explicit cooling-off restoration by canonical username and source IP |
| `room_send` | 30 / 60 s | Ordinary room messages |
| `room_ping` | 30 / 60 s | `/ping` commands, counted independently from ordinary messages |
| `room_message_mutation` | 30 / 60 s | Author room-message edits and deletions |
| `direct_message_send` | 30 / 60 s | Direct-message sends |
| `direct_message_mutation` | 30 / 60 s | Direct-message edits and delete-for-everyone |
| `attachment_upload` | 10 / 3600 s | Room attachment uploads |
| `direct_message_attachment_upload` | 10 / 3600 s | Direct-message attachment uploads |
| `room_invite` | 60 / 3600 s | Room invitations |
| `direct_message_user_search` | 120 / 60 s | Participant-facing username search |
| `message_search` | 60 / 60 s | Participant full-text search over authorized current room and direct-message bodies |
| `admin_user_search` | 120 / 60 s | Administrator user listing/search |
| `room_invitable_user_search` | 120 / 60 s | Room-scoped invitation candidate search |
| `admin_direct_message_user_search` | 120 / 60 s | Administrative DM participant search |
| `admin_direct_message_inspection` | 60 / 3600 s | Audited DM inspection pages |
| `message_revision_review` | 60 / 3600 s | Audited exact-message revision review |

The complete variable list with current defaults is in `.env.example`.

## Why policies are environment-only

Rate limits are deployment security controls rather than ordinary application preferences. ChitChat therefore does not expose browser-admin mutation of these values in this milestone. This prevents a compromised Administrator session from weakening throttles and keeps changes reviewable in the deployment configuration.

Changing a value requires deploying the updated environment and restarting the PHP application workers. Existing fixed-window rows remain valid; the new maximum and window are applied on the next request.

## Observability

The Administrator system-status response includes:

- `security.rate_limit_policies`: the effective maximum and window for every policy;
- `security.rate_limit_decisions`: aggregate allowed/rejected totals and last-decision timestamps for policies that have been exercised;
- `security.rate_limit_rows`: the number of active identifier/window rows.

When the Prometheus endpoint is enabled, it exports:

```text
chitchat_rate_limit_decisions_total{policy="room_send",outcome="allowed"} 123
chitchat_rate_limit_decisions_total{policy="room_send",outcome="rejected"} 4
```

The `policy` label has a fixed application-defined vocabulary, so it does not create user-controlled metric cardinality. For search policies, only the fixed policy name and coarse allowed/rejected totals are exported; the query itself is never a label or stored aggregate field.

## Tuning guidance

- Increase limits only after checking rejected counters and confirming legitimate traffic is affected.
- Prefer increasing the maximum modestly before shortening a window.
- Keep registration, restoration, uploads, step-up, exports, and administrative content access substantially tighter than ordinary message sends.
- Treat sudden increases in rejected login, restoration, step-up, inspection, or revision-review decisions as a security signal.
- Do not disable a policy by setting zero; zero is rejected at startup.

Rate limiting is defense in depth. It does not replace authorization, CSRF validation, block relationships, upload validation, privileged step-up, required reasons, or audit logging.
