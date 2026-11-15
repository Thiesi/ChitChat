# The Lantern mark and its family

Status: approved by the owner on 2026-11-15 as the answer to #227 (shown on the Lantern Next direction sheet), shipped with L5 (#389). The mark's geometry is ChitChat Mobile's (`docs/design/app-mark.md` there) and doesn't change. This page names the versions, says where each is used, and gives the rules. For CCM the same list is in [lantern-next.md](lantern-next.md).

## The mark

A persimmon rounded bubble with two ink eyes, and a small persimmon chit at its lower right (the speaker's corner). Master artwork: `public/assets/app-icons/lantern-mark.svg`, on the 108-unit canvas with its 33-unit safe zone.

## The family

| Version | What it is | File | Where it is used |
|---|---|---|---|
| **Primary** | Persimmon on a Night ink tile | `app-icons/lantern-mark.svg`, `favicon.svg`, the PNG app icons | The default installation icon: browser tab, home screen, the rail, sign-in |
| **Paper** | The same on a paper tile (`#F5F0E8`), ink eyes | `app-icons/lantern-mark-paper.svg` | Light documents and print. Not an installation icon preset: those are stored choices with their own fixed backgrounds |
| **One colour** | The bubble with its eyes cut out, and the chit, in a single colour | `app-icons/lantern-mark-mono.svg` | Wherever the mark signs in the text's colour: "on ChitChat" under a renamed installation's name, "Powered by ChitChat", forced colours. Matches CCM's monochrome launcher layer |
| **One colour, reversed** | The one-colour mark in a light colour on dark | the same file | The same places in dark themes: it is drawn as a mask in the current text colour, so there is one file |
| **Small sizes** | 16 to 32px | the primary artwork; `favicon-32.png` | Browser tabs, lists. Below 24px use CCM's small-size eye and chit radii (`app-mark.md`) if the eyes close up |
| **Lockup with the name** | The mark and "ChitChat" in Bricolage Grotesque 800 | `Lockup` (`src/View/Lockup.php`) | Signed-out pages of an installation that keeps the name |
| **Renamed installation** | The installation's own icon and name lead; "on ChitChat" follows with the one-colour mark | `Lockup` | Signed-out pages of a renamed installation |
| **Glow** | A still honey light behind the lockup | CSS, never baked into the artwork | Signed-out pages only |

## Rules

- **One geometry.** Every version is the same drawing. Nothing is redrawn, stretched, rotated or outlined. The mark doesn't mirror for right-to-left: it is a logo, not a layout.
- **Persimmon on Night ink or on paper; otherwise one colour.** The mark is never tinted with a scheme's colour, honey, Dusk or rose. The one-colour version takes the text colour around it and nothing else.
- **The eyes are ink or cut out.** Never white on persimmon.
- **Clear space:** at least the chit's diameter on every side.
- **The glow belongs to the page, not to the mark.** It is one static honey glow behind the lockup on signed-out pages; it never pulses and never appears in an icon file.
- **An installation's own icon is never touched.** Operators set their icon and name. Their icon is shown as given (in a rounded frame, with a fine edge where it would vanish into the page) and is never recoloured, masked into the mark's shape, or decorated. Their name leads; ChitChat signs beneath with the one-colour mark.
- **Seasonal decoration** (#242) sits around the lockup, behind it, and never on the mark.
- **Not for user content.** The mark is not a reaction, an avatar or a room icon.

## Not in the family

Seasonal or recoloured marks, a mark for a renamed installation (it has its own icon), and animated marks. If one is ever wanted it is a new decision, recorded here and in `lantern-next.md`.
