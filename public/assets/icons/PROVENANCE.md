# Provenance of the shared Lantern assets

These files are copied unchanged from the ChitChat Mobile repository so the web client and the app share one visual identity (phase-2 direction, #163). Change them only through paired PRs in both repositories.

| Here | Source | Licence |
|---|---|---|
| `public/assets/icons/*.svg`, `public/assets/icons/README.md` | Thiesi/ChitChat-Mobile @ `e9eb5b452ba23c316e1962e7993fe09010ff9933`, `docs/design/assets/icons/` | BSD 2-Clause, see `LICENSE-ChitChat-Mobile.txt` |
| `public/assets/app-icons/lantern-mark.svg` | same commit, `docs/design/assets/lantern-mark.svg` | BSD 2-Clause |
| `public/favicon.svg`, `public/assets/app-icons/*.png` | rendered from that mark (the favicon and the 192/512 icons on a rounded tile; the maskable and Apple touch icons full-bleed, inside the mark's safe zone) | BSD 2-Clause |
| `public/assets/fonts/bricolage-grotesque-{700,800}.woff2` | same commit, `app/src/main/res/font/bricolage_grotesque_{bold,extra_bold}.ttf`, subset to Latin and Latin Extended and converted to WOFF2 with fontTools | SIL Open Font License 1.1, see `public/assets/fonts/OFL.txt` |

The fonts are self-hosted on purpose: loading them from a font service would send every visitor's address to a third party, and ChitChat's content security policy allows fonts only from its own origin.

The subset covers U+0000–024F, U+0259, U+1E00–1EFF, general punctuation (U+2000–206F), currency (U+20A0–20CF), ™ and the minus sign. Characters outside it (for example → and №, which the source font doesn't contain either) fall back to the system font. Bricolage is used for display text only, never for messages.
