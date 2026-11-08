# Lantern tokens for the web: mapping from today's CSS

Status: applied by S0 (#167). `public/assets/css/tokens.css` is now the only place colour values live. Direction and decisions: #163 (phase-2 direction, revision 1, owner approval).

**Provenance.** Colour values come from Thiesi/ChitChat-Mobile @ `e9eb5b452ba23c316e1962e7993fe09010ff9933`, `docs/design/visual-vocabulary.md`. The CCM icon set comes from the same commit, `docs/design/assets/icons/`, and the identity-hue hash from its "Identity hues" section. Shared values, the mark, the icons and the hash change only through paired PRs in both repositories.

## Files

| File | What it is |
|---|---|
| `public/assets/css/tokens.css` | Every scheme's colour, shadow and type tokens, the fonts and the picker swatches. Lantern is the `:root` default. |
| `tests/js/css-literals.test.js` | Fails on any colour literal outside `tokens.css`. |
| `tests/js/design-contrast.test.js` | Checks every scheme in `tokens.css` against the contrast floors (text 4.5:1, boundaries 3:1, ink on identity hues 8.4:1). |
| `public/assets/js/identity-hue.js` | The shared identity-hue hash. |
| `tests/js/identity-hue.test.js` | Pins CCM's seven test vectors. |

## Colour roles

"Uses" counts `var(--…)` references across `public/assets/css` at `687898f`.

| Lantern role | Replaces | Uses today | Notes |
|---|---|---|---|
| `--ground` | `--bg`, `--canvas` | 14 + 4 | One page ground. The chat transcript's separate canvas goes away. |
| `--surface` | `--surface` | 44 | Cards, fields, sheets, the rail and the bar. |
| `--raised` | `--surface-2`, `--option-active-bg` | 43 + 1 | Incoming bubbles, quiet chips, the selected option. |
| `--line` | `--border` (decorative uses) | part of 97 | Dividers only. **Never** a component boundary. |
| `--field-line` | `--border` (boundary uses) | part of 97 | Fields, outlined buttons, selected chips (3:1). S0 sorts each of the 97 uses into one of these two roles; that sorting is the main manual step. |
| `--text` | `--text` | 34 | |
| `--text-muted` | `--muted` | 94 | |
| `--signature` | `--accent` (fills) | part of 51 | Persimmon: the one action and attention colour. Never the only cue on light grounds (about 2.9:1 fill on paper). |
| `--on-signature` | `--on-accent` | 5 | Always ink on persimmon, never white. |
| `--signature-text` | `--accent-strong`, `--accent` (as text) | 32 + part of 51 | Persimmon used as text. |
| `--outgoing`, `--on-outgoing` | (new) | — | Your bubbles (S1). |
| `--presence` | `--warm` (people and attention uses) | part of 12 | Honey: someone is here, typing, mentioned you. |
| `--presence-wash` | `--mention-bg`, `--reaction-active-bg`, `--broadcast-bg` | 2 + 1 + 1 | Mention highlight, your own reaction, broadcast mention. Their `-text` partners become `--text`. |
| `--unsure-line` | `--warning` (borders) | part of 15 | Uncertain states (lockdown strip, "couldn't confirm"). |
| `--steward` | (new) | — | Dusk: links, and the action colour on administration and moderation pages (S6). |
| `--error` | `--danger` | 12 | Errors and destructive confirmation only. |
| *(removed)* | `--success` | 2 | No green for success. Confirmed states use ink and wording. Both uses are status dots, which get a shape or label instead. |
| *(removed)* | `--warm` (decorative uses) | part of 12 | The welcome-art spark and the second avatar tone become identity hues or `--presence`. |

### Non-colour tokens

| Token | Today | S0 |
|---|---|---|
| `--shadow` | 2 values in the token blocks plus 7 literal `box-shadow` values | `--shadow-raised` (cards, popovers) and `--shadow-overlay` (dialogs, drawers), defined per theme. |
| `--scrim` | 6 literal backdrop values (`rgb(0 0 0 / 0.45 … 0.68)`) | One scrim colour per theme for dialog and drawer backdrops. |
| `--display-font` | Georgia, used 7 times | Bricolage Grotesque 700/800, self-hosted, display roles and the installation name only. |
| *(new)* `--radius-bubble`, `--radius-sheet`, `--radius-field`, `--radius-pill` | literals | 20px, 28px, 16px, 999px (CCM shape table). |
| *(new)* `--target-min` | — | 44px, or 48px under `(any-pointer: coarse)` (decision #2). |

**Literal colours outside the token blocks** (before S0): 12 hex values in the scheme-picker swatches (now in `tokens.css`) and the rest in today's eleven scheme blocks. The only literals are 13 `rgb()` shadows and backdrops (`app.css`, `account.css`, `components.css`, `step-up.css`), which the two token families above replace. S0's source scan then fails on any colour literal outside the token file.

*(This corrects the phase-2 direction's estimate of "about 150 literal colours outside the token blocks". The clean-up is mostly sorting `--border` and `--accent` uses into their new roles, not chasing stray literals.)*

## Schemes

| Scheme | S0 behaviour |
|---|---|
| **Lantern** | The default for everyone without a stored explicit choice (`theme.js` stores only explicit picks). |
| Lounge, Dusk, Ember, Rosé | Opt-in variants. Each re-tints the roles above with its own hues. Semantic roles keep their meaning in every variant (honey for presence, `--unsure-line`, rose for errors, Dusk for stewardship); they may shift in value for contrast but never swap. Values are defined in S0 and must pass `design-contrast.test.js`. |
| Midnight | CC's own opt-in true-black scheme, dark only. It uses CCM's AMOLED override values (`--ground #000000`, `--surface #110E17`, `--raised #1F1B29`, `--line #2E2938`) and is never labelled as Lantern's dark mode or as "Pure black". Defined here and already passing. |
| High contrast | The accessibility palette. Contrast floors at AAA where practical (S0). |

People who explicitly chose a scheme keep it after the update.

## Checks

```sh
node --test tests/js/identity-hue.test.js tests/js/design-contrast.test.js
```

Both run in CI with the other `tests/js` tests. Today they cover Lantern and Midnight. In S0, every variant's block is added to the token file and the same test covers it.
