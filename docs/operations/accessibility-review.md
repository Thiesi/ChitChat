# Manual accessibility review

Automated checks are a regression layer, not a substitute for using ChitChat with assistive technology. Run this review before a stable release that materially changes navigation, forms, dialogs, realtime status, message rendering, account controls, or administration.

Record the operating-system version, browser version, assistive-technology version, ChitChat commit, viewport or zoom setting, tester, date, and result for every run. Do not close a failure as “tool noise” without documenting the exact control, spoken or visual behavior, and rationale.

## Test installation

Use a disposable installation with:

- one Super-Administrator and one ordinary member;
- at least one public room containing normal, `/me`, edited, deleted, attachment, reply, `@mention`, and reacted-to messages;
- one direct-message conversation with an attachment and unread message;
- at least one privacy notification and one submitted moderation report awaiting review;
- a search term that returns results in both room and direct-message scope;
- administrative revision review enabled;
- passkeys enabled when the platform supports them;
- a VAPID keypair configured so the Web Push subscribe/preferences controls render.

Never perform account closure, password reset, role changes, or destructive moderation against a production account during accessibility review.

## NVDA on Windows

Run the journey with current NVDA and both Firefox and Chromium-based Edge or Chrome.

1. Open the signed-out page in browse mode. Confirm that the document title, one level-one heading, tab list, selected tab, username, password, and submit control are announced meaningfully.
2. Operate the Sign in and Register tabs with Left Arrow and Right Arrow. Confirm selection and the newly displayed panel are announced and focus remains on the active tab.
3. Submit an invalid login. Confirm the error is announced once without moving focus unpredictably.
4. Sign in and navigate by landmarks and headings. Confirm the sidebar/navigation region, room list, current room heading, message region, composer, and account controls have understandable names.
5. Enter a room. Confirm connection status and newly received messages are announced without repeatedly reading the entire message history.
6. Navigate several messages in browse mode. Confirm author, time, edited/deleted state, message text, attachment name, metadata, reaction bar, and download control are understandable without relying on visual position.
7. Send a multiline message and operate the attachment picker entirely from the keyboard.
8. Reply to a message and add a reaction from the keyboard. Confirm the reply banner, quoted preview, and reaction picker are announced and operable. Type `@` in the composer and confirm the mention autocomplete list is announced and a suggestion can be chosen without a pointer.
9. Open Direct messages. Confirm the privacy disclosure is encountered before or alongside the messaging interface, conversation controls are named, unread state is understandable, and incoming messages do not cause a focus jump.
10. Open message search from a room and from Direct messages. Confirm the search field, scope control, and result list are labeled, and that activating a result deep-links to and highlights the exact message.
11. Report a message and, as a moderator, open the moderation queue. Confirm the report form, case list, case detail, and resolution controls are named and that queue-state changes are announced.
12. Open Account and Privacy notifications, including the Web Push subscribe/preferences controls. Confirm headings, details/summary controls, checkboxes, status messages, notification read state, and destructive-action wording are announced correctly.
13. As the Super-Administrator, open Administration, privileged step-up, room creation, direct-message inspection, and revision review. Confirm dialogs announce their title, initial focus is sensible, focus remains contained while open, Escape or Cancel closes them, and focus returns to the invoking control.

Repeat important form and dialog steps with NVDA focus mode where applicable.

## VoiceOver on macOS

Run the equivalent journey with current VoiceOver and Safari.

1. Enable Quick Nav and traverse headings, landmarks, links, form controls, and tables or lists.
2. Confirm the authentication tabs expose tab, selected, and panel relationships and can be operated with VoiceOver keyboard commands.
3. Sign in and navigate the chat shell by landmarks and headings before interacting with room and composer controls.
4. Confirm realtime status and new-message announcements are useful but do not continuously interrupt navigation.
5. Exercise room messages, attachments, direct messages, Account, Privacy notifications, and the administrative dialogs described in the NVDA journey.
6. Verify that rotor lists contain meaningful headings, links, form controls, and landmarks without duplicate or empty names.
7. Confirm focus returns to the invoking element after every modal dialog closes.

Document browser-specific differences separately. A success in Safari does not waive a reproducible NVDA/Firefox or NVDA/Chromium failure, and vice versa.

## Keyboard-only journey

Run without a screen reader and without using a pointer.

- Reach every visible interactive control in a logical order.
- Confirm the focus indicator remains visible against every background and is not clipped.
- Operate authentication tabs, room selection, message actions, reaction picker, mention autocomplete, attachment controls, message search, moderation queue and report forms, details elements, notification controls, and administration navigation.
- Confirm Enter and Space activate controls according to their native semantics.
- Confirm dialogs do not leak focus to the page behind them and return focus when closed.
- Confirm no keyboard trap exists in chat history, horizontally scrollable room lists, dialogs, or code/recovery displays.
- Confirm destructive controls are not activated by an unexpected default Enter key press.

## Zoom and reflow

Use browser zoom rather than operating-system display scaling for these checks.

1. At 200% zoom on a 1280 CSS-pixel-wide desktop viewport, exercise authentication, chat, message search, the moderation queue, direct messages, Account, Privacy notifications, and Administration.
2. At 400% zoom or an effective width near 320 CSS pixels, exercise authentication, Account, Privacy notifications, and at least one message composer.
3. Confirm text is not clipped, controls remain reachable, status and error messages wrap, dialogs fit within the viewport, and document-level two-dimensional scrolling is not required.
4. Purpose-built horizontally scrollable controls, such as the compact mobile room list, may scroll in one direction when their purpose remains clear and all items are keyboard reachable.

The automated reflow test covers deterministic document-level overflow at 640 and 320 CSS pixels. It does not replace real browser zoom because browser chrome, platform font metrics, and user settings differ.

## Windows contrast themes and forced colors

With a Windows contrast theme active:

- confirm text and controls remain visible without author colors;
- confirm selected authentication tabs and active rooms remain distinguishable;
- confirm primary and destructive buttons retain visible boundaries and understandable labels;
- confirm focus indicators remain visible;
- confirm connected/error status is not conveyed by color alone;
- confirm disabled controls remain distinguishable from enabled controls.

Playwright forced-colors emulation is a regression check for CSS behavior, not a substitute for a real Windows contrast theme.

## Reduced motion

Enable the operating system's reduced-motion preference and confirm:

- opening pages and dialogs does not introduce decorative movement;
- focus and content changes remain understandable without animation;
- no information depends on animation completing;
- realtime message insertion and status changes do not cause viewport movement unrelated to the user's action.

The stylesheet clamps animation and transition durations under `prefers-reduced-motion: reduce`; manual review must still check script-driven scrolling and focus movement.

## Result record

Use one row per environment and journey:

| Commit | Date | OS | Browser | Assistive technology | Journey | Result | Findings / issue links |
|---|---|---|---|---|---|---|---|
| `<sha>` | `YYYY-MM-DD` |  |  |  |  | Pass / Fail / Blocked |  |

A stable release should not claim manual NVDA or VoiceOver validation unless completed rows identify the tested versions and any accepted limitations. Preserve failed findings in issues even when a release is intentionally blocked or deferred.
