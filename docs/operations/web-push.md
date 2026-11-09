# Web Push

Web Push sends best-effort alerts from the durable account-notification timeline to browser and compatible UnifiedPush subscriptions. It stays disabled until the installation has a VAPID keypair. The timeline is authoritative; push acceptance is neither a delivery receipt nor a read receipt.

## Enablement

Generate a VAPID keypair once, keep the private key secret, and configure:

```env
WEB_PUSH_VAPID_PUBLIC_KEY=<public key>
WEB_PUSH_VAPID_PRIVATE_KEY=<private key>
WEB_PUSH_VAPID_SUBJECT=mailto:admin@example.org
```

The subject is a mailto or HTTPS contact for the operator. Use the installation's existing keypair when upgrading; changing it can require clients to subscribe again. The dispatcher validates configuration before claiming work.

## Dispatch and scheduling

Run `php bin/dispatch-web-push` (or `composer dispatch-web-push`). PostgreSQL stores event jobs and a separate outcome for every snapshotted subscription; Redis and another sender are unnecessary. Existing processed notifications are never replayed.

A short transaction claims an event for one device attempt using `FOR UPDATE SKIP LOCKED`, a random token and a 60-second lease. HTTP runs after commit with 3-second connection and 10-second total timeouts. Completion is token-fenced; an expired worker cannot overwrite its successor's result or remove a rotated registration.

Only subscriptions registered by the notification's creation time are included. New devices do not receive historical alerts. A notification with no subscriptions is terminal. Read notifications are skipped before job creation and checked again before every send/retry, alongside current preferences, quiet hours, recipient status and source authorization.

Lifetimes, absolute from notification creation:

| Kind | Lifetime | Urgency |
| --- | --- | --- |
| Mention, ping (and the future opt-in DM kind) | 30 minutes | high |
| Other pushed kinds | 6 hours | normal |

The wire TTL is the remaining whole seconds, so retrying never restarts the lifetime. These constants live together in `PushDeliveryPolicy`.

Retry network failures, HTTP 408/429 and 5xx, at most five attempts, with exponential delays starting at 15 seconds and capped at 120 seconds plus small jitter. Valid Retry-After seconds or HTTP dates take precedence when longer. A retry outside the deadline expires instead. Successful devices are not retried because another device failed.

HTTP 404/410 removes only the exact expired registration; other 4xx, including 401/403, fail that delivery without unsubscribing the device. Redirects are not followed or counted as acceptance. Diagnostics contain status/outcome codes, not provider response text, endpoints, keys or notification contents.

Schedule every 15 seconds for low worker delay. The supplied systemd service/timer can be adapted with the installation user and paths:

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now chitchat-web-push.timer
systemctl list-timers chitchat-web-push.timer
```

A once-per-minute cron remains supported but adds up to a minute of scheduling latency:

```cron
* * * * * cd /srv/chitchat && /usr/bin/php bin/dispatch-web-push >> var/log/web-push.log 2>&1
```

Multiple workers may overlap safely; one scheduled worker suffices for a small installation. Each invocation is bounded to 500 new notifications and 500 device claims (service limits can be set between 1 and 5000). Under sustained load, monitor queue age and increase worker capacity. A provider timeout occupies one claim until completion or lease expiry.

## Outcomes and monitoring

The command returns JSON counters. Existing `processed`, `sent`, `skipped_muted`, `skipped_quiet_hours` and `pruned_subscriptions` remain; `sent` means provider accepted, not a handset receipt. Additions include `retried`, `failed`, `expired`, `stale_results`, `pruned_jobs`, reason-specific `skipped_*`, `pending_deliveries`, `due_deliveries`, `failed_deliveries` and `oldest_pending_seconds`.

Alert on growing due work/queue age or repeated terminal failures. A count of old pending deliveries after a stopped scheduler is not proof of an unreachable device. Disabled installations return zero counters without touching notifications.

`account_notifications.push_dispatched_at` now means all selected device outcomes are terminal; it remains null while retries are pending. `push_outcome` describes the terminal processing outcome. The timeline's `read_at` remains independent. Terminal job/delivery history is removed after seven days by the dispatcher; it never stores copies of endpoints, encryption keys or payloads. Physical account deletion cascades through queue records; deleting source notifications removes their mappings, and the next sweep removes orphan jobs.

## Preferences and privacy

Mention and ping preferences retain their existing defaults. Quiet hours suppress every category uniformly and terminally: there is no deferred morning replay. Security/moderation notices do not inherit a social-alert preference or a sender's posting mute. Privacy-policy update notices remain in-app only.

Before social delivery, the worker rechecks the original message/ping, account and ban state, room history/age or DM participant/block eligibility, source deletion and relevant sender restrictions. Room ignores apply to room mentions and pings; they do not replace DM blocking. A conversation already marked read cannot produce a late DM mention alert.

Payload keys remain `title`, `body`, `link`, `app_name`. Rendered sender/room names may appear, but message bodies, captions and filenames never do. Encryption to the push provider is not chat end-to-end encryption.

Provider acceptance followed by worker death is ambiguous. Reclaimed attempts can duplicate an already accepted push, and notifications accepted before a read/block/logout cannot be recalled. Updated client deduplication/binding is separate lifecycle work; this release promises neither exactly-once alerts nor delivery to an offline/permission-disabled device.

## Dependencies and interoperability

The worker uses the locked `minishlink/web-push` encryption and VAPID signer with an explicit `php-http/curl-client` PSR-18 transport (`ext-curl` required). A project-owned request adapter corrects v11's VAPID audience for HTTPS services on non-default ports while preserving the encrypted request. No vendor files, FCM server sender or relay are added. TLS validation stays enabled; environment proxies and redirects are disabled.

Automated request/signature and queue tests do not prove native background delivery. Real browser delivery plus two compatible distributor/device deliveries are a separate acceptance gate coordinated with CCM. See [the API contract](../api/web-push.md) and [ADR 0006](../architecture/0006-web-push.md).
