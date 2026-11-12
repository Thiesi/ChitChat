# Accessibility

ChitChat aims to be usable with a keyboard, screen readers, magnifiers, voice control and other assistive technology. This page describes what the web client supports today, how that is tested, what is *not* tested, and how to report a problem.

**Our position:** we check accessibility with automated tests, and we do not test manually with screen readers. If you use ChitChat with assistive technology and something gets in your way, please [tell us](#reporting-a-problem). Reports from real users are the most useful input we get, and we treat them as bugs.

We do not claim conformance with any WCAG level. The automated checks below use WCAG 2.0, 2.1 and 2.2 A and AA rules, but automated tools can only find part of what WCAG asks for.

## What ChitChat supports

### Keyboard

- **Skip links.** The first Tab on a signed-in page offers "Skip to conversation" (Rooms, Direct) or "Skip to content" (other pages).
- **The conversation is one Tab stop.** Tab reaches the current message; it does not step through every message. On a message:
  - **↑ / ↓** move to the previous or next message, and **Page Up / Page Down** move ten at a time.
  - **Home / End** go to the first or last loaded message.
  - **↑ on the oldest message** loads older history; focus stays on the same message.
  - **Enter**, the **context menu key** or **Shift+F10** opens the message's actions menu.
  - **Escape** goes to the message box. From a control inside a message, Escape returns to the message first.
  - There are no single-letter shortcuts, so screen-reader keys aren't intercepted.
- **The message box:** Enter sends, Shift+Enter starts a new line. While the @-mention or /command suggestions are open, ↑ / ↓ choose, Enter or Tab accepts, and Escape closes them.
- **The emoji picker** is navigated with the arrow keys, Home and End; Escape closes it.
- **Menus and panels:** Escape closes the profile card menu, the members panel on phones, and the overlaid list on tablet-sized screens.
- **Direct conversations on phones:** Report and Block sit behind a "More actions" button that is announced as expanded or collapsed. Opening it moves focus to the first action; Escape closes it with focus back on the button.
- **Lists and conversations:** on tablet-sized screens, everything under the overlaid room or conversation list is inert while it's open, and Escape closes it with focus back on its toggle. On phones, choosing a room or conversation moves focus to its title, and going back returns focus to the item you came from.
- **Signing in:** every password field has a show/hide toggle ("Show password" / "Hide password", announced as pressed or not). A shown password is hidden again when the form is submitted. A missing tick on the community-rules checkbox is explained next to it, not in the browser's popup.
- **Choosing a new username** (when a moderator requires one): errors are shown next to the field and tied to it, so screen readers read them with the field.

### What screen readers hear

- **Messages are read as one sentence:** who sent it (or "You"), what it replies to, the text, the full date and time, "read" on your own direct messages once read, and whether it was edited, carries a file or has reactions (with "including you" where you reacted). The bubble itself shows only the time; the day is in a heading.
- **The conversation is a list with day headings,** in reading order, the same in rooms and direct messages.
- **New messages are announced politely, once each,** through a single status region:
  - A message from someone else reads like "Alex: see you soon" (long text is cut after 140 characters), "Mention from Alex: …", "Ping from Alex: …" or "Alex sent a file: plan.pdf".
  - A burst of more than three is summarised, for example "5 new messages from Alex, Sam and 2 more."
  - Nothing is announced while the tab is in the background; when you come back, one line says how many arrived.
  - Your own messages, edits, reactions, typing and presence changes are not announced; they're read when you reach the message.
  - Loading older history says "Loading older messages…" and then how many arrived.
- Opening a room or conversation with unread messages announces its name and the number of new messages.
- **Room rows are links,** each read as one name with what's waiting in it, for example "Garden, 3 unread, mentions you, 2 here now". The open room is marked as the current page. Ctrl- or Cmd-click opens a room in a new tab. Rooms you haven't joined, and invitations, have their own "Join" button next to the link.
- Navigation tabs with unread items are read with their visible name first, for example "Activity, 3 unread", so voice-control users can say what they see.
- A "signed out" line on the sign-in screen, for example after signing out on another device, is also announced.

### Colour, contrast and motion

- **Light, dark or system.** The mode follows your operating system unless you choose Light or Dark under You → Appearance. The choice is stored per device.
- **Colour schemes:** Lantern (the default), Lounge, Dusk, Ember, Rosé, Midnight and **High contrast**.
- **Contrast floors are checked for every scheme in both modes:** at least 4.5:1 for text and icons that carry meaning, and 3:1 for component boundaries and meaningful non-text marks.
- **Forced colours** (for example Windows Contrast themes): selected items, the primary action, focus outlines, the current navigation tab and unread badges keep visible system-colour outlines or borders.
- **Reduced motion:** when your system asks for reduced motion, animations, transitions and smooth scrolling are switched off.
- **Reflow:** core screens fit narrow windows, down to 320 CSS pixels for the conversation, without horizontal page scrolling. Below that width (for example at 200% zoom on a small phone) the room title is cut short and the message box gets cramped; that's a known gap.
- On touch screens the navigation tabs are at least 48 × 48 pixels.

## How it is tested

Every change runs these checks in continuous integration:

- **axe-core** with the WCAG 2.0, 2.1 and 2.2 A and AA rule sets, on each screen and on the states that matter (open menus and dialogs, errors, empty and loading states), in light and dark mode. These run in **Chromium, Firefox and WebKit**.
- **Accessibility-tree snapshots** check what assistive technology is given for the conversation, the actions menu and the navigation: roles, names and reading order.
- **Keyboard tests** drive the conversation (one Tab stop, arrows, Home/End, Enter, Escape, loading older history), the menus, the suggestion lists and the emoji picker without a mouse.
- **A deeper gate** in Chromium checks core surfaces with axe in both modes, reflow without horizontal scrolling, forced-colours affordances and reduced-motion durations. Forced-colours and reduced-motion emulation is only available in Chromium in our test tool.
- **A contrast test** reads the shipped colour tokens and checks the contrast floors above for every scheme in light and dark mode.

### What is not tested

- **No manual testing with screen readers or other assistive technology.** That's the maintainer's decision. Automated checks can't tell whether something is pleasant or efficient to use, only whether certain rules are met.
- **No conformance audit** against WCAG or any other standard.
- Operators who need a formal sign-off for their own installation should arrange their own assessment.
- If you'd like to test yourself, the optional [assistive-technology checklist](operations/accessibility-review.md) suggests journeys for screen readers, keyboard, zoom, contrast themes and reduced motion. It isn't a release requirement; partial runs are welcome.

## Reporting a problem

Please open an [**Accessibility problem** issue](https://github.com/Thiesi/ChitChat/issues/new?template=accessibility.yml). The form asks for:

- your assistive technology and its version, your browser and your operating system;
- what you were trying to do;
- what you expected to hear or happen, and what happened instead;
- optionally, a speech log or transcript.

You don't need to share account details or anyone's messages. If you attach a speech log, please remove anything personal that was read out from your chats first.

If you can't use GitHub, tell the people who run your ChitChat installation; they can pass the report on.
