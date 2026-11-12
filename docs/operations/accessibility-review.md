# Optional assistive-technology checklist

This is an **optional** checklist for people who use ChitChat with assistive technology, for volunteers, and for operators who want their own evidence for an installation. It is **not a release gate**: the project's own verification is automated (axe, keyboard and accessibility-tree checks in three browser engines; see the [accessibility statement](../accessibility.md)), and ChitChat makes no claim of manual screen-reader validation.

If you run any part of this and find a problem, please report it with the [Accessibility problem](https://github.com/Thiesi/ChitChat/issues/new?template=accessibility.yml) form. Partial runs are welcome: one journey in one screen reader is already useful.

For each run, it helps to note the operating system, browser, assistive technology and their versions, the ChitChat version, the zoom level or window size, the date and what happened. Please don't include personal data from real chats.

## Test installation

Use a disposable installation, never a production account, with:

- one Super-Administrator and one ordinary member;
- at least one public room containing normal, `/me`, edited, deleted, attachment, reply, `@mention` and reacted-to messages;
- one direct-message conversation with an attachment and an unread message;
- at least one notification and one moderation report awaiting review;
- a search term that returns results in both room and direct-message scope;
- passkeys enabled when the platform supports them, and a VAPID keypair so the push controls render.

Never perform account closure, password reset, role changes or destructive moderation against a production account.

## Screen reader journey (NVDA, JAWS or Narrator on Windows; VoiceOver on macOS or iOS; TalkBack on Android)

Use any screen reader and browser you actually use. The [accessibility statement](../accessibility.md) describes what you should hear and which keys apply.

1. On the signed-out page, check that the page title, heading, Sign in and Register tabs, fields, the password show/hide toggle and the submit button are announced meaningfully. Submit an invalid sign-in and check that the error is announced once, without focus jumping. On Register, leave the community-rules box unticked and submit: the reason should be announced once and tied to the box, next to it rather than in a browser popup.
2. Sign in. Navigate by landmarks and headings: the navigation (Rooms, Direct, Activity, You), the room or conversation list, the conversation heading, the message list and the message box should all have understandable names. Try the skip link.
3. Open a room. Tab to the conversation and move between messages with the arrow keys, Home and End. Each message should be read as one sentence (author, text, date and time, and any reply, file, edit or reactions) without re-reading the whole history.
4. Open a message's actions with Enter or Shift+F10. Reply, react and copy from the keyboard. Escape should return to the message, then to the message box.
5. In the message box, type `@` and `/`. Check that the suggestions are announced and can be chosen without a pointer, and that Enter sends while Shift+Enter starts a new line.
6. Have the second account send messages. Check that new messages are announced once each without moving focus, that bursts are summarised, and that a background tab gets one "while you were away" line on return.
7. Open Direct. Check that the privacy notice is encountered, conversations and unread state are understandable, and incoming messages don't move focus. On a phone-width window, open "More actions" in the conversation header: it should be announced as expanded or collapsed, and Escape should close it and return focus to it.
8. Search from a room and from Direct. The field, scope and results should be labelled, and choosing a result should open the exact message.
9. Report a person and a message. As a moderator, open the moderation queue, a case and its resolution controls.
10. Open You (account, notifications and push, appearance) and, as the Super-Administrator, Administration and Operational settings. Dialogs should announce their title, keep focus inside while open, close with Escape or Cancel, and return focus to the control that opened them.

Browser differences matter: a success in one browser doesn't rule out a failure in another, so note the combination.

## Keyboard-only journey

Without a screen reader and without a pointer:

- reach every visible control in a logical order, with a visible focus indicator that isn't clipped;
- operate the sign-in tabs, navigation, lists, message actions, suggestions, emoji picker, attachments, search, reports, moderation, You and Administration;
- check that dialogs don't leak focus behind them and return focus when closed;
- check that there's no keyboard trap, and that destructive actions aren't triggered by an unexpected Enter.

## Zoom and reflow

Use browser zoom, not operating-system display scaling.

1. At 200% zoom on a 1280-pixel-wide window, use sign-in, chat, search, moderation, Direct, You and Administration.
2. At 400% zoom (about 320 CSS pixels wide), use sign-in, You and at least one message box.
3. Check that text isn't clipped, controls stay reachable, messages wrap, dialogs fit, and the page doesn't need two-dimensional scrolling. Below 320 CSS pixels the room title and message box are a [known gap](../accessibility.md#colour-contrast-and-motion).

The automated tests check document-level overflow at fixed widths. Real zoom also involves browser chrome, platform fonts and user settings, which is why this is worth a look.

## Contrast themes and forced colours

With a Windows Contrast theme (or another forced-colours mode) active, check that text and controls stay visible, the selected tab and current room stay distinguishable, buttons keep visible boundaries, focus stays visible, status isn't shown by colour alone, and disabled controls look disabled. The automated forced-colours check is CSS emulation in Chromium, not a real contrast theme.

## Reduced motion

With the operating system's reduced-motion preference on, check that opening pages and dialogs doesn't add decorative movement, that nothing depends on an animation finishing, and that incoming messages and status changes don't scroll the view on their own.

## Recording results

If you want to keep results, one row per environment and journey works well:

| ChitChat version | Date | OS | Browser | Assistive technology | Journey | Result | Findings / issue links |
|---|---|---|---|---|---|---|---|
| | `YYYY-MM-DD` | | | | | Pass / Fail / Blocked | |

Please file failures as issues with the Accessibility problem form, so they reach the maintainers.
