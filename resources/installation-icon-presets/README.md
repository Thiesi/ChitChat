# Installation icon preset sources (#226)

Drawn by the visual lead from the maintainer-approved presets sheet (v2, https://claude.ai/artifact/W17WUMaCqntTxCmMH2YE8Z, including CCM's review edits). Each `.svg` is the exact source of its `.png`; regenerate the PNG from the SVG if a drawing ever changes, and update this table.

## Contract with the renderer

- Every PNG is a **transparent foreground canvas**. The server flattens it onto the preset's fixed background (below) and scales it to each output size. It is composited **1:1**, with no alpha-aware fitting or recentring: the drawings already sit inside the adaptive-icon safe zone at their approved size and offset (the chit is off-centre on purpose).
- Full drawings: 1024 × 1024 px on the 108-unit adaptive grid (safe zone: the central 72 units). Used for every rendition above 32 px, maskable included.
- Compact drawings (`-compact.png`): 256 × 256 px on the sheet's 24-unit favicon grid, full bleed. Used for renditions of 32 px and below (favicon, ICO). Only the five Lantern-mark presets have one; Two voices and Spark use their full drawing at every size.
- **ChitChat icon** (`lantern`) has no source here: choosing it resets the icon to null, so the server's own default lantern shows.

## Presets

| ID | Label | Background (fixed) | Foreground | Contrast | Full source SHA-256 | Compact source SHA-256 |
|---|---|---|---|---|---|---|
| `lantern` | ChitChat icon | (server default) | — | 6.1:1 | reset to null | — |
| `lantern-paper` | Lantern paper | `#FBF7F1` | `#B5401A` | 5.3:1 | `fedb8c1094842f22f27629da4aef70197359ced43ac4b02dec1b25e7ae299e9d` | `094434a66bc9a9a3a21ff6777966f8b3b7236e89ed4d7de48196745b33772516` |
| `lounge` | Lounge | `#111E22` | `#A8DBC5` | 11.0:1 | `5614641d5a01739a3f98322820eac19f2571e62b7f3152e4b91a1006e866745b` | `b9128d9a94b8337d5d7d85c4b87572f16f0291609d298afe21ffc50a4aa06530` |
| `dusk` | Dusk | `#141729` | `#A9B4FF` | 9.0:1 | `c36b9b56cacffbd28a2d132246dd9d88eab0a8a4864562de766efc43031e67fb` | `97fbc1db026802c398b13aa8294e72ce74ca28e75a8845a8892bb775fdc76a89` |
| `ember` | Ember | `#1D1612` | `#F0A868` | 8.9:1 | `01e71650b20ad6268c2b1eba7052652ce38a4cf38b8df2942f742789f3d2ea30` | `bdb981cd2f087ecf29c97825886826c4468a63a6fb672786e78171638de64905` |
| `rose` | Rosé | `#1D1419` | `#F0A6C4` | 9.4:1 | `8aedc8f1d856e0b63de05c10887d3af7145cde863fa6f71f527dcb96006412f1` | `d3624ef13f4cad16bbbed493dfbf5ac89cb9f43f3d44f4a23fb194d4f6dd32be` |
| `voices` | Two voices | `#213D38` | `#A8DBC5` / `#5F8F80` | 7.6:1 / 3.2:1 | `d5f84ca2d82add16eb41593c27e4553aaebfae82c9f3407496cc4a46342133b2` | — |
| `spark` | Spark | `#22274A` | `#FFC857` | 9.4:1 | `abef20c51db8b03e64ffc65ea82edfd39713c8470e237f29ad68b941da8b2e1e` | — |
| `initial` | Your initial | `#F06A3B` | `#14111C`, Bricolage Grotesque ExtraBold | 6.1:1 | generated (below) | generated (below) |

## Your initial: geometry

Rendered by the server with `resources/fonts/BricolageGrotesque-ExtraBold.ttf` onto a transparent canvas, then flattened onto `#F06A3B` like the other presets. No auto-fit: the size is fixed so every installation's letter looks the same.

- **Glyph:** the first character of the effective installation name after trimming whitespace; letters are upper-cased (multibyte-aware). Offered only when the font's cmap covers that character (the server's coverage manifest).
- **Ink:** `#14111C`.
- **Placement:** the centre of the glyph's ink bounding box sits at the canvas centre, then the optical offset below is applied (positive y is down).

| Profile | Used for | Canvas | font_size | offset_x | offset_y |
|---|---|---|---|---|---|
| full | renditions above 32 px, maskable included | 1024 px | 493 px (52 of 108 units, as on the sheet) | 0 | 0 |
| compact | 32 px and below (favicon, ICO) | 256 px | 166 px (65% of the side, for legibility at 16 and 32 px) | 0 | 0 |

Centring on the ink box (not the baseline) keeps letters with descenders or accents optically centred, matching the sheet's `T`. If a later drawing review asks for an optical lift, change `offset_y` here and in the server's encoded metrics together.
