# Lantern Next: every brand-level change, for ChitChat Mobile

Status: living record, updated in the slice that makes each change (tracking: #381). The reasoning behind the whole overhaul is in [lantern-next-direction.md](lantern-next-direction.md).

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
| Secondary button | `raised` fill with a `fieldLine` border | No fill, `fieldLine` border, label at 600 | One outline button on any ground. The boundary stays 3:1 against `ground` and `surface`; hover fills it with `raised` and darkens the border to `text`. |
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

## Still to come

L2 frame · L3 lists · L4 conversation · L5 sign-in family and the mark variants (#227) · L6 Direct, Activity, Search · L7 You · L8 stewardship · L9 overlays, status and motion · L10 handoff. Each adds its section here when it merges.
