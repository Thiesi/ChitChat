# Lantern Next: direction for the ChitChat web overhaul

Status: **approved by the owner on 2026-11-15** (decisions in section 8). Tracking: #381. This is the phase-1 audit and direction as written on 2026-11-14/15, against `main` at `106b47e` (v3.14.0 plus #378/#380). It records the intent. The values that actually ship, slice by slice, are in [lantern-next.md](lantern-next.md), which is also what ChitChat Mobile adopts.

The audit's 152 captures, the owner's visual sheet and the throwaway capture spec stay in the design lead's working folder. They are evidence for this document, not part of the repository.

## 1. Summary

Lantern is a good brand and stays: persimmon for the one action, honey for people, Dusk for stewardship, rose for errors and destruction, the mark, the eight identity hues, pebbles and lanterns, tail-less bubbles with a chit, the CCM icon set, Bricolage Grotesque plus the system stack.

What keeps the client from reading as premium is not the palette. It is drift: about 40 font sizes, 30 corner radii, three page-header patterns, four renderings of one person's avatar, three simultaneous cues for "selected", cards inside cards, and almost no motion. The proposal is therefore an evolution with strict systems, not a repaint:

1. **A lit room in a quiet frame.** The frame (rail, list panes, page ground) is paper and ink with no borders. The content the person came for sits on one clean surface, the "stage". Every signed-in page uses the same rail + (optional list) + stage frame.
2. **One rhythm.** A 7-step type scale, 6 radii, a 4 px spacing scale, 3 elevations, 3 durations, all as tokens.
3. **Say it once.** One selected style, one focus ring, one avatar renderer, one badge size, one button family.
4. **Three colour values change.** Everything else in the palette is untouched.
5. **Motion that explains.** Sheets, dialogs, menus, toasts and arriving messages get short transform/opacity transitions; all of it is off under reduced motion.

## 2. Audit

### 2.1 Method

All captures come from the mocked presentation harness, with intercepted API responses, a fixed clock, `reducedMotion: 'reduce'` and service workers blocked. No database was involved.

Screens captured: sign-in, sign-in error, register, password reset, MFA sign-in step; rooms list, room conversation, empty room, composing, members panel, leave dialog/sheet, name menu (profile card), message menu, emoji picker; Direct list and conversation; Activity (filled, empty); Search (empty, results); You (list, Profile, Alerts, Appearance, Sign-in and security, Privacy and data, Account); Administration (Users, Rooms), Operational settings, System status, Moderation queue, DM inspection, Revision review; rules and privacy (unpublished state); not-found; toasts.

Not captured, and why: see section 9.

### 2.2 What already reads as premium

- The token discipline: one colour file, a literal scan, a contrast test over every scheme.
- The identity system (hues by stable hash, squircle rooms, round people) and the bubble + chit signature.
- Honest, plain micro-copy ("There's nothing here", "Your rooms and messages are where you left them").
- The phone bottom bar and the pane stack; the rail's active pill.
- Accessibility depth (forced colours, 180 px reflow, toast region contract).

### 2.3 What does not, screen by screen

**System-wide**

| Finding | Evidence |
|---|---|
| ~40 distinct `font-size` values, down to 0.62rem (about 10 px) | `grep font-size public/assets/css` |
| 30 distinct `border-radius` values; only four radius tokens exist and few rules use them | same |
| Shadows are one heavy blur (`0 12px 36px`) used for cards, popovers and dialogs alike | `tokens.css --shadow` |
| Motion: 12 transition/animation declarations in 225 KB of CSS; dialogs, menus and toasts cut in | grep |
| Headings switch face: Bricolage for "System status", system bold for "Moderation queue", "Maintenance", "Complete sign-in" | captures |
| Primary button has a darker lower edge (a "3D" lip) and is a pill on auth but a rounded rectangle on Search and not-found | `sign-in`, `search`, `not-found` |
| Selected rows stack three cues: persimmon wash + 3 px rust bar + rust outline | `rooms-list`, `you`, `direct` |
| Focus on fields draws border + offset outline (a double ring) | `mfa-sign-in`, `emoji-picker` |
| One person, four avatars: solid hue pebble (transcript), pale tinted circle (members panel), pale pink circle (profile card), honey-outlined circle (You) | `members-panel`, `name-menu`, `you` |
| Uppercase tracked section labels everywhere ("YOUR ROOMS", "ONLINE HERE — 3", "EVIDENCE AT FIRST REPORT") give an admin-console tone | captures |
| "Signed in as Jamie" repeats under the title of Direct, Activity, Search and every stewardship page | captures |

**Sign-in family.** The lockup and the shape illustration float apart with no relationship; the card's tab switcher is a pill inside a pill with an outline; fields are paper-tinted inside a white card. Register adds hint lines at a third size. The MFA step keeps the guest offer *above* "Complete sign-in", sets its heading in the system face, and shows two persimmon buttons ("Use code", "Use passkey"), which breaks Lantern's own "one persimmon button" rule (P-13). Password reset is the cleanest of the family.

**Rooms list.** Every lantern shows "#", so rooms differ by colour alone at a glance. Names, times and badges are all bold. The mention notice is a bordered honey box with an underlined bold "Open". "Live" sits under the title at the same weight as content. At 1280 the installation name under the rail icon is 11 px and clamps at two lines.

**Conversation.** The header is surface, the transcript is ground, so the "card" is two-tone and bubbles (raised on ground, 1.10:1) look faint and putty-coloured in light. Every bubble carries its own time. The composer is 80 px tall when empty and has a permanent 150-character instruction line under it. On phones the header and composer are fine but the same faint bubbles apply.

**Direct.** The list opens with a labelled form ("Start a conversation", "Username prefix", Search) above the conversations. The conversation header's two most prominent controls are "Report" and "Block user". The non-E2EE disclosure strip is right and must stay.

**Activity.** A 48 px title on ground, then a card, then a card per notice, then two outlined buttons per notice. It reads as a form, not a feed. On phones each notice is about 150 px tall.

**Search.** Three stacked cards before any result (header card with eyebrow and 44 px title, notice card, form card). Result titles are bold, underlined, rust links.

**You.** The structure (list + section) is right. Sections are large-radius cards holding label-over-field forms; `<details>` "Learn more" in rust with a triangle; the theme control is a small native select beside a row of loose radio swatches. On phones the section list has no icons or affordance.

**Stewardship.** Administration is reached through five identical outlined buttons, then a tab bar in a bordered box, then an `h2`; roles are raw strings (`global_moderator`, `standard user`), people are "Rowan · #2". Operational settings is one column of twelve cards (7,386 px tall at 360). System status is six equal key/value cards with wrapping labels and no hierarchy. Moderation queue is the best of the group but has its own header card and an uppercase context line. DM inspection and Revision review are forms on ground with a third header variant.

**Dialogs, sheets, menus, toasts.** Dialogs are sound (the leave dialog follows CCM's "no persimmon in dialogs"). The members sheet has no handle. Popovers, dialogs and cards share one shadow. Toasts are plain boxes stacked at the top of the page, over the title on phones; the error toast differs only by a rose outline (no glyph).

**Status pages.** Not-found is good; it only needs the shared button and type scale. Rules/privacy could only be seen in their unpublished state (section 9).

## 3. The Lantern evolution

Naming: CSS custom properties below are the proposed web names. CCM's names are in `visual-vocabulary.md`; `lantern-next.md` (phase 3) will carry the mapping.

### 3.1 Colour tokens

Only rows marked **changed** differ from today. Contrast figures are WCAG 2.x ratios, computed the way `tests/js/design-contrast.test.js` does.

**Lantern light**

| Token | Current | Proposed | Notes |
|---|---|---|---|
| `--ground` | `#FBF7F1` | **`#F5F0E8`** changed | Deeper paper so white surfaces lift without outlines (surface:ground 1.07 → 1.13). On it: text 16.4, muted 6.13, signature-text 4.99, presence-text/warning 5.05, steward 5.78, error 4.76, field-line 3.21. |
| `--surface` | `#FFFFFF` | `#FFFFFF` | New use: the stage (transcript, page content). |
| `--raised` | `#F3ECE2` | **`#F1EADF`** changed | Incoming bubbles and quiet buttons now sit on surface (1.19:1 against white). On it: text 15.6, muted 5.82, signature-text 4.73, error 4.52, steward 5.49, warning 4.80, field-line 3.05. |
| `--line` | `#E6DCCF` | same | |
| `--field-line` | `#8C8299` | same | 3.64 on white. |
| `--text`, `--text-muted` | `#14111C`, `#5E566B` | same | |
| `--signature`, `--on-signature`, `--signature-text`, `--signature-hover` | `#F06A3B`, `#14111C`, `#B5401A`, `#F5916D` | same | |
| `--outgoing`, `--on-outgoing` | `#FFD9C4`, `#14111C` | same | |
| `--presence`, `--presence-text`, `--unsure-line`, `--warning`, `--presence-wash` | unchanged | same | |
| `--steward`, `--error` | `#3D52C4`, `#C4314B` | same | |

**Lantern dark**

| Token | Current | Proposed | Notes |
|---|---|---|---|
| `--ground`, `--surface`, `--raised`, `--line`, `--field-line`, `--text`, `--text-muted` | unchanged | same | Surface becomes the stage; raised bubbles on it are 1.13:1. |
| `--outgoing` | `#8A3A1E` | **`#742F17`** changed | Deeper ember: less glare, `--on-outgoing` 6.74 → 8.43, honey unsure-line on it 5.53. Muted text on it is only 3.82, so nothing muted may sit on an outgoing bubble (times move out, 4.4). |
| everything else | unchanged | same | |

**New derived tokens (no new hues)**

| Token | Light | Dark | Use |
|---|---|---|---|
| `--signature-wash` | `color-mix(signature 18%, surface)` ≈ `#FCE4DC` | ≈ `#42272A` | Rail active pill (exists today as an inline mix; becomes a token). Text 15.3 / 11.7 on it. |
| `--selected` | `var(--surface)` | `var(--raised)` | The selected list row ("lit row"). |
| `--field` | `var(--surface)` | `var(--ground)` | Field fill, one rule everywhere. |
| `--toast`, `--on-toast` | `var(--text)`, `var(--ground)` (16.4) | `var(--raised)`, `var(--text)` (13.4) | Toast surface. |
| `--scrim` | `rgb(20 17 28 / 0.42)` | `rgb(0 0 0 / 0.55)` | One scrim; `--scrim-strong` and the backdrop blur are retired. |
| `--elev-1/2/3` | see 3.5 | see 3.5 | Replace `--shadow`, `--shadow-soft`, `--shadow-strong`. |

**Other schemes.** Lounge, Dusk, Ember, Rosé, Midnight and High contrast keep their hues (L0 found one value to correct: Dusk light `--error`, see `lantern-next.md`). Each gets the same structural review in the foundation slice: the stage is `--surface`, bubbles are `--raised`, and `tests/js/design-contrast.test.js` gains the new pairs (raised-on-surface text pairs, on-toast, selected). Where a scheme's light `--ground`/`--surface` separation is under about 1.08 I will propose a ground adjustment per scheme in that PR, with numbers. High contrast and Midnight use edges, not shadows (as today).

**Semantics unchanged.** Honey = people/attention, always with text. Warning = `--warning` with the triangle glyph. Rose = errors and destructive confirmation only, never colour alone. Dusk = stewardship and links. Persimmon is never a warning. No green.

### 3.2 Type

Faces unchanged: Bricolage Grotesque 700/800 (the two existing self-hosted woff2 files, 47 KB together; no new files) and the system stack. The 800 weight is kept for Display L only, so signed-in pages no longer need the 800 file at all (it loads lazily, only where used).

| Role | Token | Size / line | Face, weight, tracking | Use | Replaces |
|---|---|---|---|---|---|
| Display L | `--type-display-l` | `clamp(2rem, 1.2rem + 3vw, 3.25rem)` / 1.05 | Bricolage 800, −0.02em | Installation name on signed-out pages | five different clamps |
| Display M | `--type-display-m` | 1.625rem / 1.15 | Bricolage 700, −0.015em | Page and list titles, dialog titles, empty-state headlines | 1.35–3rem headings |
| Title | `--type-title` | 1.1875rem / 1.25 | Bricolage 700, −0.015em | Stage headers (room, person, section) | mixed |
| Message | `--type-message` | 1rem / 1.45 | system 400 | Message bodies (never the display face, A-11) | same |
| Body | `--type-body` | 0.9375rem / 1.45 | system 400; names 600 | Lists, settings, buttons | 0.85–1rem |
| Label | `--type-label` | 0.8125rem / 1.3 | system 600, sentence case | Section labels, field labels, small buttons | uppercase 0.72–0.8rem |
| Caption | `--type-caption` | 0.75rem / 1.3 | system 400, tabular numerals | Times, hints, badges (700) | 0.62–0.78rem |

Rules: nothing below 0.75rem. All sizes in rem so browser font-size settings and zoom keep working. Bricolage is for display roles only (page/stage/dialog titles, the installation name, tile figures); card and group headings are system 600–700. Section labels lose uppercase and tracking (a brand-level change from CCM's "uppercase, +0.02em"). Times use `font-variant-numeric: tabular-nums`.

### 3.3 Shape

| Token | Value | Use |
|---|---|---|
| `--radius-1` | 8px | Chips, menu items, code |
| `--radius-2` | 12px | Icon buttons, small tiles |
| `--radius-field` | 16px (as today) | Fields, list rows, cards, popovers |
| `--radius-bubble` | 20px (as today) | Bubbles |
| `--radius-sheet` | 28px (as today) | Sheets, dialogs, the stage |
| `--radius-pill` | 999px (as today) | Buttons, badges, the composer, segmented controls |

Identity shapes unchanged: rooms 33% squircles, people circles, guests dashed circles. Buttons become pills everywhere (CCM's rule; today Search/not-found/admin use rounded rectangles). Target sizes: `--target-min` stays 44px / 48px coarse.

Spacing: `--space-1..8` = 4, 8, 12, 16, 20, 24, 32, 48 px.

### 3.4 Components (one of each)

- **Button family:** primary (persimmon fill, ink label, flat: the lower lip goes), quiet (`--raised` fill), outline (`--field-line`), destructive (rose outline and label, with wording), steward (Dusk fill on stewardship pages). Heights 44 (default), 52 (auth), 32 visual inside a 44 target (small).
- **Focus:** one ring. `outline: 2px solid var(--signature-text); outline-offset: 2px` on buttons, rows and links; on fields the outline sits on the border (`outline-offset: -1px`) so there is one line, not two. Stewardship pages keep their existing focus colour rule. Outlines (not box-shadows), so forced colours keep them.
- **Selected row:** `--selected` fill + `--elev-1` + a 3 × 22 px persimmon (Dusk on stewardship) bar + weight 700 + `aria-current`. Forced colours: the existing #378/#380 outline rules stay.
- **Avatar:** one renderer for people (solid identity hue, ink initials) and one for rooms, at 24/32/40/48 px. Members panel, profile card and You adopt it.
- **Room lantern content:** the room's first grapheme (via `Intl.Segmenter`, uppercase where the script has case; "#" as the fallback) instead of "#" for every room. Decorative: the name beside it is the text.
- **Badges:** 20 px, caption 700, tabular; mention = honey with "@", count = persimmon with the number. Unchanged semantics.
- **Segmented control:** `--raised` track, `--surface` thumb with `--elev-1`, weight 700 on the selected item (auth tabs, theme, Search scope, moderation filters).
- **Fields:** 52 px on auth, 44–48 px elsewhere, `--field` fill, `--field-line` 1px.
- **Notices:** one strip pattern (icon + text + optional action) for the status ladder, mention notice, disclosures. Warning strips keep the triangle.
- **Toasts:** pill, `--toast`, leading glyph (check / info / warning triangle / error), bottom centre above the bar. Region semantics unchanged: polite region, `role="alert"` only for errors (#369).

### 3.5 Elevation

| Level | Light | Dark | Used by |
|---|---|---|---|
| `--elev-1` resting | `0 1px 2px rgb(60 40 20 / .07), 0 0 0 1px rgb(60 40 20 / .05)` | `0 0 0 1px #2E2838` | Stage, selected row, segmented thumb |
| `--elev-2` floating | `0 2px 6px rgb(60 40 20 / .07), 0 14px 34px -8px rgb(60 40 20 / .2)` | `0 0 0 1px var(--line), 0 14px 34px rgb(0 0 0 / .5)` | Menus, pickers, toasts, auth card |
| `--elev-3` modal | `0 8px 20px rgb(20 17 28 / .1), 0 32px 72px -16px rgb(20 17 28 / .34)` | `0 0 0 1px var(--line), 0 32px 72px rgb(0 0 0 / .65)` | Dialogs, sheets |

High contrast and Midnight: `none` plus a 1px `--line`/`--field-line` edge. Forced colours: borders (existing guards). In dark, floating surfaces use `--raised`, not `--surface`, so they separate by tone.

### 3.6 Iconography

The CCM set is unchanged (24 grid, 1.8 stroke, round caps/joins, `currentColor`). Sizes: 22 in navigation, 20 in headers and rows, 16–18 inline. Three glyphs are needed and would be drawn in the same style: `chevron`, `plus`, and a horizontal `more`. They land in `public/assets/icons/` with provenance "CC, pending CCM adoption" and are listed in `lantern-next.md` (section 8). Emoji stay user content only.

### 3.7 The mark and its variants (#227)

Geometry unchanged (CCM `app-mark.md`). Proposed named variants, shown on the sheet for the owner's approval under #227's own process:

1. **Primary:** persimmon on a Night ink tile (app icons, sign-in, rail).
2. **Paper:** persimmon on a paper tile, ink eyes (light print and docs).
3. **One colour / reversed:** `currentColor` silhouette with cut-out eyes (forced colours, the "on ChitChat" line, watermarks). Mirrors CCM's monochrome launcher layer.
4. **Small sizes:** 16–32 px use CCM's small-size eye and chit radii.
5. **Lockup with name:** mark + "ChitChat" in Bricolage 800.
6. **Renamed installation:** the installation's own icon and name lead; a one-colour mark + "on ChitChat" signs below. Extends today's `Lockup`.
7. **Glow:** the honey radial glow behind the lockup, signed-out pages only (CCM: Welcome only).

In dark themes the Night ink tile gets a 1px `--line` edge where it sits on Night ink ground (rail, phone eyebrow), because today the tile disappears into the page. Seasonal sign-in decoration (#242) is untouched and stays outside the mark.

### 3.8 Motion

Tokens: `--motion-fast: 120ms`, `--motion-base: 200ms`, `--motion-slow: 280ms`; `--ease-out: cubic-bezier(0.2, 0, 0, 1)` for entering, `--ease-in: cubic-bezier(0.4, 0, 1, 1)` for leaving. Only `transform` and `opacity` animate (plus colour on hover). Nothing loops.

| Change | Motion | Reduced motion |
|---|---|---|
| Hover, press, toggle | colour 120ms; press `scale(.98)` | colour only, no scale |
| Menu, picker, name card | fade + 4px rise, 120ms | instant |
| Dialog | fade + `scale(.97→1)`, 200ms; scrim fade | instant |
| Sheet (phones) | `translateY(100%→0)`, 280ms (today 140ms) | instant |
| Pane slide (phones) | existing 200ms transform, retimed to tokens | instant (as today) |
| Toast | fade + 8px rise in 200ms, fade out 120ms | instant |
| Message arriving live | fade + 6px rise, 160ms, only for messages appended live at the bottom, never on history load | instant |
| Segmented thumb | 200ms slide | instant |
| Page to page (rail/bar) | cross-document view transition (`@view-transition { navigation: auto }`), 150ms cross-fade of the page; the rail has its own `view-transition-name` so it holds still. CSS only, no script; unsupported browsers navigate as today | not enabled (inside `@media (prefers-reduced-motion: no-preference)`) |

The three duration tokens drop to 0.01ms under `prefers-reduced-motion: reduce` in `tokens.css`, which every page loads (L0, after review: the global rule that zeroes every animation and transition lives in `accessibility.css`, and the stewardship, step-up and status pages do not load that file). That global rule stays as a second backstop where it is loaded; new motion is additionally written inside `no-preference` blocks. Typing indicators stay static. The liveliness glow stays static.

## 4. Layout and hierarchy per screen

### 4.1 The frame (all signed-in pages)

- **Wide (≥ 48rem):** rail 72 px (installation icon, four destinations, Stewarding at the bottom) · optional list pane (about 336 px, on ground, no border) · the stage (`--surface`, `--radius-sheet`, `--elev-1`, 10 px inset). Pages without a list (Activity, Search) use the stage full width with a centred 680 px column.
- **Phones:** unchanged structure (list pane, then the conversation over it; bottom bar). The transcript and page content sit on `--surface`.
- **Page header:** one pattern. List panes: an eyebrow row (installation name, ellipsised, with the connection state at its right) + a Display M title + icon actions. Stages: Title + one muted subline + actions. "Signed in as …" appears once, on You.
- **Installation name:** moves from under the rail icon (11 px, two-line clamp) to the list-pane eyebrow (as phones already do), where long names have room; the full name stays in `title` and the document title. The rail keeps the icon.
- The 180/320 px reflow rules are kept as they are; the eyebrow row wraps (state under name) at narrow widths rather than truncating to nothing.

### 4.2 Sign-in, register, recovery, MFA, passkeys

- Wide: lockup in a static honey glow as the hero, the shape illustration tightened into one small "conversation" below it; card at `--elev-2`.
- Phones: horizontal lockup, then the form directly on ground (no card).
- Card: Display M greeting, segmented Sign in / Register, 52 px fields with label-row hints, "Forgot password?" in the Password label row, one 52 px persimmon button, an "or" rule, the quiet guest button and its caption, "Restore a closing account" as a caption link.
- MFA step: replaces the form inside the same card (the guest offer and tabs are hidden while it shows), Display M heading, one persimmon button for the offered default method (authenticator code when available, otherwise passkey), the other methods as quiet buttons. Order and wording of approved strings unchanged.
- Recovery-key display, rules acceptance, choose-username, restore-account and native-auth pages adopt the same card and type roles.
- Any name length: Display L clamps and balances (`text-wrap: balance`), wraps to as many lines as needed, the form never moves off-screen (existing long-name test in `presentation-auth` stays).

### 4.3 Rooms list

Eyebrow + "Rooms" + search action. Mention notice as a compact honey strip with a quiet "Open" pill. Sentence-case labels. Rows 56 px: lantern with initial (ring when presence is known), name 600 (700 when unread or selected), one muted subline ("3 here now · info line"), time in caption, 20 px badges. "Join" as a small outline pill. Selected = the lit row.

### 4.4 Conversation and composer

- Stage header: 36 px lantern, Title, subline ("3 here now · info line"), members (icon + count) and search as 40 px ghost icon buttons.
- Transcript on `--surface`; bubbles `--raised` / `--outgoing`, 9 × 14 px padding, 3 px between bubbles of a run, 16 px between runs, max width about 560 px.
- Times: once per run. Incoming: beside the sender name. Outgoing: a caption under the run. Each message keeps its `<time>` in the DOM and in its accessible name; it becomes visible on hover and on keyboard focus of that message. (Approved, section 8; fallback in section 7.)
- Avatar stays at the foot of the run beside the chit (the Lantern signature).
- Composer: one pill row, 52 px when empty (grows as today), attach and emoji inside, 40 px persimmon send. Placeholder names the room. The hint line shrinks to "Enter to send · Shift + Enter for a new line · / for commands" on wide screens only; attachment-caption and `/help` detail move into the `/help` output that already exists.
- Members panel: same header pattern, shared avatar, presence text in words ("active", "you"), sheet with a handle on phones. Menus (message, name card, emoji picker): `--elev-2`, `--radius-field`, 44 px items.
- Empty room: the existing dashed-bubble drawing, Display M headline, one muted line.

### 4.5 Direct

Same frame. List: "Direct" + a compose action; one pill search field ("Find someone by name", submit on Enter with a visually-hidden submit button; behaviour unchanged). Conversation header: pebble, Title, subline, search and a "more" menu holding "Report …" and "Block …" (both end in an ellipsis and open their existing dialogs). The non-E2EE disclosure keeps its exact text as a slim strip under the header, always in view.

### 4.6 Activity

Stage, centred column. Display M title, unread count as a subline, "Mark all as read" as a small quiet button. Day labels. Each notice is one row and one link: kind glyph (honey "@" for mentions, neutral for pings, the sender's pebble for DMs), sentence, "kind · time" subline, unread dot + weight, a 44 px "Mark as read" tick button, chevron. "About these notices" stays a disclosure at the end. Empty state: drawing + headline + next step (as S7e).

### 4.7 Search

Stage, centred column: one row with the query field, the scope segmented control and the Search button; the "only messages you can see" line as a caption with its Learn more; results as rows (avatar, source as Label, excerpt with the existing highlight, time), each a link.

### 4.8 You

List pane: identity block (48 px pebble, name, member since), sections with icons, Sign out at the foot (quiet). On phones the same list with chevrons. Sections render in the stage as settings rows: label and one-line help on the left, control on the right, hairlines between, group labels in Label type. Forms that need several fields (password, passkeys, TOTP) stay stacked but lose their outer card. Appearance: theme as a segmented control, schemes as a swatch grid with a check on the selected one (weight + border + check, not colour alone). Sessions and passkeys as rows with a trailing action. Destructive account actions grouped last in rose outline.

### 4.9 Stewardship

- One list pane titled "Stewarding" in Dusk replaces the five link-buttons and the tab box: People, Rooms, Moderation queue (count), Operational settings, System status, Audit log, DM inspection, Revision review, grouped under "People and rooms", "This server", "Sensitive, always audited". These are links between the existing pages plus Administration's existing tab states; no endpoint changes. Administration's three tabs aren't addressable by URL today, so they stay an in-page control (section 8).
- Administration: people and rooms as rows (shared avatar, role as a readable chip: "Member", "Global moderator", "Banned" in rose with wording), Manage as a small outline pill; the ID moves to the detail view.
- Operational settings: the stage gets an in-page section index (sticky on wide, a "Jump to" select-like disclosure on phones), cards become groups of settings rows, one save bar per group as today.
- System status: four tiles for the numbers operators look for (people connected, database size and latency, disk used with a neutral meter, last cleanup), then the existing groups as two columns of definition lists, then the rate-limit table. Every figure is one the API already returns; no derived "all healthy" verdict is invented. Status wording keeps an icon + text (no green).
- Moderation queue, DM inspection, Revision review: same frame; the "what this opens" notices keep their exact wording as strips; evidence and reports as rows.

### 4.10 Dialogs, sheets, toasts, notices, status pages

Dialogs: `--radius-sheet`, `--elev-3`, Display M (22 px) title, body, actions right-aligned with the safe choice first; destructive in rose outline; no persimmon fill in dialogs. Below 768 px every modal is a sheet (as S7f) with a handle and full-width 52 px buttons. Toasts as 3.4. The status ladder keeps one notice at a time. Not-found, error, rules and privacy pages take the shared card, type roles and button.

## 5. How each non-negotiable is kept

1. **Accessibility.** No assertion is weakened; where a design and a guard disagree, the design changes. Contrast: every new pair is added to `design-contrast.test.js` before it is used; the three changed values are computed above. Keyboard: no interaction becomes pointer-only (hover-revealed times also show on focus; the Direct "more" menu is a button with a menu, the same pattern as the message menu). Names and roles: markup changes keep accessible names (Label in Name for the nav and badges stays), the toast region contract (#369) is untouched, live regions stay where they are. Forced colours: the #378/#380 rules stay; new selected/focus styles use outlines and borders. Reduced motion: section 3.8. Reflow: every slice runs `presentation-narrow` at 180 and 320. Targets: 44 px minimum, 48 px coarse; small visual buttons keep full-size hit areas. Minimum text size rises to 12 px.
2. **Privacy and CSP.** No third-party requests, no inline scripts or styles added, no new font files, icons stay self-hosted SVG. View transitions are a CSS at-rule. The owner sheet embeds everything and makes zero requests (verified in Chromium).
3. **No server or API changes.** Everything above is CSS, presentation markup in `public/*.php` and `src/View/*`, and presentation JS. Open factual points are in section 8.
4. **Semantic colours.** Values and meanings unchanged; warning keeps its triangle; rose stays destructive-only and never alone; Dusk stays stewardship; persimmon never warns.
5. **Performance.** No new assets. CSS should shrink (shared tokens replace one-off values; target: no growth past today's 225 KB across files, and no stylesheet added to any page). Animations are transform/opacity only; the backdrop blur is removed; nothing loops.
6. **Any name, any icon.** The installation name gets more room than today; lockups wrap and balance; the icon is always shown as given in a 22–26% rounded frame with a hairline edge in dark, never recoloured or masked into the mark.

## 6. Slice plan for phase 2

One CSS-heavy PR open at a time. Every PR: before/after captures (1280 and 360, light and dark, plus each changed scheme), reasoning, baseline changes explained, Lantern changes listed; axe on every touched state; `presentation-narrow`; `node --test tests/js` (incl. `css-structure`, `css-literals`, `design-contrast`); grep of the `zz*` journeys for touched selectors and strings; baselines regenerated from the CI artefacts of the exact head; visual lead review; technical lead merges.

| # | Slice | Scope | Baselines likely to move | Guards to watch |
|---|---|---|---|---|
| L0 | **Foundations** | New tokens in `tokens.css` (type, radius, space, elevation, motion, derived colours); the three colour values; contrast-test pairs; `docs/design/tokens.md` update; start `docs/design/lantern-next.md`. No component restyle. | All four baseline sets shift slightly (ground, raised): `zzzzzzzzz-visual` (account ×2, auth), `admin-settings-desktop`, `admin-rooms-desktop`, `moderation-desktop` | `design-contrast`, `css-literals`, forced-colours specs |
| L1 | **Controls** | Buttons, fields, focus ring, segmented control, badges, chips, one avatar renderer, room initials | All | `presentation-button-states`, `presentation-keyboard`, `presentation-room-list`, identity-hue tests, `zzzz…-avatars`, `zzzz…-people` |
| L2 | **Frame** | Rail, bar, list-pane header (eyebrow, name move), stage, shared page header, view transitions | All signed-in baselines | `presentation-shell`, `presentation-panes`, `presentation-destinations`, `presentation-narrow`, `zzzz…-application-name` |
| L3 | **Lists** | Rooms list, Direct list, mention notice, selected row, connection-state placement | — (covered by specs, not baselines) | `presentation-room-list`, `presentation-direct-list`, `presentation-connection-status`, `zzzz…-live-room-list`, `zzzz…-room-unread` |
| L4 | **Conversation** | Bubbles on stage, run times, header, composer, hint, members panel, menus, emoji picker, empty room | — | `presentation-transcript`, `-transcript-tree`, `-bubbles`, `-message-editing`, `-suggestions`, `-leave-room`, reactions/replies/typing/slash journeys |
| L5 | **Sign-in family** | Sign-in, register, reset, MFA, passkeys, recovery key, rules acceptance, choose-username, restore, native-auth, guest | `auth-desktop` | `presentation-auth`, `-recovery`, `-totp`, `-rules`, `-native-auth`, `mfa-passkeys`, registration-protection and sign-in-provider journeys |
| L6 | **Direct, Activity, Search** | Direct header/menu/strip, Activity rows, Search layout | — | `presentation-direct-list`, `-report-person`, `-activity`, `-search`, DM and search journeys |
| L7 | **You** | Settings rows, Appearance, sessions, passkeys, alerts, privacy, account | `account-desktop`, `account-narrow` | `presentation-you`, `-sessions`, `-totp`, `-username-change`, `-accessibility-feedback`, theme/preferences journeys |
| L8 | **Stewardship** (may split 8a list + Administration, 8b settings, 8c status, 8d moderation/inspection/review) | Shared list pane, each page | `admin-rooms-desktop`, `admin-settings-desktop`, `moderation-desktop` | `presentation-administration`, `-admin-settings`, `-admin-icon`, `-admin-decoration`, `-inspection`, `-report-person`, `-ownership-transfer`, system-status and step-up journeys |
| L9 | **Overlays and status** | Dialogs, sheets, toasts, notices, not-found/error/rules/privacy, remaining motion | — | `presentation-sheets`, `-alerts`, `-announcer-contract`, `-status-pages`, `-unavailable`, `-files-unavailable`, `no-native-dialogs` |
| L10 | **Handoff** | `docs/design/lantern-next.md` complete (every brand-level change, old → new, reason, CCM mapping); final capture set; other-scheme sweep | — | full suite |

Order rationale: tokens first so later slices only consume them; controls before layout so every screen slice is mostly arrangement; the frame before screens so each screen is restyled once.

## 7. Risks and fallbacks

| Risk | Mitigation / fallback |
|---|---|
| Per-run times hide information on touch devices | Fallback: keep a time on every message, restyled as a caption outside the bubble's text flow. The owner approved times per run (section 8). |
| Room initials for non-Latin names, emoji-only names, RTL | First grapheme via `Intl.Segmenter`; "#" fallback when unavailable or empty; decorative, so no accessibility dependence. Tests asserting "#" need updating with reasons. |
| Moving Report/Block into a menu lowers discoverability of reporting | One click away, named "More actions for {name}"; the visual lead may require Report to stay visible; then only Block moves. |
| The deeper light ground touches every baseline and every scheme | Lands alone in L0 with contrast numbers; schemes adjusted in the same PR only where tested pairs require. |
| View transitions differ per browser, could affect WebKit runs | Feature is additive and inside `no-preference`; Playwright baselines run with animations disabled; main's WebKit run is checked after merge; can be dropped without design loss. |
| Stewardship list needs deep links to Administration tabs | If the tabs are not addressable today, the list links to `/admin.php` and the tabs stay as a segmented control inside the stage (no JS routing change beyond presentation). Confirm with engineer. |
| Many specs assert copy and structure | Each slice greps specs and `zz*` journeys first; copy changes are listed in the PR; approved/verbatim strings are not edited. |
| CSS churn across 33 files, merge conflicts with parallel feature work | One CSS-heavy PR at a time; update by merging `origin/main`; `css-structure` test on every push. |
| Brand drift from CCM while web leads | Every brand-level change recorded in `lantern-next.md` in the slice that makes it (not at the end). |

Brand-level changes so far (for `lantern-next.md`): light `ground` and `raised` values; dark `outgoing` value; transcript/content on `surface` ("the stage"); section labels in sentence case; 800 weight restricted to Display L; type scale and 12 px floor; radius/elevation/motion token sets; one scrim, no backdrop blur; flat primary button; selected-row style; room initials in lanterns; times per run; toast style; three added glyphs; mark variant family; dark-ground tile edge.

## 8. Decisions

**Owner (2026-11-15):**
1. **Direction:** evolution, as proposed. On top of it, be **bolder in a few signature moments**: the sign-in hero, the empty states (new room, no activity yet and so on), motion (messages arriving, sheets and dialogs opening) and the mark family. The system stays strict everywhere else.
2. **Room initials instead of "#":** yes.
3. **Times beside the name instead of on every bubble:** yes. The exact time stays available on hover, on keyboard focus and to screen readers.
4. **Report and Block in a "more" menu in Direct:** yes.
5. **The mark family as the answer to #227:** approved. #227 gets a comment with the approved set and closes with the PR that ships it.
6. **Installation name at the top of each list pane, and the rail keeps the icon only:** yes. It has to hold up with very long names and any installation icon.

**Technical lead:**
- Administration's tabs (Users, Rooms, Audit) aren't addressable by URL or hash today (buttons with `data-panel`; the page reads only `?user=`). They stay an in-page control; hash links may be proposed in L8 as a small client-only change.
- The three new glyphs (chevron, plus, horizontal "more") go into `public/assets/icons/` and are recorded in `lantern-next.md` for CCM.

**CC visual presentation lead:** the review criteria every slice is built to are recorded on #381. They sharpen this document in these places:
- every message keeps its exact time as `<time datetime>` in its accessible text, follow-on messages included; "on keyboard focus" means when the arrow keys reach the message (the conversation is one Tab stop);
- room initials are `aria-hidden`, dark ink on the identity colour, and row names stay exactly as they are;
- fields keep a boundary of at least 3:1, and quiet controls keep their forced-colours edges;
- every animation and transition, view transitions included, is 0.001 s or less under reduced motion;
- Activity rows nest no controls (a stretched link plus a separate, named button);
- the Direct "more" menu has full menu keyboard semantics and doesn't compete with the phone header's existing "More actions" (#257);
- the composer's full keyboard help stays reachable when the visible hint is shortened;
- the `.app-nav-count` zero-size text in `shell.css` is deliberate and is exempt from the 12 px floor.

## 9. Not captured, and why

- **Published rules and privacy pages:** server-rendered from the database; the mocked harness has no database, so only the "unpublished" 404 state renders. The page chrome is the same.
- **The 500 error page:** only reachable by a server failure; its markup is unit-tested and shares the not-found styles.
- **Guest view, lockdown strip, connection-ladder states (reconnecting, offline), stream recovery:** not captured in this pass; existing specs cover them and they will be captured in the slices that touch them.
- **Passkey ceremonies and TOTP enrolment dialogs, recovery-key display, rules acceptance, choose-username, restore-account, native-auth, step-up dialog:** not captured in this pass (need multi-step mocked flows); they share the auth card and dialog components and get their own before/after captures in L5/L9.
- **Message states:** edited, deleted, replies, mentions, attachments, unavailable files, inline edit, report dialog, ownership transfer, ban/mute dialogs: not captured in this pass; covered in L4/L6/L8.
- **Opt-in schemes** (Lounge, Dusk, Ember, Rosé, Midnight, High contrast), forced colours, 180/320 px and 200% zoom: not captured in this pass (the brief asked for Lantern light/dark at 1280/360).
- **Other browsers:** Chromium only.
- **Run notes:** the first capture run was stopped by me after 9 screens because a wrong selector made one state wait out a long timeout; its finished captures were kept and the rest re-run. The leave dialog, message menu and toast states failed once on wrong selectors and were re-captured successfully. The toast capture injects three toasts into the real toast region on the Revision review page (real styles, synthetic messages). The name-menu capture shows the profile card, not a separate menu. All 152 files are from `106b47e`.
