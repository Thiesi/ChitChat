# Lantern Next: every brand-level change, for ChitChat Mobile

Status: complete for the web's overhaul (slices L0 to L10, tracking: #381, finished 2026-11). Everything below is shipped on `main`. The reasoning behind the whole overhaul is in [lantern-next-direction.md](lantern-next-direction.md); the [handoff](#handoff-for-chitchat-mobile) at the end says what to adopt first, what the web did not build, and what is still open.

**Who this is for.** ChitChat Mobile's own visual overhaul starts from this document, not from the Lantern values in its repository (`docs/design/overhaul-handoff.md` there, "First read"). For this run the owner suspended the paired-PR rule: the web leads, and CCM adopts later. Until then web and app differ on purpose.

**How to read it.** Each entry gives the old value, the new value and the reason. Names are the web's CSS custom properties with CCM's token name in brackets. "Web only" marks a change CCM doesn't need to mirror. Contrast figures are WCAG 2.x ratios for the exact pair named, and `tests/js/design-contrast.test.js` holds every scheme to them.

## What doesn't change

Persimmon is the one action colour, with ink labels. Honey means people or attention, always with text. The warning colour comes with its triangle. Rose is for errors and destruction only, never colour alone. Dusk is stewardship and links. No green. The mark's geometry, the eight identity hues and their hash, squircle rooms and round people, tail-less bubbles with a chit, the icon set's grid and stroke, and Bricolage Grotesque with the system stack all stay.

## L0: foundations (#382)

### Colour values

| Token | Theme | Old | New | Reason |
|---|---|---|---|---|
| `--ground` (`ground`) | light | `#FBF7F1` | `#F5F0E8` | A deeper paper, so white surfaces lift off it without outlines (surface to ground goes from 1.07 to 1.13). On it: text 16.4, muted 6.13, persimmon text 4.99, honey text and warning 5.05, Dusk 5.78, rose 4.76, field line 3.21. |
| `--raised` (`raised`) | light | `#F3ECE2` | `#F1EADF` | Incoming bubbles and quiet controls now sit on white (1.19 against it). On it: text 15.6, muted 5.82, persimmon text 4.73, Dusk 5.49, rose 4.52, honey text 4.80. |
| `--outgoing` (`outgoing`) | dark | `#8A3A1E` | `#742F17` | A deeper ember: less glare at night, and text on your own bubbles goes from 6.74 to 8.43. The honey unsure line on it is 5.53. Muted text on it is only 3.82, so nothing muted sits on an outgoing bubble; links and times there use `--on-outgoing`. |
| `--error` | Dusk scheme, light (web only) | `#C0392B` | `#BD3729` | Found by the new check: rose text on that scheme's `--raised` was 4.49. Now 4.64. |

Everything else in Lantern light and dark keeps its value. Midnight (the web's name for CCM's AMOLED values) inherits the new dark `--outgoing`.

Kept at the old Paper on purpose: the installation-icon background preset "Lantern paper" (`#FBF7F1`). It is a stored choice that is drawn into icon files, not a page colour.

The browser theme colour for light mode follows the ground (`#F5F0E8`), so browser chrome still matches the page.

### Where the conversation sits

| What | Old | New | Reason |
|---|---|---|---|
| Transcript background (rooms and direct), and the band behind the composer | `ground` | `surface` | "The stage": the conversation is the lit surface and the paper ground is only the frame around it. Bubbles (`raised` on `surface`) separate at 1.13 to 1.19 in every scheme, where `raised` on `ground` was about 1.10. Every text role that can appear on `raised` is now tested there. |

For CCM this means the transcript screen's background is `surface` and incoming bubbles stay `raised`. The web names the role `--stage`. **Midnight (CCM: AMOLED black) is the exception:** its stage stays `ground`, true black, as the AMOLED rule requires for large areas; bubbles are `raised` on black there (1.25).

### New scales

None of these existed as tokens before. From L1 on, the stylesheets use them instead of one-off values (about 40 font sizes and 30 radii today).

**Type.** Faces unchanged. Bricolage Grotesque 800 is kept for Display L only; every other display role is 700.

| Role | Token | Size and line height | Face and weight | Use |
|---|---|---|---|---|
| Display L | `--type-display-l` | `clamp(2rem, 1.2rem + 3vw, 3.25rem)` / 1.1 | Bricolage 800, −0.02em | The installation's name on signed-out pages |
| Display M | `--type-display-m` | 1.625rem (26px) / 1.1 | Bricolage 700, −0.015em | Page and list titles, dialog titles, empty-state headlines |
| Title | `--type-title` | 1.1875rem (19px) / 1.3 | Bricolage 700, −0.015em | Conversation and section headers |
| Message | `--type-message` | 1rem (16px) / 1.45 | system 400 | Message text, never the display face |
| Body | `--type-body` | 0.9375rem (15px) / 1.45 | system 400, names 600 | Lists, settings, buttons |
| Label | `--type-label` | 0.8125rem (13px) / 1.3 | system 600, sentence case | Section and field labels, small buttons |
| Caption | `--type-caption` | 0.75rem (12px) / 1.3 | system 400, tabular numerals | Times, hints, badges (700) |

Changes from CCM's table: section labels lose uppercase and tracking; the smallest readable size is 12px (CCM allows 11); Display M is 26px for every top-level title.

**Shape.**

| Token | Value | Use |
|---|---|---|
| `--radius-1` | 8px | Chips, menu items, code |
| `--radius-2` | 12px | Icon buttons, small tiles |
| `--radius-field` | 16px | Fields, list rows, cards, popovers |
| `--radius-bubble` | 20px | Bubbles |
| `--radius-sheet` | 28px | Sheets, dialogs, the stage |
| `--radius-pill` | 999px | Buttons, badges, the composer, segmented controls |

The last four are CCM's shape table as it stands; the first two are new, for the small things the table didn't name.

**Space.** `--space-1` to `--space-8`: 4, 8, 12, 16, 20, 24, 32, 48px.

**Targets.** `--target-min`: 44px, and 48px for coarse pointers (CCM: 48dp everywhere).

**Elevation.** Three levels replace one all-purpose shadow.

| Token | Light | Dark | Use |
|---|---|---|---|
| `--elev-1` | `0 1px 2px rgb(60 40 20 / .07), 0 0 0 1px rgb(60 40 20 / .05)` | a 1px edge, `line` mixed 70% into `ground` | Resting: the stage, a selected row |
| `--elev-2` | `0 2px 6px rgb(60 40 20 / .07), 0 14px 34px -8px rgb(60 40 20 / .2)` | 1px `line` edge and `0 14px 34px rgb(0 0 0 / .5)` | Floating: menus, pickers, toasts |
| `--elev-3` | `0 8px 20px rgb(20 17 28 / .1), 0 32px 72px -16px rgb(20 17 28 / .34)` | 1px `line` edge and `0 32px 72px rgb(0 0 0 / .65)` | In front: dialogs and sheets |

In dark themes depth is tone plus a fine edge. High contrast and Midnight use edges only, as CCM's AMOLED rule says.

**Motion.** `--motion-fast` 120ms (hover, press, menus), `--motion-base` 200ms (dialogs, toasts), `--motion-slow` 280ms (sheets, panes); `--ease-out` `cubic-bezier(0.2, 0, 0, 1)` for entering and `--ease-in` `cubic-bezier(0.4, 0, 1, 1)` for leaving. Only transform and opacity move, nothing loops, and with reduced motion every change is an instant cut (CCM: "Remove animations"): the three duration tokens themselves drop to 0.01ms under `prefers-reduced-motion: reduce`, in the token file every page loads, so anything timed with them switches off everywhere. CCM's range was 150 to 250ms with standard easing.

### Derived colour roles

No new hues. Each resolves from the scheme's own values.

| Token | Light | Dark | Use |
|---|---|---|---|
| `--stage` | `surface` | `surface`; `ground` in Midnight | The conversation's background |
| `--signature-wash` | persimmon 18% into `surface` (about `#FCE4DC`) | about `#42272A` | The current tab's pill (already drawn this way; now a token). Text on it 15.3 / 11.7. |
| `--selected` | `surface` | `raised` | The selected list row |
| `--field` | `surface` | `ground` | Field fill |
| `--toast`, `--on-toast` | `text`, `ground` (16.4) | `raised`, `text` (13.4) | Toasts |

L0 only defines the scales and derived roles. The slices that start using each one record it below.

## L1: controls (#384)

### Buttons

| What | Old | New | Reason |
|---|---|---|---|
| Shape | Rounded rectangles (12px) outside sign-in, pills on sign-in | Pills everywhere (`--radius-pill`) | CCM's own rule ("Buttons: full pill"); the web had drifted. |
| Primary button | Persimmon with a darker 3px lower edge | Flat persimmon, ink label | The lower "lip" read as dated. Hover and press still move the fill to `--signature-hover` (label 8.1:1). |
| Secondary button | `raised` fill with a `fieldLine` border | No fill, `fieldLine` border, label at 600 | One outline button on any ground. The boundary stays 3:1 against `ground` and `surface`; hover tints it with 7% of `text` (so it shows on any ground, a `raised` card included) and darkens the border to `text`. |
| Destructive button | Rose outline, 12px corners | Rose outline pill, label at 600 | Same meaning: rose, with wording, never colour alone. |
| Stewardship primary | Dusk with a darker lower edge | Flat Dusk | As the primary button. |
| Press | Every button moved down 1px | The button family scales to 0.98, only when motion is welcome | Motion that explains (a press), off under reduced motion. |

### Fields

| What | Old | New | Reason |
|---|---|---|---|
| Corner | 12px | `--radius-field` (16px) | CCM's shape table. |
| Fill | `ground` | `--field`: `surface` in light, `ground` in dark | One rule instead of "paper-tinted inside a white card". The `fieldLine` boundary is unchanged (3.6:1 on white). |
| Focus | Border plus a 2px outline 2px outside it (two rings) | One ring: the 2px outline sits on the border | Say it once. Still an outline, so forced colours keep it. |

### Badges

One size for the unread count (Rooms, Direct, the navigation) and the "@" mark: 20px, 12px digits at 700 with tabular numerals. Before: 18 to 24px with 10.9 to 12.5px digits at 700 to 800. Meanings unchanged: persimmon with a number for unread, honey with "@" for a mention.

### One avatar for a person

A person without a picture is the same pebble everywhere: their identity hue with ink initials (8.4:1 or more). The members panel, the profile card and You used to draw a pale tinted circle with coloured initials in one of four tones; they now match the conversation and the Direct list. Guests keep the dashed circle. CCM already draws people this way.

### Room initials

| What | Old | New | Reason |
|---|---|---|---|
| What a room's lantern shows | "#" for every room | The room's initial: the first grapheme of its name, upper-cased where the script has case; "#" when the name is empty | Rooms differed by colour alone at a glance. Owner decision, 2026-11-15. |
| Face | system, 700 | Bricolage Grotesque 700 | The lantern is an identity mark, a display role. |

The initial is decorative. On the web the lantern's text stays "#" (zero-size) and the letter is generated content with empty alternative text, so a room is still read and found as "# name". Ink on the identity hue, as before. For CCM: draw the first grapheme (`BreakIterator`), keep the lantern out of the accessibility tree, and leave room names untouched.

## L2: the frame (#386)

| What | Old | New | Reason |
|---|---|---|---|
| Installation name on wide screens | Under the rail's icon, 11px, clamped to two lines | At the top of every list pane and page, above the title, as a Label (13px, 600, muted); it wraps to as many lines as it needs | Owner decision. Operators name their installation; a long name needs room and must never be cut or scroll the page. The rail keeps the name as text for screen readers. |
| The rail | `surface` with a line beside it and a hairline under the brand | The page's own `ground`, no lines, 84px wide as before (every item takes the whole rail and its label wraps inside it rather than spilling out); the installation's icon in a 22% rounded frame with a fine edge | The quiet frame: navigation is paper and ink. The edge keeps a dark icon visible on a dark rail. |
| Any installation icon | Shown as given | Unchanged: shown as given, never recoloured or masked | Operators set their own icon. |
| The stage's edge | A `line` border plus the all-purpose shadow, 24px corners | `--elev-1` and `--radius-sheet` (28px) | Depth by the resting elevation, not a line and a heavy shadow. In forced colours the transparent border is painted, so the edge stays. |
| List and page titles | 26 to 52px in two weights, depending on the page | Display M (26px, Bricolage 700) on every list pane and page | One page header everywhere. |
| The installation eyebrow on pages | Upper case, tracked, in three colours depending on the page | The same Label as on list panes: sentence case, muted | As above. |

For CCM: the phone list panes already show the icon with the name; what to mirror is the Label style for it, Display M for every top-level title, and the stage's edge.

## L3: lists (#387)

| What | Old | New | Reason |
|---|---|---|---|
| The selected row (Rooms, Direct, You) | A 13% persimmon wash, a 3px bar along the whole edge and a persimmon-tinted outline | The `--selected` fill (`surface` in light, `raised` in dark) at the resting elevation, one short bar (3 × 22px, persimmon as text), the name at 700 | Say it once. The lit row is the lantern idea in small; the bar and the weight are the cues that aren't colour. |
| Row names | 650, and 800 when unread | 600, and 700 when unread or selected | Two weights instead of three; unread still has its count. |
| Row sub-line and time | 12.8px and 11.5px | Label (13px) and Caption (12px, tabular numerals) | The type scale. |
| Row shape and hover | 10px corners, `surface` on hover | `--radius-field` (16px); hover is a 6% tint of `text` | Hover shows on any ground. |
| Section labels ("Your rooms", "Other rooms", "Conversations") | 11.5 to 12.8px, 700 to 750, upper case, tracked | Label: 13px, 600, sentence case | Calmer, and kinder to long words and zoom. Changes CCM's "uppercase, +0.02em" label. |
| The mention notice | A honey box with a honey-brown outline and an underlined bold "Open" | A honey strip without an outline; "Open" is an outlined pill on `surface` | A strip, not a box. The pill keeps a 3:1 boundary of its own. |
| The glow's gap in a row | Always `surface` | The colour the row sits on: `ground`, or `--selected` for the open room | The ring reads as a ring on both. |
| Connection state | 13px with an 8.8px dot | Caption (12px) with a 7px dot | It reports; it shouldn't compete with the title. Shape and wording for reconnecting and offline are unchanged. |

## L4: the conversation (#388)

### Times: bubbles hold words

| What | Old | New | Reason |
|---|---|---|---|
| A message's time | Inside every bubble, floated at its end | Once per run: beside the name that starts someone else's run in a room; under the run's last bubble for your own messages and on both sides in Direct | Owner decision, 2026-11-15. Within a run the messages are minutes apart; the bubble is for words. |
| The other messages' times | Always drawn | Kept in the page for every message as `<time datetime>` in its own accessible text (title: the full moment); drawn as a small chip on the bubble's lower edge under the pointer, when focus is inside the message, and when the arrow keys reach it | Nothing is lost: screen readers read sender, text, time as before, and sighted keyboard and pointer users get the exact time where they look. The chip overlaps; it never moves the conversation or widens the page. |
| Direct message time | Text in a `<span>` | A real `<time datetime>` inside it | The same contract as rooms. |

For CCM: show the run's time at the name (incoming) or under the last bubble (outgoing); keep each bubble's time in its accessibility description ("sender, text, time", A-06) and reveal it on long-press or focus.

### Header, bubbles, composer

| What | Old | New | Reason |
|---|---|---|---|
| Conversation title | 26px, with a 14.4px sub-line | Title (19px, Bricolage 700) with a Label sub-line | The conversation's name leads its own stage without shouting over it. |
| Bubble padding and corner | 8.8 × 14.4px, 20px | 8 × 13.6px, `--radius-bubble` (20px) | A little closer to the words, now that no time floats inside. |
| Composer | 80px tall when empty, 20px corners, a soft shadow, `surface` fill | One slim row (about 50px), a pill that keeps a 26px corner as it grows, the `--field` fill, no shadow; Send is `--target-min` round | A field like every other field. |
| Composer hint | One long line, always | "Enter sends · Shift+Enter adds a line · Type / for commands"; the rest ("/help for formatting", attachments' caption) is in the page for every reader and drawn while there is text in the field (not on focus alone: a line that leaves with focus moves the conversation under a click) | One short hint; the full help stays reachable. |
| Emoji picker | A line and the all-purpose shadow | `--elev-2`, `--radius-field` | The floating level. |

### Signature moments

| What | Old | New | Reason |
|---|---|---|---|
| A message arriving live | Appears | Rises 8px and fades in over `--motion-base` (200ms, ease-out), once. Only messages appended live to a conversation already on screen: never the first fill, a room switch or older history | Motion that explains a change of state (owner decision: bolder here). Under reduced motion the rule doesn't apply and the token is cut, so it is an instant change. |
| The empty room | A small outlined bubble and a muted 26px line | CCM's empty-room drawing, larger and lit: a dashed bubble ("someone could speak here") with its persimmon chit in a still honey glow; the words in Display M, in ink | An empty state that invites (J-03). Static, drawn from Lantern's shapes only. |

## L5: the sign-in family and the mark family (#389, #227)

| What | Old | New | Reason |
|---|---|---|---|
| The sign-in drawing | Loose lanterns, pebbles and one bubble | A small conversation in Lantern's shapes: someone speaks, you answer, someone is typing (three still honey dots), with two room lanterns. Its three groups settle into place once (`--motion-slow`, 120 to 400ms apart) | A signature moment (owner decision). It shows what the product is. Decoration only; static under reduced motion. |
| The glow on the wide sign-in screen | One honey glow just behind the mark | The lantern's light: wider and softer, two stops, still | The lockup is the hero. It never pulses and is never baked into the mark. |
| The sign-in card | A line and the all-purpose shadow | `--elev-2`, `--radius-sheet`; the greeting in Display M | The floating level. |
| Sign in / Register | A pill inside a pill with an outline; the chosen tab in persimmon text | A segmented control: `raised` track, the chosen tab a `surface` thumb at `--elev-1` in bold ink | Weight and the thumb's shape carry the choice, not colour. |
| The second sign-in step | Two persimmon buttons when an authenticator code is on offer, the guest offer above it, a system-face heading | One main button (the code; the passkey is an outline button), no guest offer until the step ends, the heading in Display M | CCM's own rule: one persimmon button per screen. |
| The mark family | One file | Named versions with rules: primary, paper, one colour (also reversed), small sizes, the lockup with the name, the renamed-installation lockup, the glow on signed-out pages. See [mark.md](mark.md) | #227, approved 2026-11-15. |
| "on ChitChat" and "Powered by ChitChat" | Text only | Signed with the one-colour mark, drawn in the text's colour | A renamed installation's icon and name lead; ChitChat signs quietly. |

For CCM: the one-colour mark is the monochrome launcher layer's geometry; the welcome screen may take the wider glow and the conversation drawing.

## L6: Direct, Activity, Search (#393)

| What | Old | New | Reason |
|---|---|---|---|
| Report and Block in Direct | Two outlined buttons in the header on wide screens; a "More actions" menu on phones | One "More actions for <name>" menu at every width: a 44px button, a menu at the floating elevation with the field corner, items "Report <name>…" and "Block <name>" / "Unblock <name>" | Owner decision. The header keeps one row and the conversation leads; the actions are one click away. One control, not two. |
| The menu's behaviour | A disclosure | A menu button: `aria-haspopup="menu"`, `aria-expanded`, `role="menu"` and `menuitem`s; arrow keys, Home, End; Escape returns to the button; a dialog opened from an item returns there too | The pattern people who use a keyboard or a screen reader expect. |
| An Activity notice | An outlined card with one or two outlined buttons | A quiet row: no outline, the field corner, a hover tint. A row with somewhere to go is one link you can click anywhere (its link stretched over the row); "Mark as read" is a round 44px tick at the row's end, named with the notice it marks | Rows, not cards. No control is nested in another. |
| Unread in Activity | A dot in the row's corner and bold words | A dot before the words and bold words; "Unread:" for screen readers as before | The corner now holds the tick. |
| Activity day headings | Upper-case tracked | Label type in sentence case | As the lists' section labels (L3). |
| Search | A card holding the field, the scope and the button; result cards with shadows; the chosen scope in persimmon | A bar on the page (no card); the chosen scope a lit chip in bold ink with a full ring; results as rows with a soft edge and the field corner | One quiet bar. The chosen scope is carried by weight and the ring, not by colour. |

For CCM: the Direct header's actions sit behind one "More" control; Activity rows are whole-row targets with a separate "mark read" control at the end.

A note on Activity's rows (L6): the row's link is stretched over the whole row, so anything else that should take a click inside a notice (a second link in its words, say) must be raised above it the way the tick is (`position: relative; z-index: 1`). Today the server's notice text carries no links.

## L7: You (#397)

| What | Old | New | Reason |
|---|---|---|---|
| A section's title | Bricolage 400 at up to 42px | Display M (26px), 700; phones keep their smaller size | One page header: a title, not a poster. It matches the list's own title. |
| Settings cards | A 24px corner, a line and the all-purpose shadow; headings at 21.6px | `--radius-sheet`, `--line-soft`, `--elev-1`; headings in Title type; `--space-4` between cards | The resting level. Cards recede; their contents lead. |
| The colour scheme | A row of bare swatches with names; the chosen one ringed in persimmon with a bold name | Each scheme is a chip (44px tall): swatch and name. The chosen one has a full ring in ink, its name in bold and a tick on its swatch | Named radios as before; the choice is told by more than colour, and each is a proper target. |
| In forced colours | The ring only | The chip's border in `Highlight`, the tick in `HighlightText` on `Highlight` | The choice stays visible. |

## L8: stewardship (#399)

| What | Old | New | Reason |
|---|---|---|---|
| Cards on Administration, Operational settings, System status, the Moderation queue, direct-message inspection and revision review | A line, corners from 12 to 16px, the all-purpose shadow on some | `--radius-sheet`, `--line-soft`, `--elev-1`, the same on every page | One card. Stewardship reads as part of the same product, in its Dusk frame. |
| Rows inside a card (members, invitations) | Each a bordered box inside the card | Rows with a soft rule between them | No boxes within boxes. |
| Small labels ("ChitChat", "Reported person · from …", "Evidence at first report") | Upper-case, tracked | Label type in sentence case | As the lists' section labels (L3). |
| The page's tabs (Users, Rooms, Audit) | In an outlined box | On a quiet `raised` track; the chosen tab keeps its steward edge and wash | Fewer lines; the choice still has a shape. |
| Cases in the queue | Field-line boxes; the open case told by its steward edge and wash alone | Rows with the field corner and a soft edge. The open case keeps its steward edge and wash and gains the short bar at its start (the lists' selected-row mark, L3); in forced colours it takes a 2px `Highlight` border | Rows. The open case is told by more than colour (it was colour alone). |

Unchanged on purpose: the steward accent on actions, links and focus (S6), the sensitive-access notice, rose for destructive actions, and every text field's boundary. In forced colours the cards, the rules between rows, the tabs' track and the cases take system-colour edges.

## L9: overlays, toasts and motion (#401)

| What | Old | New | Reason |
|---|---|---|---|
| Toasts | Top right; a raised card with a line; an error told by a rose line | At the bottom, centred, above the composer or the phone's bottom bar; dark on light themes and raised on dark ones (`--toast`, `--on-toast`), the field corner, `--elev-2`. An error carries the warning sign before its words as well as a rose edge. A toast takes no clicks | A toast answers what you just did, near where you did it, and never sits on the header's controls. An error is told by a shape, not only a colour. |
| Where the toasts stop | Fixed 1rem from the top | `shell.js` measures what is fixed at the bottom (the composer, which grows with its text, or the bottom bar) into `--toast-clear` | They never cover the composer or the bar. |
| Toasts and assistive technology | The region is polite; error toasts are alerts | Unchanged | #369, #372. |
| Dialogs | A 24px corner, a line, the all-purpose shadow | `--radius-sheet`, `--line-soft`, `--elev-3` | The top level. |
| How dialogs and sheets arrive | Dialogs appeared; phone sheets faded in from 60% | A dialog settles 8px into place and a phone's sheet rises 24px from the bottom edge, over `--motion-base`, while the backdrop dims in over 200ms. The box never fades. With reduced motion nothing moves | A signature moment (owner decision). A fading box is hard to read for a moment and is measured half-drawn by contrast tools, so the box moves instead. |
| A toast arriving | Appeared | Rises 12px over `--motion-base`, without fading | Readable from its first frame. |
| Reduced motion | The motion tokens are cut in `tokens.css`; every other animation and transition is cut in `accessibility.css`, which the stewardship and status pages don't load | Both cuts are in `tokens.css`, which every page loads, with page-to-page view transitions included | No page is left out. |

A command named in a toast keeps its chip on the toast's own colours (a 16% mix of `--on-toast`), not the page's `raised` chip.

Carried to L10 (#404): the page-to-page cross-fade (cross-document view transitions) that the direction planned for the frame, whose reduced-motion cut is already in place; and the remaining empty states and the status pages.

## L10: the other empty places, the status pages, page to page (#404)

| What | Old | New | Reason |
|---|---|---|---|
| An empty Direct conversation | A muted centred line | The empty room's drawing (the dashed bubble, its chit, a still honey glow) with the next step in Display M | A signature moment (owner decision); the same care as the empty room (L4). |
| An Activity with nothing new | A bold line and a button, at the start of the card | The drawing, "You're all caught up." in Title type, and the way back as a button beneath, centred | The same. |
| A search that found nothing | A muted status line | The drawing with the status line beneath in Title type, centred. Only for that outcome: the prompt, "Searching…", results and an error stay a plain line. It is the same element with the same `status` role throughout | The same. An error is never drawn as "nothing here", and the drawing never flickers while a search runs. |
| The status pages (not found, unavailable) | A card with a line and the all-purpose shadow; the installation's name upper-case; the heading in Bricolage 400 | `--radius-sheet`, `--line-soft`, `--elev-2`; the name a Label in sentence case; the heading bold; the empty bubble and its chit above it | The same care, by stylesheet alone: the pages' markup, their lack of script and their small footprint (#344, #348) are untouched. |
| Going from page to page | A hard cut | A 150ms cross-fade of the page in browsers that can do it between documents (`@view-transition`), with the rail holding still. Declared only under `prefers-reduced-motion: no-preference` | The direction's frame. No script, no request; browsers without it cut as before. |

The drawing gives way on narrow screens (24rem and below), where the words need its room. In forced colours it is drawn in `CanvasText` without its glow.

## Handoff, for ChitChat Mobile

The web's overhaul is finished. This section is the short way in for CCM's own run.

### What to adopt, in this order

1. **The values and the scales (L0).** Three colour values changed (`ground` and `raised` in light, `outgoing` in dark); the type, radius, space, motion and elevation scales are new. Everything later is built from them. The conversation sits on `surface`, except in AMOLED black.
2. **The controls (L1):** one button family, calmer fields with a 3:1 boundary, one badge, one avatar, room initials in place of "#".
3. **The frame and the lists (L2, L3):** the installation's name above each list, Display M for top-level titles, the one selected-row mark (the lit fill and the short bar).
4. **The conversation (L4):** times once per run with every message's time kept for assistive technology; the slim composer; the arriving message; the empty room's drawing.
5. **The sign-in family and the mark family (L5).** See the mark family below.
6. **Direct, Activity, Search, You, stewardship (L6 to L8):** one "More" control in Direct; whole-row notices with a separate "mark read" control; colour schemes as chips with a tick; the same quiet card everywhere.
7. **Overlays and motion (L9, L10):** toasts at the bottom, clear of the composer; dialogs that settle and sheets that rise without fading; the drawing on every empty place.

The lines marked "For CCM" in the sections above say how each of these translates. "Web only" marks what CCM need not mirror.

### The mark family

Approved by the owner on 2026-11-15 as the answer to #227; the full text and the rules are in [mark.md](mark.md). The geometry is CCM's own (`docs/design/app-mark.md` there) and did not change. Two files are new on the web:

| Version | File | What it is | For CCM |
|---|---|---|---|
| **Paper** | `public/assets/app-icons/lantern-mark-paper.svg` | The primary mark on a paper tile: persimmon bubble and chit, ink eyes (`#14111C`), on `#F5F0E8`, the new light `ground`. Same 108-unit canvas and safe zone as the primary. For light documents and print. **Not** an installation icon preset | New to CCM. Its tile is the new light `ground` value, so it only matches a light screen once CCM has adopted L0's `ground`. Not a launcher icon. |
| **One colour** | `public/assets/app-icons/lantern-mark-mono.svg` | The bubble with its eyes cut out, and the chit, in a single colour. Drawn in the text's colour wherever the mark signs ("on ChitChat", "Powered by ChitChat"); in forced colours it takes the system's text or link colour | CCM's monochrome launcher layer (`ic_launcher_monochrome.xml` at ChitChat Mobile `ed28929`); nothing new is asked of the app. |

The primary mark, the small sizes, the lockup with the name, the renamed-installation lockup and the glow are unchanged or described in mark.md.

### What holds the design in place

- `tests/js/design-contrast.test.js`: every contrast figure in this document, in every scheme.
- `tests/js/motion-tokens.test.js`: the motion tokens, the reduced-motion cut on every page, and where the page cross-fade is declared.
- `tests/js/css-literals.test.js`: colour literals only in `tokens.css`. `tests/js/css-structure.test.js` checks the stylesheets' structure.
- The mocked browser specs (`tests/e2e/presentation-*.spec.js`) hold the behaviour each section describes: names and roles, keyboard paths, forced colours, reflow to 180 CSS px, and axe at 1280 and 360.
- Six screenshot baselines (Lantern dark, Chromium on Linux), regenerated only from CI's own images.

### Planned but not built, and why

- **Hash links for Administration's tabs.** Suggested for L8; not done. The tabs stay in-page.
- **Three new glyphs** (chevron, plus, horizontal more) for `public/assets/icons/`. Not needed in the end: the existing `more.svg` and inline drawings served. Nothing to record for CCM.
- **A full redesign of Search's header and notice.** L6 made the form a bar and the results rows; the header card and the notice above it keep their earlier look.

### Open items

- **#394: do a message's name-line actions open by tap on a real touch device?** Not settled. During L4 a tap on "More" failed to open the menu in Chromium's touch emulation, but that was in an experimental layout that moved the button between touch and click, and it was dropped. As shipped, touch reaches a message's actions through the sheet that a tap on the bubble opens, which is tested. Browser emulation can't settle the rest: **it needs a check on a real phone**, iOS Safari above all.
- **One screenshot baseline is behind what ships.** `admin-rooms-desktop` was drawn before L8 restored the rose edge on the "Delete this room" card, so it shows that one card's edge soft where the page ships it rose. The difference is a one-pixel edge inside the comparison's allowance, so the comparison passes and CI, which draws an image only for a failing comparison, has had nothing to regenerate from. Left as it is by the technical lead's decision; the next slice that moves that baseline will pick it up.
- **An axe sweep that did not finish locally.** L10's sweep over all 13 scheme and theme combinations was stopped when the machine ran short of memory, after Direct and most of the empty Activity (all clean). The empty Search and the not-found page were swept only in Lantern light and dark (clean). CI's axe runs passed. The colours involved are tokens swept in every scheme by earlier slices.
- **`zzzzzzzzzzzz-replies-mentions` in WebKit** timed out in CI during L4 and L8 (#402); fixed outside this overhaul by #405.
- **The pictures.** Each slice's before-and-after pictures are on the pictures-only branch `pr-assets/lantern-next`, linked from the slices' pull requests at fixed commits. No single final capture set was taken at the end (the machine was short of memory); the per-slice sets are the record. The branch is never merged and may be deleted after the handoff, which breaks those links, so anything CCM wants to keep should be copied first.
