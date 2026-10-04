# Changelog

All notable changes to the reconstructed ChitChat application are documented here.

The project uses semantic versioning. Release-candidate versions are pre-releases and may still require schema or configuration changes before a stable release.

## [Unreleased]

ChitChat is feature-complete; see [Project status](README.md#project-status-feature-complete) and the [roadmap](docs/roadmap.md) for the maintenance-only policy going forward. Entries only appear here for a discovered bug fix or an accepted new feature request.

### Added

- Added **Delete** on other people's messages in rooms for everyone allowed to moderate the room, so a moderator no longer has to report a message to act on it. It asks for confirmation with an optional reason for the audit log; everyone sees "Message deleted by a moderator." and the author is told as before.

### Changed

- Moderation now follows ranks: member, room moderator, room owner, Chat Admin and Global Moderator, Administrator, Super-Administrator. Moderators act only on lower ranks, so a room moderator can no longer delete the room owner's or global staff's messages, and an owner no longer global staff's. Only Super-Administrators act on their equals, which also means only a Super-Administrator may sign out, ban, reset the password of, or change the roles of an Administrator.
- Signing someone out, banning, and lifting a ban no longer ask for the moderator's password (privileged step-up). They are reversible and audited; changing roles and resetting passwords still ask.

- Google and Twitch buttons (sign-in, restore, Sign-in methods, confirming a sensitive action, and the picture button) now show the provider's logo, drawn in the page, so nothing is fetched from either company.
### Fixed

- Fixed the Report message dialog's buttons looking different from each other in rooms and direct messages (#126).

## [3.0.0] - 2026-10-04

Usability release with thirteen forward-only database migrations (`0025` to `0037`); back up before upgrading. See the [`v3.0.0` release notes](docs/releases/v3.0.0.md).

### Added

- Added a typing indicator: "Alex is typing…" above the composer in rooms and direct conversations. It carries no text, expires within seconds, is sent for room members only (and in direct conversations only while the two may message each other), never for `/commands`, and never shows people you ignore. It can be turned off on the Account page, and then works both ways: nobody sees you typing, and you see nobody typing. Migrations `0036_typing_event.sql` and `0037_share_typing.sql`.
- Added slash commands in rooms: typing `/` lists them, like `@` lists people. New are `/help` (commands and formatting), `/dm name message` (send a direct message without leaving the room, or open the conversation), `/topic text` (change the room's info line; room owners and room administrators) and `/shrug message`; `/me` and `/ping` are unchanged. A formatting marker right after a backslash now stays literal, so `¯\_(ツ)_/¯` survives.
- Added **Use my Google picture** / **Use my Twitch picture** on the Account page's Profile picture card, for connected providers. Only that click asks the provider for the picture, never sign-in or sign-up; the server fetches it once from the provider's own image host, and it goes through the usual crop step and re-encoding. Migration `0035_avatar_imports.sql` keeps it for up to 15 minutes in between.
- Added message formatting and clickable links. Messages show `*bold*`, `_italic_`, `` `code` ``, fenced code blocks and `> ` quotes, and http/https links open in a new tab (`rel="noopener noreferrer nofollow"`; no link previews). Formatting is built as page elements, never HTML, and markers only count when they hug a word, so `2 * 3 * 4` and `snake_case` stay as typed. Stored message text is unchanged.
- Added unread markers for rooms. Joined rooms with new messages are bold in the room list with a count (up to "99+"); opening one shows a **New messages** divider above the first unread message. Reading further up no longer gets interrupted by arriving messages: a **Jump to latest** button appears instead. The read position is kept per account on the server (`POST /api/v1/rooms/read.php`; migration `0033_room_reads.sql`).
- Added ignoring people in rooms, from the profile card (**Ignore in rooms** / **Stop ignoring**). Their room messages collapse to a single line you can open, their pings are hidden, and their mentions and pings no longer notify you or count as unread. They are not told, and nothing changes for anyone else; direct messages keep using blocking. Migration `0034_user_ignores.sql`.
- Added signing in and signing up with Google or Twitch. Connect a provider on the Account page, then use **Continue with Google/Twitch** on the sign-in page; someone new continues with a provider, chooses a username and has an account without a password. Such accounts confirm sensitive actions by signing in with the provider again in a small window, can set a password later on the Account page, and restore a closing account through the provider. ChitChat asks the provider only for an account identifier (not anonymous: see INSTALL.md), never an email address, name, or picture, and verifies every sign-in token itself. Bans, maintenance lockdown, and multi-factor authentication apply as with a password; connecting or disconnecting requires privileged step-up. Each provider is off until its client ID and secret are configured (see INSTALL.md). Migrations `0031_sign_in_providers.sql` (connected providers, signing keys) and `0032_password_less_accounts.sql` (`users.has_password`).
- Added a Password card to the Account page for changing the password, or setting one on an account created through Google or Twitch.
- Added a maintenance lockdown for Super-Administrators in Operational settings. While it is on, nobody can sign in, register, or restore an account, except Super-Administrators, who can always sign in to switch it off again. A configurable message appears on the sign-in page and as a banner for everyone still signed in (updated live). Switching it on can optionally sign everyone else out at once. It requires privileged step-up and is audited. Migration `0030_maintenance_lockdown.sql` adds the settings.
- Added profile pictures and a profile card. Upload a picture on the Account page and crop it in the browser; ChitChat then shows it instead of your initials next to your messages, in member and conversation lists, and in the account menu. The server stores its own freshly encoded 256 × 256 copy (no photo metadata such as location; JPEG, PNG, or WebP only, never SVG). Clicking any name now opens a small profile card with the person's picture, staff badge, and when they joined, next to **Send direct message**; Global Moderators and administrators can remove an inappropriate picture from it, which the person is told about. Pictures are included in backups and the personal data export and deleted when an account is permanently closed. Profile pictures need the PHP GD extension with WebP support; without it ChitChat keeps showing initials. Migration `0029_avatars.sql` adds the avatar columns and the notification kind.
- Added room deletion, in two stages so mistakes can be undone and evidence is kept. Super-Administrators, Administrators, Chat Admins, and a room's owner (not its moderators) can delete a room on the room administration page; it disappears for everyone at once and its members are notified. It can be restored under **Deleted rooms** until maintenance removes it permanently after a grace period (a new Operational setting, 30 days by default, 0 keeps deleted rooms). Removal takes the room's messages, attachment files, memberships, and pings with it; moderation reports keep their evidence. Deleting and restoring require privileged step-up and are audited. Migration `0028_room_deletion.sql` adds the setting and notification kinds and lets moderation cases outlive a removed room.
- Added a name menu: clicking a person's name (message authors, @mentions, the members panel, search results, moderation cases, and room administration) offers **Send direct message**, which also starts a brand-new conversation. When messaging is blocked, it says so instead of leading to a dead end, and your own name links to your account.
- Pings are no longer a short pop-up that is easy to miss. Each `/ping` is stored and shown in the room as a private notice that only its sender and target see ("You pinged Alex: …" / "Root pinged you: …"), on every device and after a reload. A ping also reaches someone who is offline: it appears in their notifications (linking straight to the ping) and is pushed like a mention, with its own switch under push settings. Pings follow room-message retention and are included in the personal data export. Migration `0026_room_pings.sql` adds the `room_pings` table and the `pinged` notification kind.
- Added colour schemes, chosen per device in the account menu or on the Account page, each with a light and a dark variant: **Lounge** (the default), **Dusk**, **Ember**, **Rosé**, **Midnight** (OLED black), and **High contrast**. Every scheme meets WCAG AA contrast; High contrast meets AAA and underlines links in text.
- Added a per-account date and time format on the Account page: keep Automatic (your browser's language), or choose a format region such as German (Germany) and a 24-hour or 12-hour clock, with a live preview. The choice follows your account to every device, every date in ChitChat now goes through one shared formatter, and the time zone stays your device's. Migration `0027_date_time_preferences.sql` adds the two account columns.
- Added sounds for pings, mentions, and new direct messages: short chimes made in the browser (no audio files, no third party), each with its own switch under **Sounds on this device** in the account menu, all on by default. While ChitChat is in a background tab, the tab title also counts what needs you, for example "(2) Ping from Alex". Browsers only allow sound after you have interacted with the page, so the sound adds to the visible notice and never replaces it.
- Added Tab completion for names in the chat and direct-message composers: type the start of a name and press Tab, again to cycle through matches (recent speakers first). A name that opens a message is completed IRC-style as "Name: ". Tab still moves focus when nothing matches, and Shift+Tab always leaves the composer.

### Changed

- Reworked the chat page's navigation. The sidebar now holds only rooms (one line each, with an invited, age, or privacy marker only where it matters) and your recent direct messages with unread counts. The room header became a top bar with members, search, notifications, and an account menu. The members list moved into a panel that opens on demand and is remembered per device. The bell previews your newest notifications. The account menu holds Account, Administration, the light/dark mode, and Sign out. On phones, rooms and conversations open in a slide-in drawer, and members and the account menu open as sheets from the bottom.
- Renamed "Privacy notifications" to "Notifications", since the page also lists @mentions.

### Fixed

- Fixed the Operational settings layout: panels now share the full width evenly instead of leaving gaps and stacking flush, and wide panels lay their fields out side by side. The administration and moderation pages use the full browser width.
- Fixed slash commands such as `/me` breaking across lines in chat notifications.
- Fixed Shift+Tab in the composer selecting an @mention suggestion instead of moving focus back.
- Fixed message avatars picking their colour from the username instead of the account, so the same person now looks the same in messages, members, and conversations.
- Fixed the room list going stale: rooms that are created, renamed, or deleted, and rooms an account joins, leaves, is invited to, or is removed from, now update in open chat tabs without a reload. A new contentless `rooms_changed` realtime event prompts clients to re-fetch the authorized room list, so private and unlisted rooms are never announced to people who cannot see them. Migration `0025_rooms_changed_event.sql` allows the new event type.

## [2.4.0] - 2026-10-04

Maintenance release with no database migration. See the [`v2.4.0` release notes](docs/releases/v2.4.0.md).

### Added

- Added an emoji picker to the room and direct-message composers: a smiley button inside the message field opens a searchable picker of 240 common emoji in six categories, with a recently used row saved on the device. It inserts at the caret, is fully keyboard operable, and needs no third-party code. Message reactions keep their fixed set of six (#101).

### Changed

- Gave the interface a warmer, more distinctive look: ivory/teal and ink/mint themes, an illustrated welcome screen, decorative speaker initials, quieter navigation, and cohesive room and direct-message composers, carried through the account, search, moderation, and administration pages (#102).

### Fixed

- Fixed replies and the composer overflowing on narrow screens, and an empty conversation misplacing its content in the chat layout (#102).

## [2.3.0] - 2026-10-04

Maintenance release with no database migration. See the [`v2.3.0` release notes](docs/releases/v2.3.0.md).

### Added

- Added a light theme and a **Theme** setting (System, Light, Dark) in the chat sidebar and on the account page. The default follows the device's light or dark setting; an explicit choice is saved on the device and applied before the page paints. Every color is now a theme token, and the light palette meets WCAG AA contrast (#99).

## [2.2.0] - 2026-10-04

Maintenance release. Applies one forward-only migration, `0024_application_name.sql`. See the [`v2.2.0` release notes](docs/releases/v2.2.0.md).

### Added

- Added an **Application name** setting to Operational settings: a Super-Administrator can rename the installation, with `APP_NAME` as the server default. The name is used consistently in page titles and headings, passkey prompts, push notifications, exports, and status output, and several texts that hardcoded "ChitChat" now use it. Migration `0024_application_name.sql` adds the setting (#96).
- Added a small "Powered by ChitChat!" footer linking to the project repository on every page (#97).

### Maintenance

- Fixed an intermittently failing passkey unit test whose generated key coordinates could be one byte short (#96).

## [2.1.0] - 2026-10-03

Maintenance release with two maintainer-requested features and interface fixes. Applies one forward-only migration, `0023_registration_protection.sql`. See the [`v2.1.0` release notes](docs/releases/v2.1.0.md).

### Added

- Added unobtrusive registration bot resistance: a hidden decoy field, a minimum form fill time (default 3 seconds), and a small proof-of-work puzzle the browser solves in the background (default 16 bits, about a second). There is no third-party CAPTCHA and no visible puzzle. Migration `0023_registration_protection.sql` adds the settings (#94).
- Added a **Registration protection** section to Operational settings, where a Super-Administrator can override the per-IP registration limit, the minimum fill time, and the proof-of-work difficulty. Empty fields use the server defaults from the environment (#94).
- The chat and other pages now fill the browser width, a **Menu** button on phones reaches search, direct messages, privacy notifications, account, administration, and sign-out, and attachments use a paperclip button inside the message field (#93).

### Fixed

- Fixed search, direct messages, privacy notifications, account, administration, and sign-out being unreachable from the chat page on phones, where the sidebar footer was hidden (#93).
- Fixed checkboxes rendering on a line of their own above their label text, as on the push-notification "Notify me when I'm mentioned" option and the account-closure confirmation (#92).

## [2.0.2] - 2026-10-03

Bug-fix release. No new feature and no database migration. Makes the locked dependencies installable on PHP 8.2 and 8.3 again, and fixes chat layout and message-highlight bugs found while stabilizing the browser test suite. See the [`v2.0.2` release notes](docs/releases/v2.0.2.md).

### Fixed

- Fixed the Composer lock requiring PHP 8.4.1 or newer despite ChitChat supporting PHP `^8.2`: `symfony/options-resolver` `v8.1.0` (via `minishlink/web-push`) is replaced by `v7.4.8`, and the lock now resolves against a PHP 8.2 platform so it cannot drift past the supported minimum again (#85).
- Kept the room-chat composer in view: the chat page is now one viewport tall and only the message list scrolls, instead of the page growing with the conversation and pushing the composer below the fold. Reading position is preserved across message-list updates and when loading older messages (#89).
- Kept the direct-message composer in view by bounding the conversation's height, while the privacy notice above it keeps its full size (#90).
- Fixed reply-preview, search-result, and mention-notification highlights disappearing whenever the message list re-rendered (#86).

### Maintenance

- CI runs PHP static checks and PHPUnit on PHP 8.2, 8.3, and 8.4, and publication validation requires each version's result (#85).
- Made the browser end-to-end suite reliable: it is served through Nginx and PHP-FPM as in production, specs are safe to retry, and a single failed attempt is retried automatically (#83, #86, #87, #88).

## [2.0.1] - 2026-08-23

Bug-fix release. No new feature and no database migration. Fixes findings from an independent post-release security review of the authentication and WebAuthn implementation (issues #73-#81; one finding, #78, was re-evaluated and closed without a code change — see its issue for why). See [Project status](README.md#project-status-feature-complete) and the [roadmap](docs/roadmap.md) for the maintenance-only policy going forward.

### Fixed

- Fixed WebAuthn passkey sign-counter clone detection: an assertion carrying a zero signature counter after a nonzero stored counter is now correctly rejected instead of silently resetting the stored counter and permanently disabling clone detection for that credential (#73).
- Split the combined username-or-IP login throttle into two independently configurable rate-limit policies (`login`, `login_ip`) so failed attempts against one username can no longer exhaust a shared IP's login budget for unrelated users behind the same NAT/VPN/CDN egress (#74).
- Documented that ChitChat's Nginx must terminate client connections directly: a CDN, load balancer, or additional reverse proxy in front silently breaks IP-based login/restoration throttles, the pending-MFA IP pin, and audit-log accuracy (#75).
- Equalized `password_verify` timing between a nonexistent username and a wrong password on login and account-restoration, and added an independent per-IP throttle (`account_restore_ip`) on account restoration, closing a username-enumeration side channel (#76).
- Fixed a `CborDecoder` map-parsing gap where a duplicate-key check could be bypassed by PHP's automatic numeric-string-to-integer array key coercion (#77).
- Rejected control characters (including NUL) in password input, avoiding an unhandled `ValueError`/HTTP 500 from `password_hash()` on a NUL byte (#79).
- Required active privileged step-up authentication on the admin ban, kick, and unban endpoints, matching the existing requirement on role changes and password resets (#80).
- Normalized `WEBAUTHN_ORIGIN` (lowercase scheme/host, default port stripped) so a differently-cased or explicitly-default-ported configuration value can no longer silently break every passkey ceremony, and capped credential ID length at the WebAuthn spec's 1023-byte maximum during registration (#81).

## [2.0.0] - 2026-08-15

Fifth and **final** stable release of the clean ChitChat reconstruction. Adds Web Push notification delivery on top of the stable `v1.3.0` baseline, then declares the project feature-complete: see [Project status](README.md#project-status-feature-complete), the [roadmap](docs/roadmap.md), and the [`v2.0.0` release notes](docs/releases/v2.0.0.md).

### Project status

- ChitChat is now considered feature-complete. Development has officially concluded; the project will only resume work to fix a discovered bug or evaluate a specific, concretely proposed new feature request, not to pursue further open-ended roadmap work.

### Added

- Added Web Push notification delivery: browser push subscriptions, per-category notification preferences (mute for `mentioned`), per-account quiet hours, and a new `bin/dispatch-web-push` periodic operator-scheduled command that sweeps undelivered notifications rather than sending push as a request-time side effect. See [ADR 0006](docs/architecture/0006-web-push.md).
- Added `minishlink/web-push` as this project's first production Composer dependency, for VAPID JWT signing and RFC 8291 payload encryption. Disabled unless `WEB_PUSH_VAPID_PUBLIC_KEY`, `WEB_PUSH_VAPID_PRIVATE_KEY`, and `WEB_PUSH_VAPID_SUBJECT` are all configured, mirroring how WebAuthn stays inert without `WEBAUTHN_RP_ID`/`WEBAUTHN_ORIGIN`.
- Added a "Push notifications" section to the privacy-notifications page: enable/disable push for the current device, mute `@mentions`, per-account quiet hours, and a device list with per-device revocation. Silently hidden if the browser lacks push support or the installation hasn't configured VAPID keys.

### Security and privacy

- Push payloads carry only the same sender/room/title text already considered safe for the in-app notification timeline, never a raw message body.
- `revision_review`, `moderator_message_deleted`, `admin_password_reset`, and `system_policy_changed` pushes are non-mutable — a participant cannot silence those categories short of removing all push subscriptions, matching how they're already non-optional in-app. Only `mentioned` has a per-account mute.
- Push is best-effort and never a delivery guarantee or a source of truth; the existing durable in-app notification timeline remains authoritative.
- Push subscriptions and notification preferences are cleared at the same account-tombstone point durable privacy notifications already are.

### Fixed

- Fixed several CSS custom-property references (`--warning`, `--border-color`, `--muted-text`, `--danger-color`, `--panel-background`, `--code-surface`, `--warning-border`, `--warning-surface`) that pointed at design tokens never defined anywhere in the stylesheet tree, so the affected borders, backgrounds and text colors were silently dropped. Most visibly, the privileged step-up dialog — shown on any page for any sensitive action — could render as a barely visible near-white box; moderation notices, privacy notices, the DM block toggle, the revision-review warning banner, and the Administrator system-status page's dividers and badges lost their intended styling the same way. All affected declarations now reference the actual token set (`--border`, `--muted`, `--danger`, `--surface`, `--bg`) or the newly added `--warning` token.
- Fixed the destructive-retention warning banner on Operational Settings (`.warning-text`) and the direct-message inbox header's action-button row (`.messages-header-actions`), both of which had no matching CSS rule anywhere and rendered as plain, unstyled elements.
- Removed dead CSS (`grid-template-columns` on a `display: flex` rule in `.role-fieldset label`) and aligned `color-scheme` between every page's `<meta>` tag and `app.css` (both now declare `dark` only, matching the fact that no light theme exists).

### Testing

- Added a browser end-to-end journey for message reactions (`zzzzzzzzzzzzz-reactions.spec.js`): aggregation of two participants' reactions onto one pill, idempotent add/remove toggling, and realtime delivery via `message_reaction_changed` on both room and direct messages. Reactions previously had PHPUnit integration coverage only; every other post-`v1.2.0` feature already had a dedicated browser journey.

### Changed

- Replaced the BSD-3-Clause `LICENSE` with BSD-2-Clause (drops the non-endorsement clause) and declared it in `composer.json`.
- Brought README, INSTALL, and every file under `docs/` current with everything shipped since `v1.2.0`: previously undocumented endpoints (`/search.php`, `/moderation.php`, `/notifications.php`, six API route groups, and a new `docs/api/web-push.md` for five previously undocumented Web Push endpoints), three ADRs still marked "Proposed, implementation not started" for features that had already shipped, a `docs/api/README.md` index missing 8 of its 17 entries, and two independently-confirmed contradictions (`message-revisions.md` and `api/message-revision-review.md` both incorrectly claimed revision review never notifies participants — it does).

## [1.3.0] - 2026-08-15

Fourth stable release of the clean ChitChat reconstruction. This release promotes the evaluated `v1.3.0-rc.1`/`v1.3.0-rc.2` feature set — participant search, a moderation queue, and replies/mentions — and adds message reactions on top before stabilizing. Reactions did not go through its own release-candidate evaluation window; it was validated the same way every merge to `main` already is, through full CI (PHPUnit integration tests plus the complete Chromium/Firefox/WebKit browser matrix) on each of its two pull requests.

### Added

- Added authorization-aware PostgreSQL full-text search over current, undeleted room and direct-message bodies, with combined, room-only and direct-only scopes, bounded pagination, privacy-safe POST transport and exact-message deep links.
- Added participant reporting for one specific visible, undeleted room message or incoming direct message, plus an authorization-scoped moderation queue with immutable evidence snapshots, aggregation, assignment and explicit resolution states.
- Added submitted moderation reports to the reporting participant's personal-data export without exposing other reporters, queue assignments or private moderator notes.
- Added durable reply references on room and direct messages, resolved through the same authorization-scoped read path as ordinary history, with a distinct placeholder when the referenced message is unavailable, deleted or expired.
- Added `@username` mentions, plus room-scoped `@room`/`@here` broadcast mentions, resolved and authorized once at send time. Unauthorized or unresolvable tokens render as plain text without notifying anyone.
- Added durable `mentioned` participant notifications, with a human-readable timeline entry and a deep link to the exact message, and a dedicated `RATE_LIMIT_ROOM_BROADCAST_MENTION` policy independent of ordinary room-send throttling.
- Added the account's own sent and received mentions, and submitted moderation reports, to personal-data export, excluding other participants' message bodies.
- Added reply and `@mention` composer support to the room and direct-message browser clients: a reply banner with cancel, a quoted preview of the replied-to message with click-to-scroll, `@mention` autocomplete, and highlighting limited to mentions the server actually resolved and authorized.
- Added reply-target and caption-mention support to room and direct-message attachment uploads, matching ordinary text messages.
- Added message reactions: a small controlled emoji vocabulary (👍 ❤️ 😂 😮 😢 🎉), idempotent add/remove enforced by a database `UNIQUE (message_id, user_id, emoji)` constraint, and a new `message_reaction_changed` realtime event carrying the message's full current reaction state. See [ADR 0005](docs/architecture/0005-reactions.md).
- Added `reactions` to every message-shaped API response (room and direct-message history, send, mutation metadata, attachment uploads), each entry listing the reacting participants by id and username, matching how message authorship is already visible.
- Added a reaction bar to the room and direct-message clients: pills for emoji already in use (click toggles your own reaction) plus an "Add reaction" control for the full vocabulary, updated live via `message_reaction_changed`.

### Security and privacy

- Search enforces room discoverability, membership, invitation, minimum-age and DM-participant authorization inside the query and never joins retained revision bodies; search terms are excluded from URLs, ChitChat audits, rate-limit identifiers, aggregate counters and Prometheus labels.
- Room-scoped moderators see only cases from rooms they currently moderate; global moderation roles may review DM cases but receive only submitted exact-message snapshots rather than surrounding conversation history or attachment bytes. Report bodies, participant details and moderator resolution notes are excluded from moderation audit metadata.
- Reply targets must be in the same room or the same direct-message conversation as the reply; a reply cannot point across rooms or DM threads. This applies equally to attachment uploads.
- Mention authorization is re-checked against current room access and minimum-age eligibility for every candidate, individual or broadcast, and never discloses another participant's message body in the mentioned account's own personal-data export.
- Reacting requires exactly the same authorization as reading the message; there is no new authorization concept. Reacting to an already-deleted message is rejected with `409 message_already_deleted`. Reacting to an existing direct message stays available after a block, matching how reply previews already behave.

### Compatibility

- `v1.3.0` supports an in-place upgrade from stable `v1.2.0`, or promotion of an existing `v1.3.0-rc.1`/`v1.3.0-rc.2` deployment, after PostgreSQL and attachment storage are backed up together.
- The upgrade applies forward-only migrations `0018_message_search.sql`, `0019_moderation_reports.sql`, `0020_replies_mentions.sql` and `0021_reactions.sql` without introducing an external search, queue, cache or moderation service.
- Once these migrations are applied, older ChitChat source must not be pointed at the upgraded database. Rollback requires restoring a matching pre-upgrade database and attachment backup.
- An installation already migrated through `0020_replies_mentions.sql` (i.e. `v1.3.0-rc.2`) needs only `0021_reactions.sql` and a redeployed source tree; no data conversion is required.

### Upgrade notes

Fresh installations should follow `INSTALL.md` and `docs/releases/v1.3.0.md`.

Existing `v1.2.0` installations should:

1. stop or drain application writes;
2. back up PostgreSQL and attachment storage together and verify the backup;
3. deploy `v1.3.0` while preserving `.env` and attachment storage;
4. compare the existing `.env` with `.env.example`;
5. run `composer install --no-dev --classmap-authoritative`;
6. run `composer migrate` once;
7. run `composer maintenance:dry-run` and review the result;
8. verify `/health.php`, `/ready.php`, login, rooms, direct messages, attachments, SSE through the production reverse proxy, system status, participant search, moderation reporting, replies/mentions, and reactions.

Existing `v1.3.0-rc.1`/`v1.3.0-rc.2` installations should create and verify a backup, deploy stable source, change an explicitly pinned `APP_VERSION` to `1.3.0`, run the same Composer, migration, and maintenance commands, and verify representative behavior. `composer migrate` applies only the migrations not already present on that installation.

### Known limitations

- The supported deployment target remains one application server; horizontal scaling and Redis-backed event delivery are not implemented.
- PostgreSQL is the only supported database.
- Direct messages are not end-to-end encrypted.
- No reply/mention/reaction support in administrative DM inspection or revision-review surfaces.
- Maintenance, backup scheduling, retention, alerting, and release-specific manual assistive-technology testing remain operator responsibilities.
- The committed automated accessibility suite is not a substitute for a complete manual WCAG audit.
- No supported in-place upgrade exists from the incomplete legacy `v0.10.25` snapshot.

## [1.3.0-rc.2] - 2026-08-14

Second release candidate for ChitChat v1.3.0, superseding `v1.3.0-rc.1`. This pre-release adds durable replies and `@mention`s (including room-scoped `@room`/`@here` broadcasts) on top of `v1.3.0-rc.1`'s participant search and moderation queue. It is intended for controlled evaluation and upgrade rehearsal before the stable release.

### Added

- Added durable reply references on room and direct messages, resolved through the same authorization-scoped read path as ordinary history, with a distinct placeholder when the referenced message is unavailable, deleted or expired.
- Added `@username` mentions, plus room-scoped `@room`/`@here` broadcast mentions, resolved and authorized once at send time. Unauthorized or unresolvable tokens render as plain text without notifying anyone.
- Added durable `mentioned` participant notifications, with a human-readable timeline entry and a deep link to the exact message, and a dedicated `RATE_LIMIT_ROOM_BROADCAST_MENTION` policy independent of ordinary room-send throttling.
- Added the account's own sent and received mentions to personal-data export, excluding other participants' message bodies.
- Added reply and `@mention` composer support to the room and direct-message browser clients: a reply banner with cancel, a quoted preview of the replied-to message with click-to-scroll, and highlighting limited to mentions the server actually resolved and authorized.
- Added `@mention` autocomplete while composing: room suggestions come from current room membership (plus `@room`/`@here`), direct-message suggestions from the conversation's only possible recipient. A suggestion is a typing convenience only — the server independently re-authorizes every mention at send time regardless of what was suggested or typed.
- Added reply-target and caption-mention support to room and direct-message attachment uploads, matching ordinary text messages.

### Security and privacy

- Reply targets must be in the same room or the same direct-message conversation as the reply; a reply cannot point across rooms or DM threads. This applies equally to attachment uploads.
- Mention authorization is re-checked against current room access and minimum-age eligibility for every candidate, individual or broadcast; a message body's mention count is otherwise unbounded and relies on existing message-length and send rate limits rather than a separate cap.
- Mentions of another participant never disclose that participant's message body in the mentioned account's own personal-data export.
- The mention-search endpoint behind autocomplete is scoped to a room's current membership and re-uses the same history-read authorization boundary as ordinary room access; it is a suggestion source, not an independent authorization surface.

### Compatibility

- `v1.3.0-rc.2` supports an in-place upgrade from stable `v1.2.0`, or from an existing `v1.3.0-rc.1` deployment, after PostgreSQL and attachment storage are backed up together.
- Adds forward-only migration `0020_replies_mentions.sql` on top of `0018_message_search.sql` and `0019_moderation_reports.sql`, without introducing an external notification, queue or search service.
- Once these migrations are applied, older ChitChat source must not be pointed at the upgraded database. Rollback requires restoring a matching pre-upgrade database and attachment backup.
- This is a release candidate: compatible fixes may land before `v1.3.0`, but operators must not assume database or configuration compatibility with later pre-releases without reading their notes.

### Upgrade notes

Fresh installations should follow `INSTALL.md` and the release-candidate evaluation guidance in `docs/releases/v1.3.0-rc.2.md`.

Existing `v1.2.0` installations should:

1. stop or drain application writes;
2. back up PostgreSQL and attachment storage together and verify the backup;
3. deploy `v1.3.0-rc.2` while preserving `.env` and attachment storage;
4. compare the existing `.env` with `.env.example`;
5. run `composer install --no-dev --classmap-authoritative`;
6. run `composer migrate` once;
7. run `composer maintenance:dry-run` and review the result;
8. verify `/health.php`, `/ready.php`, login, rooms, direct messages, attachments, SSE through the production reverse proxy, system status, participant search, moderation reporting, and replies/mentions.

Existing `v1.3.0-rc.1` installations should redeploy source and run `composer migrate` to apply `0020_replies_mentions.sql`; no data conversion is required.

### Known limitations

- The supported deployment target remains one application server; horizontal scaling and Redis-backed event delivery are not implemented.
- PostgreSQL is the only supported database.
- Direct messages are not end-to-end encrypted.
- Maintenance, backup scheduling, retention, alerting, and release-specific manual assistive-technology testing remain operator responsibilities.
- No `@mention` autocomplete for room-broadcast wording beyond the literal `@room`/`@here` keywords, and no reply/mention support in administrative inspection or revision-review surfaces.
- This is a pre-release intended for controlled evaluation rather than an unconditional production recommendation.
- No supported in-place upgrade exists from the incomplete legacy `v0.10.25` snapshot.

## [1.3.0-rc.1] - 2026-08-14

First release candidate for ChitChat v1.3.0. This pre-release adds authorization-aware participant message search and a participant reporting and moderation queue on top of the stable `v1.2.0` baseline. It is intended for controlled evaluation and upgrade rehearsal before the stable release.

### Added

- Added authorization-aware PostgreSQL full-text search over current, undeleted room and direct-message bodies, with combined, room-only and direct-only scopes, bounded pagination, privacy-safe POST transport and exact-message deep links.
- Added participant reporting for one specific visible, undeleted room message or incoming direct message, plus an authorization-scoped moderation queue with immutable evidence snapshots, aggregation, assignment and explicit resolution states.
- Added submitted moderation reports to the reporting participant's personal-data export without exposing other reporters, queue assignments or private moderator notes.

### Security and privacy

- Search enforces room discoverability, membership, invitation, minimum-age and DM-participant authorization inside the query and never joins retained revision bodies.
- Search terms are excluded from URLs, ChitChat audits, rate-limit identifiers, aggregate counters and Prometheus labels.
- Room-scoped moderators see only cases from rooms they currently moderate; global moderation roles may review DM cases but receive only submitted exact-message snapshots rather than surrounding conversation history or attachment bytes.
- Open moderation evidence survives canonical message retention; closed evidence is transactionally linked to the exact closure audit and expires with that audit under configured retention.
- Report bodies, participant details and moderator resolution notes are excluded from moderation audit metadata.

### Compatibility

- `v1.3.0-rc.1` supports an in-place upgrade from stable `v1.2.0` after PostgreSQL and attachment storage are backed up together.
- The upgrade applies forward-only migrations `0018_message_search.sql` and `0019_moderation_reports.sql` without introducing an external search, queue, cache or moderation service.
- Once these migrations are applied, older ChitChat source must not be pointed at the upgraded database. Rollback requires restoring a matching pre-upgrade database and attachment backup.
- This is a release candidate: compatible fixes may land before `v1.3.0`, but operators must not assume database or configuration compatibility with later pre-releases without reading their notes.

### Upgrade notes

Fresh installations should follow `INSTALL.md` and the release-candidate evaluation guidance in `docs/releases/v1.3.0-rc.1.md`.

Existing `v1.2.0` installations should:

1. stop or drain application writes;
2. back up PostgreSQL and attachment storage together and verify the backup;
3. deploy `v1.3.0-rc.1` while preserving `.env` and attachment storage;
4. compare the existing `.env` with `.env.example`;
5. run `composer install --no-dev --classmap-authoritative`;
6. run `composer migrate` once;
7. run `composer maintenance:dry-run` and review the result;
8. verify `/health.php`, `/ready.php`, login, rooms, direct messages, attachments, SSE through the production reverse proxy, system status, participant search, and moderation reporting.

### Known limitations

- The supported deployment target remains one application server; horizontal scaling and Redis-backed event delivery are not implemented.
- PostgreSQL is the only supported database.
- Direct messages are not end-to-end encrypted.
- Maintenance, backup scheduling, retention, alerting, and release-specific manual assistive-technology testing remain operator responsibilities.
- This is a pre-release intended for controlled evaluation rather than an unconditional production recommendation.
- No supported in-place upgrade exists from the incomplete legacy `v0.10.25` snapshot.

## [1.2.0] - 2026-07-18

Third stable release of the clean ChitChat reconstruction. This release promotes the `v1.2.0-rc.1` feature set after controlled evaluation completed without a reported defect. It adds deployment-configurable throttling, supported backup and restore tooling, account closure and restoration, passkey multi-factor authentication, participant-facing privacy notifications, and deeper accessibility and visual-regression coverage.

### Stabilized since 1.2.0-rc.1

- No application defect was reported during the release-candidate evaluation period.
- No database migration, runtime behavior, API contract, privacy policy, retention rule, production dependency, or deployment configuration requirement changed after the release candidate.
- Stabilized the WebKit structural-accessibility test by waiting for the asynchronous MFA account summary before enumerating visible account controls; this is a test-only timing fix.
- Updated version declarations, tests, repository status, changelog, and release metadata for stable `v1.2.0`.

### Added

- Added bounded named rate-limit policies and aggregate privacy-preserving counters for authentication, account, messaging, upload, invitation, search, inspection, revision-review, restoration, and MFA paths.
- Added supported manifest-bound PostgreSQL-plus-attachment backup, verification, safe staged restore, and dedicated backup-rehearsal automation.
- Added step-up-protected account closure, a 14-day cooling-off and restoration flow, maintenance-driven profile tombstoning, and final-Super-Administrator protection.
- Added optional password-first WebAuthn MFA with multiple passkeys, ten one-time recovery codes, MFA-aware login/step-up/restoration, and enforceable administrative-MFA policy.
- Added durable participant-facing privacy notifications for revision review, moderator deletion, administrative password reset, and material installation-policy changes.
- Added pinned axe-core, reflow, forced-colors, reduced-motion, screenshot-regression, and explicit human assistive-technology review procedures.

### Security and privacy

- Rate-limit configuration remains deployment-only and aggregate counters contain only fixed policy names and coarse totals.
- Backup manifests contain no database password; publication requires self-verification, and restore rejects unsafe archives and accidental production replacement.
- Closure destroys private credentials and profile identifiers at finalization while preserving shared retained conversation history and immutable evidence according to policy.
- MFA-enabled accounts cannot establish an authenticated session or privileged step-up from a password alone; recovery-code plaintext is revealed only on creation and each code is consumed atomically once.
- Selected participant notifications commit atomically with their append-only audit source and exclude restricted bodies, reasons, usernames, IP addresses, credentials, passkey data, and recovery material.
- Automated accessibility results remain separate from release-specific manual NVDA and VoiceOver sign-off.

### Compatibility

- `v1.2.0` supports an in-place upgrade from stable `v1.1.0` after PostgreSQL and attachment storage are backed up together.
- The upgrade applies forward-only migrations `0014_rate_limit_observability.sql`, `0015_account_closure.sql`, `0016_mfa_passkeys.sql`, and `0017_privacy_notifications.sql`.
- An installation already migrated for `v1.2.0-rc.1` requires no additional database migration, data conversion, runtime dependency, or configuration redesign.
- Once migrations `0014`–`0017` are applied, older ChitChat source must not be pointed at the upgraded database. Rollback requires restoring a matching pre-upgrade database and attachment backup.
- Passkeys remain disabled unless both `WEBAUTHN_RP_ID` and `WEBAUTHN_ORIGIN` are configured. Production WebAuthn origins require HTTPS; the PHP OpenSSL extension is required when passkeys are enabled.
- Existing password-only installations retain password login and password step-up behavior while WebAuthn remains unconfigured.
- No external identity service, queue, Redis deployment, or other new production runtime service is required. Axe-core and screenshot comparison are development-only npm dependencies.

### Upgrade notes

Fresh installations should follow `INSTALL.md` and `docs/releases/v1.2.0.md`.

Existing `v1.1.0` installations should:

1. stop or drain application writes;
2. back up PostgreSQL and attachment storage together and verify the backup;
3. deploy `v1.2.0` while preserving `.env` and attachment storage;
4. compare the existing `.env` with `.env.example`, keeping WebAuthn disabled unless a durable HTTPS RP ID and origin have been chosen;
5. run `composer install --no-dev --classmap-authoritative`;
6. run `composer migrate` once;
7. run `composer maintenance:dry-run` and review the result;
8. verify `/health.php`, `/ready.php`, login, rooms, direct messages, attachments, SSE through the production reverse proxy, system status, account closure/restoration, backup verification, privacy notifications, and any enabled passkey flow.

Existing `v1.2.0-rc.1` installations should create and verify a backup, deploy stable source, change an explicitly pinned `APP_VERSION` to `1.2.0`, run the same Composer, migration, and maintenance commands, and verify representative behavior. `composer migrate` should find no new stable-release migration.

### Known limitations

- The supported deployment target remains one application server; horizontal scaling and Redis-backed event delivery are not implemented.
- PostgreSQL is the only supported database.
- Direct messages are not end-to-end encrypted.
- Maintenance, backup scheduling, retention, alerting, and release-specific manual assistive-technology testing remain operator responsibilities.
- The committed automated accessibility suite is not a substitute for a complete manual WCAG audit.
- No supported in-place upgrade exists from the incomplete legacy `v0.10.25` snapshot.

## [1.2.0-rc.1] - 2026-07-18

First release candidate for ChitChat v1.2.0. This pre-release adds deployment-configurable throttling, supported backup and restore tooling, account closure and restoration, passkey multi-factor authentication, participant-facing privacy notifications, and deeper accessibility and visual-regression coverage. It is intended for controlled evaluation and upgrade rehearsal before the stable release.

### Added

- Added bounded named rate-limit policies for authentication, account, messaging, upload, invitation, search, inspection, revision-review, restoration, and MFA paths, configured through deployment environment variables.
- Added aggregate privacy-preserving allowed/rejected rate-limit counters to Administrator system status and Prometheus without account, IP, room, message, search-term, or request-body identifiers.
- Added supported `chitchat-backup`, `chitchat-verify-backup`, and `chitchat-restore` commands with a versioned manifest binding PostgreSQL and attachment storage by exact size and SHA-256 checksum.
- Added safe staged restore into new targets by default, explicit destructive-replacement flags, attachment archive traversal/link/special-file rejection, and a dedicated backup-rehearsal CI gate.
- Added step-up-protected account closure with immediate session invalidation, global-role revocation, a fixed 14-day cooling-off period, independently throttled credential-based restoration, and maintenance-driven irreversible tombstoning.
- Added optional password-first WebAuthn multi-factor authentication with multiple labelled ES256 or RS256 passkeys, ten one-time recovery codes, account credential management, and passkey or recovery-code completion for login, privileged step-up, and restoration.
- Added optional Super-Administrator enforcement of passkey MFA for all global administrative roles, with transactional activation checks and a PostgreSQL role-assignment invariant.
- Added durable participant-facing privacy notifications for administrative revision review, moderator room-message deletion, administrative password reset, and material installation-policy changes.
- Added a paginated signed-in notification center, account-scoped individual and bulk read state, and a capped unread badge.
- Added pinned axe-core WCAG A/AA analysis for core signed-out and signed-in surfaces, Chromium reflow checks at 640 and 320 CSS pixels, forced-colors and reduced-motion checks, and targeted pinned-Chromium/Linux screenshot regression for stable authentication and account layouts.
- Added explicit manual NVDA, VoiceOver, keyboard-only, browser-zoom, Windows contrast-theme, and reduced-motion review procedures with versioned result recording.

### Security and privacy

- Rate-limit configuration remains deployment-only so a compromised browser administration session cannot weaken anti-abuse controls; aggregate counters use only fixed policy names and coarse totals.
- Backup manifests contain no database password, backup sets publish only after self-verification, and restore refuses unsafe archives, public-root overlap, and configured production targets without explicit acknowledgement.
- Closure preserves shared room and direct-message history, immutable revisions, attachment evidence, membership and ownership attribution, bans, and audits according to retention policy while destroying private credentials and profile identifiers at finalization.
- A correct password for an MFA-enabled account creates only a short-lived pending context and does not establish an authenticated session or successful login until a passkey or unused recovery code succeeds.
- Recovery-code plaintext is returned only when a set is created or replaced; PostgreSQL stores SHA-256 hashes of 96-bit random values and consumes codes atomically once.
- Selected privacy notifications are derived from append-only audit records inside the same PostgreSQL transaction and contain only a fixed event kind, recipient, nullable audit reference, bounded structural context, and read timestamps.
- Notification context excludes message and revision bodies, administrator or moderation reasons, usernames, IP addresses, credentials, session state, passkey data, and recovery material.
- Automated accessibility results remain separate from release-specific manual assistive-technology sign-off; green axe, emulation, and screenshot checks do not claim completed NVDA or VoiceOver validation.

### Compatibility

- `v1.2.0-rc.1` supports an in-place upgrade from stable `v1.1.0` after PostgreSQL and attachment storage are backed up together.
- The upgrade applies forward-only migrations `0014_rate_limit_observability.sql`, `0015_account_closure.sql`, `0016_mfa_passkeys.sql`, and `0017_privacy_notifications.sql`.
- Once these migrations are applied, older ChitChat source must not be pointed at the upgraded database. Rollback requires restoring a matching pre-upgrade database and attachment backup.
- Passkeys remain disabled unless both `WEBAUTHN_RP_ID` and `WEBAUTHN_ORIGIN` are configured. Production WebAuthn origins require HTTPS; the PHP OpenSSL extension is required when passkeys are enabled.
- Existing password-only installations retain password login and password step-up behavior while WebAuthn remains unconfigured.
- No external identity service, queue, Redis deployment, or other new production runtime service is required. Axe-core and screenshot comparison are development-only npm dependencies.
- This is a release candidate: compatible fixes may land before `v1.2.0`, but operators must not assume database or configuration compatibility with later pre-releases without reading their notes.

### Upgrade notes

Fresh installations should follow `INSTALL.md` and the release-candidate evaluation guidance in `docs/releases/v1.2.0-rc.1.md`.

Existing `v1.1.0` installations should:

1. stop or drain application writes;
2. back up PostgreSQL and attachment storage together and verify the backup;
3. deploy `v1.2.0-rc.1` while preserving `.env` and attachment storage;
4. compare the existing `.env` with `.env.example`, keeping WebAuthn disabled unless a durable HTTPS RP ID and origin have been chosen;
5. run `composer install --no-dev --classmap-authoritative`;
6. run `composer migrate` once;
7. run `composer maintenance:dry-run` and review the result;
8. verify `/health.php`, `/ready.php`, login, rooms, direct messages, attachments, SSE through the production reverse proxy, system status, account closure/restoration, backup verification, privacy notifications, and any enabled passkey flow.

### Known limitations

- The supported deployment target remains one application server; horizontal scaling and Redis-backed event delivery are not implemented.
- PostgreSQL is the only supported database.
- Direct messages are not end-to-end encrypted.
- Maintenance, backup scheduling, retention, alerting, and release-specific manual assistive-technology testing remain operator responsibilities.
- The committed automated accessibility suite is not a substitute for a complete manual WCAG audit.
- This is a pre-release intended for controlled evaluation rather than an unconditional production recommendation.
- No supported in-place upgrade exists from the incomplete legacy `v0.10.25` snapshot.

## [1.1.0] - 2026-07-17

Second stable release of the clean ChitChat reconstruction. This release adds direct-message controls and attachments, message editing and revision history, stronger administrative authentication and privacy boundaries, operational observability, personal-data export, WebKit validation, and accessibility hardening.

### Added

- Added user-controlled direct-message blocking and unblocking from the conversation header.
- Added authenticated block-status, block, and unblock API endpoints.
- Added integration and Chromium/Firefox journey coverage for blocked sends, retained history, and resumed messaging after unblock.
- Added direct-message file uploads with optional captions, opaque storage, shared MIME and size policy, SHA-256 metadata, safe image previews, and participant-only downloads.
- Added bounded attachment metadata enrichment for visible direct-message history without changing the canonical DM/SSE payload shape.
- Added PostgreSQL/filesystem and Chromium/Firefox coverage for multipart DM uploads, exact-byte downloads, outsider denial, and retention cleanup.
- Added author editing and delete-for-everyone controls for room and direct messages, including attachment captions.
- Added immutable database-triggered revision ledgers for room and direct-message edits and deletions.
- Added bounded mutation metadata endpoints, edited markers, realtime cross-session refresh, and author/moderator deletion placeholders.
- Added PostgreSQL/filesystem and Chromium/Firefox coverage for authorship enforcement, block-aware editing, deletion, revision history, and attachment revocation.
- Added separately configurable administrative review of retained room and direct-message revision chains.
- Added an exact-message-ID, reason-required review endpoint and browser surface that expose only messages with retained revisions rather than providing user, room, conversation, date, or body search.
- Added integration and Chromium/Firefox coverage for revision-chain rendering, independent role authorization, reason validation, and content-free audit metadata.
- Added an Administrator system-status page backed by aggregate PostgreSQL, attachment-storage, realtime, security-ledger, and maintenance measurements.
- Added a disabled-by-default Prometheus text endpoint protected by a configurable bearer token.
- Added leased SSE-connection accounting and persistent maintenance invocation records for success, warning, failure, duration, and result reporting.
- Added ready-to-adapt hardened `systemd` service/timer units and observability operating documentation.
- Added unit, PostgreSQL integration, and Chromium/Firefox coverage for status authorization, Prometheus encoding, maintenance freshness, and SSE lease lifecycle.
- Added short-lived current-password step-up authentication for DM inspection, revision review, global role replacement, administrator password resets, and operational-policy updates.
- Added session disclosure of step-up status, a shared accessible browser password dialog, and one-time automatic retry of protected JSON POST requests after successful verification.
- Added database-backed step-up attempt limiting plus separate success and failure audit records.
- Added unit, PostgreSQL integration, and Chromium/Firefox coverage for failed verification, successful elevation, session-version binding, expiry, rate limiting, and reuse during the active window.
- Added a signed-in account page with a step-up-protected JSON export of retained profile, room, direct-message, security-history, and actor-audit data.
- Added repeatable-read export snapshots, versioned export metadata, per-account export throttling, and successful-generation audits containing aggregate counts only.
- Added PostgreSQL integration and Chromium/Firefox coverage for export scope, download behavior, revision ownership, block-direction privacy, and secret/storage-key exclusion.
- Added the complete browser release journey as an independent WebKit CI gate alongside Chromium and Firefox.
- Added dependency-free browser accessibility checks for landmarks, headings, unique IDs, labelled controls, named interactive elements and dialogs, keyboard-operated authentication tabs, and visible focus indicators.
- Added explicit authentication-tab panel relationships, roving tab focus, keyboard navigation, a named room dialog, live connection-status semantics, and high-visibility focus styling on the core user surfaces.

### Security and privacy

- A block in either direction prevents new messages and file uploads in both directions while preserving existing retained history.
- Public relationship state exposes whether the requesting user set a block and only a generic messaging-availability flag; it does not expose a separate `blocked_by_other` field.
- Send, upload, block, and unblock operations serialize on a PostgreSQL advisory lock for the user pair, preventing a completed block from being bypassed by a concurrent message or attachment.
- DM attachment metadata and bytes are returned only to the sender or recipient; unauthorized requests use the same not-found response as an unknown attachment.
- Administrative text-history inspection does not silently grant attachment-binary download rights.
- Direct-message retention removes associated attachment metadata and files in the same maintenance run, while orphan detection treats room and DM keys as one shared storage namespace.
- Only an undeleted message's author may edit or delete it; room moderator deletion remains a separate audited action.
- Direct-message editing is disabled while either participant has blocked the other, preventing an edit from becoming a post-block delivery channel; sender deletion remains available.
- User-deleted attachments become inaccessible immediately while their binary and immutable revision evidence remain until configured cleanup.
- Revision bodies are not exposed through participant mutation endpoints or duplicated into ordinary mutation audit metadata.
- Revision review is disabled by default, has an authorization policy independent from DM inspection and moderation roles, requires a fresh 10-500 character reason, and audits every successful access before returning historical bodies.
- Successful review audits record the actor, IP, exact message context, reason, and returned revision IDs and actions without copying historical bodies into audit JSON.
- Messages without retained revisions cannot be opened through the review workflow, limiting it to its stated historical-content purpose.
- ChitChat does not notify participants when a revision review occurs; the administrative interface and operating documentation make that limitation and the operator's disclosure responsibility explicit.
- Metrics remain unavailable while no bearer token is configured and expose aggregate operational values rather than usernames, message content, attachment names, IP addresses, credentials, or filesystem paths.
- Privileged elevation is bound to the current user and session version, expires after a configurable 60-3600 second window, and is cleared by login rotation, logout, password or session-version changes, bans, and other authentication invalidation.
- Coarse role and feature-policy checks occur before step-up, so unauthorized accounts are denied without receiving a password prompt.
- Successful and failed step-up audits contain method and timing policy only; passwords are never written to audit metadata.
- Password step-up is recent reauthentication rather than multi-factor authentication and does not replace roles, CSRF, required reasons, per-action audits, or target-specific authorization.
- Personal-data exports exclude credentials, session and step-up state, attachment bytes and storage keys, other users' incoming block state, and hidden revisions for messages authored by somebody else.
- Successful export audits are created after the exported activity snapshot and contain only the format version and aggregate item counts, avoiding recursive inclusion and content duplication.

### Compatibility

- `v1.1.0` supports an in-place upgrade from stable `v1.0.0` after PostgreSQL and attachment storage are backed up together.
- The upgrade applies forward-only migrations `0010_direct_message_blocks.sql`, `0011_direct_message_attachments.sql`, `0012_message_mutations.sql`, and `0013_operational_observability.sql`.
- Once these migrations are applied, older ChitChat code must not be pointed at the upgraded database. Rollback requires restoring a matching pre-upgrade database and attachment backup.
- No new runtime package or external service is required for the supported single-server deployment.

### Upgrade notes

Fresh installations should follow `INSTALL.md`.

Existing `v1.0.0` installations should:

1. stop or drain application writes;
2. back up PostgreSQL and attachment storage together;
3. deploy `v1.1.0` while preserving `.env` and attachment storage;
4. run `composer install --no-dev --classmap-authoritative`;
5. run `composer migrate` once;
6. run `composer maintenance:dry-run` and review the result;
7. verify `/health.php`, `/ready.php`, login, room and direct-message history, attachment access, SSE through the production reverse proxy, account export, and system status.

### Known limitations

- Initial deployment target remains one application server; horizontal scaling and Redis-backed delivery are not implemented.
- PostgreSQL is the only supported database.
- Direct messages are not end-to-end encrypted.
- Revision review does not automatically notify affected participants.
- Browser accessibility checks are regression smoke tests rather than a complete manual WCAG audit or visual-regression suite.
- Maintenance scheduling and monitoring remain operator responsibilities.
- Account closure, multi-factor authentication, configurable per-limit throttles, and richer compliance/reporting workflows are not included.
- No supported in-place upgrade exists from the incomplete legacy `v0.10.25` snapshot.

## [1.0.0] - 2026-07-17

First stable release of the clean ChitChat reconstruction.

### Stabilized since 1.0.0-rc.1

- Added the complete two-session browser journey as an independent Firefox gate alongside Chromium.
- Added installation testing from the published `v1.0.0-rc.1` source archive using production Composer dependencies.
- Added automated PostgreSQL and attachment backup verification, restore under new names, and forward migration from the release candidate.
- Added verification that restored accounts, room history, direct-message history, and exact attachment bytes remain usable through current source.
- Added a real Nginx and PHP-FPM deployment gate that authenticates, opens SSE, sends a room message concurrently, and requires the event to arrive before the stream closes.
- Added tested Nginx/PHP-FPM, browser-matrix, and release-rehearsal operating documentation.
- Corrected the reverse-proxy test harness to use Ubuntu-supported PHP-FPM meta-packages and dynamically locate the installed FPM binary.

### Compatibility

- No database migration, API, retention-policy, or application-feature change was introduced between `v1.0.0-rc.1` and `v1.0.0`.
- A `v1.0.0-rc.1` installation may be upgraded in place after backing up PostgreSQL and attachment storage together, deploying stable source, running `composer install --no-dev --classmap-authoritative`, and running `composer migrate`.
- The automated release rehearsal proves the published RC archive can be installed, backed up, restored, and advanced to stable source without losing seeded user, room, attachment, or direct-message data.

### Security and privacy defaults

- Direct messages are not end-to-end encrypted.
- Administrative DM inspection is enabled for Super-Administrators by default and every successful page access is audited.
- Room messages, direct messages, and audit entries are retained permanently until a Super-Administrator configures a nonzero retention period and maintenance runs.
- Attachment downloads always re-evaluate current room and minimum-age authorization.
- Only `public/` may be exposed by the web server; attachment storage and `.env` must remain outside it.

### Known limitations

- Initial deployment target is one application server; horizontal scaling and Redis-backed delivery are not implemented.
- PostgreSQL is the only supported database.
- Direct messages are text-only and do not support attachments, blocking, editing, or user deletion.
- Room messages cannot be edited; moderator deletion is a soft-delete action.
- Browser end-to-end coverage targets Chromium and Firefox, not WebKit, and is a release journey rather than visual-regression coverage.
- Retention cleanup requires an operator-scheduled maintenance command.
- No supported in-place upgrade exists from the incomplete legacy `v0.10.25` snapshot.

### Upgrade notes

Fresh installations should follow `INSTALL.md`.

Existing `v1.0.0-rc.1` installations should:

1. back up PostgreSQL and attachment storage together;
2. deploy the stable source while preserving `.env` and attachment storage;
3. run `composer install --no-dev --classmap-authoritative` for production or `composer install` for development;
4. run `composer migrate` once;
5. verify attachment-directory ownership, `/health.php`, and `/ready.php`;
6. run `composer maintenance:dry-run`;
7. test login, a room message, an attachment download, a direct message, and SSE through the production reverse proxy.
