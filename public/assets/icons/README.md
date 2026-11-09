# Lantern icons

The canonical UI glyphs for CCM and the ChitChat web client. They were drawn for CCM: the same
paths appear in the canvas mockups (`docs/design/mockups/project/*.dc.html`). They are not a
Material Symbols copy, and they are covered by this repository's BSD 2-Clause license.

## Style

- 24×24 viewBox, `fill="none"`, `stroke="currentColor"`, stroke width 1.8, round caps and
  joins (visual-vocabulary.md, Iconography). Color always comes from the context (`currentColor`),
  never from the file.
- Drawn on a 24dp/px box inside a 48dp/px target (A-03; on the web, see the web's own target
  rule).
- Each glyph keeps its meaning. Don't reuse one glyph for a different action.

## Set

| File | Meaning | Where |
|---|---|---|
| `nav-rooms` | Rooms tab: the mark simplified (bubble plus chit) | navigation-shell.md |
| `nav-direct` | Direct tab: a person | navigation-shell.md |
| `nav-activity` | Activity tab: a bell | navigation-shell.md |
| `nav-you` | You tab: a person in a circle | navigation-shell.md |
| `back` | Back (auto-mirrored in RTL) | navigation-shell.md P-76 |
| `close` | Close, cancel | modals, viewer |
| `search` | Search | lists, transcript |
| `more` | More actions (vertical dots) | headers, rows |
| `send` | Send (arrow) | composer |
| `send-waiting` | Waiting to send (Send when back online) | composer, communication.md |
| `attach` | Attach a file | files-media.md |
| `emoji` | React | message actions |
| `reply` | Reply | message actions |
| `copy` | Copy | message actions |
| `edit` | Edit | message actions |
| `delete` | Delete | message actions |
| `report` | Report (flag) | reporting.md |
| `ping` | Private ping (paper plane), used as a badge | communication.md, lists-restyle.md |
| `share` | Share to another app | files-media.md |
| `save` | Save to a chosen place | files-media.md |
| `image` | Image or photo | files-media.md |
| `check` | Confirmed, selected | status |
| `info` | Information | status ladder, notice |
| `warning` | Needs attention | status ladder, notice |
| `help` | Help | onboarding |
| `block` | Block, not allowed | DM, moderation |
| `lock` | Private, locked | rooms, security |
| `offline` | No connection | status strip |
| `mute` | Muted | moderation |
| `guest` | Guest (dashed person) | rooms, people |
| `steward` | Stewardship (shield), drawn in Dusk | moderation, admin |
| `show-password` | Show password | onboarding |
| `sign-out` | Sign out | You |
| `appearance` | Theme and appearance | You |
| `qr` | Invite code | invite-codes.md |
| `document` | A policy document (rules, privacy policy) | play-readiness.md §1, Activity |
| `open-external` | Opens outside the app (browser) | play-readiness.md §1 |

## Notes

- The current app still ships an older paper-plane `ic_message_send` drawable on Send. The
  Lantern Send glyph is the arrow above, and the paper plane means a private ping. The swap
  belongs to the transcript slice; until then the app's Send button is a known exception.
- The launcher icon, notification small icon and master mark live in `../` (app-mark.md).
- Shared rule with the ChitChat web client: these files, their paths and their meanings change
  only through paired PRs in both repositories. The web copies them with provenance (CCM
  commit SHA plus path).
