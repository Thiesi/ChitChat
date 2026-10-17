# ADR 0007: Mobile (Android) app strategy

- Status: Proposed; nothing built yet
- Date: 2026-10-17

## Context

The maintainer asked whether an Android app that connects to a ChitChat instance would work well on a phone, without draining the battery or using a lot of mobile data, and how much effort a native app would be compared with a Progressive Web App (PWA). This ADR records the assessment so the decision can be picked up later without redoing it.

### What the current realtime design costs on a phone

- `public/api/v1/events/stream.php` serves Server-Sent Events. Each request lasts at most 25 seconds; the server checks for new events every 0.5 seconds and sends a heartbeat comment every 10 seconds when idle. The browser then reconnects, and missed events are replayed with `Last-Event-ID` ([ADR 0003](0003-realtime-events.md)).
- `public/assets/js/presence.js` sends a presence heartbeat every 20 seconds. On `visibilitychange` it only refreshes when the page becomes visible; the timer keeps running while the page is hidden.
- Web Push already exists ([ADR 0006](0006-web-push.md)), with per-category preferences, quiet hours and a per-device subscription list, delivered through `public/sw.js`.
- No web app manifest exists, so ChitChat cannot be installed to an Android home screen as an app.

### Assessment

**Mobile data is not a concern.** Messages are a few hundred bytes of JSON. One SSE reconnect every 25 seconds plus one presence request every 20 seconds comes to roughly 0.5–1 MB per hour while the app is open in the foreground. Images and avatars outweigh this and can be cached.

**Battery depends entirely on background behaviour.**

- In the foreground, cost is comparable to any messenger.
- In the background, keeping the event stream open is the classic drain pattern: every heartbeat or reconnect wakes the cellular radio, which then stays in its high-power state for several seconds. Android's Doze mode would also kill the connection anyway.
- The correct pattern is to close the stream and stop timers when backgrounded, let push wake the device only for things that matter (mentions, pings, direct messages), and catch up with `Last-Event-ID` on resume. The server already supports every part of this.

**Push is the hard part of a native app, not chat.** A native Android app receives push through Firebase Cloud Messaging (FCM), and FCM credentials belong to whoever publishes the app. For one app to connect to any self-hosted instance, every instance would need to send through that publisher's Firebase project. That requires a central push relay operated by the app publisher, as Matrix and Mastodon clients do, and optionally UnifiedPush for devices without Google services. Web Push in Chrome on Android already goes through FCM without any of this.

### Effort comparison

These are rough estimates for one developer working with an AI assistant, not measurements.

**PWA: days, up to about 1–2 weeks.**

- Web app manifest, icons and install behaviour: about a day.
- Close the SSE stream and pause the presence timer while the page is hidden, then resume with `Last-Event-ID`: about a day plus testing.
- Phone-width layout fixes: unknown until audited; between a day and a week. This is the largest unknown on the PWA side.
- Every existing and future feature reaches mobile automatically.

**Native Android app (Kotlin): months, plus permanent overhead.**

Server-side prerequisites:

- Token-based authentication for non-browser clients. The current API appears to use browser sessions with CSRF tokens. Google and Twitch sign-in need an app redirect flow, and step-up confirmation needs a native equivalent.
- An FCM sender alongside Web Push, plus the push-relay question above.
- API versioning, because old app versions stay installed for months.

Client work:

| Stage | Includes | Rough effort |
|---|---|---|
| Basic first version | Sign-in, room list, message view with history loading, sending, direct messages, mentions and pings, unread counts, live-connection lifecycle, push notifications | ~4–8 weeks |
| Feature parity | Name menu, mention completion, avatars, typing indicator, presence, search, colour schemes, date formats, guest access, account settings, room creation | another ~2–3 months |
| Kept on the web | Administration and moderation pages | none |

Ongoing overhead: every feature is built and released twice; Play Store requirements (a one-off developer account fee, a data-safety form, review for each release, a yearly target-SDK update); a second test suite; a signing key to protect; crash reports from devices nobody on the project owns.

Overall, a native app is roughly 10–20 times the up-front effort of the PWA, and the per-feature overhead after that matters more.

**Middle options: wrap the PWA.**

- **Trusted Web Activity** (Google's Bubblewrap tool) publishes the PWA to the Play Store with almost no extra code. Notifications remain Chrome Web Push.
- **Capacitor** wraps the same web code in a native shell and adds native FCM push, notification reply actions and share-to-ChitChat while sharing about 95% of the code. That is roughly 1–2 extra weeks on top of the PWA, but it brings back the push-relay question.

## Decision (proposed)

1. Build the PWA first:
   - add a web app manifest and icons;
   - close the SSE stream and stop the presence timer while the page is hidden, and resume with `Last-Event-ID` when it becomes visible;
   - audit and fix the layout at phone width.
2. If a Play Store listing or native-only capabilities are wanted later, wrap the PWA with Capacitor rather than writing a separate native client.
3. Write a fully native client only if ChitChat becomes something many independent operators host and their users expect a polished native app. Design the push relay before anything else in that case.

## Consequences

- Mobile support stays a single codebase; no second client to keep in step with the API.
- Push stays on the existing Web Push path with no central infrastructure.
- Native-only extras (inline notification replies, more reliable delivery on vendors with aggressive battery optimisation) stay unavailable until a wrapper is adopted.
