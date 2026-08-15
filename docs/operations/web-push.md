# Web Push

Web Push delivers a best-effort browser notification for selected account events even when no ChitChat tab is open. It is disabled until an operator configures a VAPID keypair. See [ADR 0006](../architecture/0006-web-push.md) for the full design.

Push is never a delivery guarantee and never a source of truth. The durable in-app notification timeline (`account_notifications`) is authoritative; push is a best-effort nudge derived from it.

## Enabling Web Push

Generate a VAPID keypair once per installation and keep the private key secret:

```sh
npx web-push generate-vapid-keys
```

Set all three values in `.env`:

```env
WEB_PUSH_VAPID_PUBLIC_KEY=<generated public key>
WEB_PUSH_VAPID_PRIVATE_KEY=<generated private key>
WEB_PUSH_VAPID_SUBJECT=mailto:admin@example.org
```

`WEB_PUSH_VAPID_SUBJECT` must be a `mailto:` address or an `https:` URL — it is a contact a push service may use to reach the operator, not shown to participants. Web Push stays inert (no subscribe affordance offered, `bin/dispatch-web-push` exits immediately) unless the public and private keys are both configured, mirroring how passkeys stay inert without `WEBAUTHN_RP_ID`/`WEBAUTHN_ORIGIN`.

## Dispatch sweep

```sh
composer dispatch-web-push
```

The command sweeps `account_notifications` rows that have never had a push attempt, checks each recipient's quiet hours and (for the one mutable category) mute preference, sends to every active browser subscription for accounts that pass, and marks every row it looked at as attempted — regardless of per-subscription success. A subscription is removed automatically only when the push service reports it permanently gone (HTTP 404/410); any other delivery failure is left alone for the next sweep to retry against, since the failure is about that specific push attempt, not the subscription's validity.

The command prints a JSON summary:

```json
{
  "processed": 3,
  "sent": 2,
  "skipped_muted": 1,
  "skipped_quiet_hours": 0,
  "pruned_subscriptions": 0
}
```

Unlike `composer maintenance`, this command is meant to run **frequently** — every 30-60 seconds — since its entire value is timely delivery while a mention or event is still relevant. It requires no queue or Redis: the `account_notifications.push_dispatched_at IS NULL` filter over an already-durable table is the queue.

## Scheduling

Ready-to-adapt systemd examples are provided in:

```text
deploy/systemd/chitchat-web-push.service
deploy/systemd/chitchat-web-push.timer
```

Copy them to `/etc/systemd/system/`, adapt the user, group, installation path, and PHP path, then enable the timer:

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now chitchat-web-push.timer
systemctl list-timers chitchat-web-push.timer
```

The provided timer runs 30 seconds after boot and every 30 seconds thereafter. A cron equivalent (minimum practical granularity is one minute) remains valid but delivers push less promptly:

```cron
* * * * * cd /srv/chitchat && /usr/bin/php bin/dispatch-web-push >> var/log/web-push.log 2>&1
```

Running the sweep from multiple hosts is safe — each row is claimed by whichever invocation updates it first — but redundant; one scheduled instance is sufficient for the single-application-server deployment target.

## Notification categories and preferences

Five notification kinds exist; only `mentioned` can be muted per account, via `POST /api/v1/push/update-preferences.php`. `revision_review`, `moderator_message_deleted`, `admin_password_reset`, and `system_policy_changed` are security/audit notices and always push when Web Push is configured and the account is outside quiet hours — the same way they are already non-optional in the in-app timeline. An account can still stop receiving any push by removing every subscribed browser.

Quiet hours are a per-account local-time window (`push_quiet_hours_start`, `push_quiet_hours_end`, an hour 0-23 each, plus `push_quiet_hours_timezone`, an IANA identifier) that suppresses every category, mutable or not, uniformly. All three or none may be set. A notification skipped for quiet hours is marked attempted like any other — it is not queued for redelivery once the window ends; the in-app timeline is where a participant catches up on anything push missed.

## Privacy

- A push payload carries only the same sender-username/room-name/title text already considered safe for the in-app timeline — never a raw message body. See `PrivacyNotificationService::renderText()`, the single rendering path both surfaces share.
- Push subscriptions (`push_subscriptions`) and notification preferences (`notification_preferences`) are cleared at the same account-tombstone point durable privacy notifications already are (see [maintenance.md](maintenance.md#account-closure-finalization)).
- The VAPID private key is deployment secret material; treat `.env` accordingly. It is never sent to a browser or logged.

## Dependency

Web Push introduces `minishlink/web-push` (plus a minimal `php-http/curl-client` + `nyholm/psr7` PSR-18/17 implementation) as this project's first production Composer dependency, since VAPID JWT signing and RFC 8291 payload encryption are security-sensitive cryptographic code with no PHP built-in support worth hand-rolling. `ext-curl` is required at runtime for the HTTP client.
