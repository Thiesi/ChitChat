# Provenance: qrcode-generator

`qrcode.js` draws the QR code for setting up an authenticator app (#204). It runs only in the page: the setup secret and its `otpauth://` URI are encoded locally and never sent anywhere.

| File here | Source | SHA-256 of the file here |
|---|---|---|
| `qrcode.js` | `dist/qrcode.mjs` from the npm package **qrcode-generator 2.0.4**, tarball https://registry.npmjs.org/qrcode-generator/-/qrcode-generator-2.0.4.tgz (npm integrity `sha512-mZSiP6RnbHl4xL2Ap5HfkjLnmxfKcPWpWe/c+5XxCuetEenqmNFf1FH/ftXPCtFG5/TDobjsjz6sSNL0Sr8Z9g==`, tarball SHA-256 `02e2e18a99a90b02dad940851f59b7c3c5fd1ab79cbdece8595cb06328878159`), copied byte-for-byte and stored with a `.js` name: many servers' MIME tables (CI's nginx among them) serve `.mjs` as `application/octet-stream`, which browsers refuse for module scripts | `ea91d7118a5395289170da848b7c6758b996163bfbccf312591ab65a4911b7c0` |
| `LICENSE` | The upstream repository's `LICENSE` at tag `js2.0.4` (commit `83b7e8fe3fddd3b0368dbafd6ce56995bd25e3c8`), https://github.com/kazuhikoarase/qrcode-generator. The npm tarball has no LICENSE file; the source header carries the same MIT notice | `3a850fa5f08101db6f40676c2786e10bd2cd5fff7b12ffdf1e0c434d4e49d90c` |

Licence: MIT, Copyright (c) 2009 Kazuhiko Arase. "QR Code" is a registered trademark of DENSO WAVE INCORPORATED.

To update: download the new version's tarball, check its npm integrity, copy `dist/qrcode.mjs` unchanged to `qrcode.js`, and the matching tag's `LICENSE` unchanged, and update this table (see docs/operations/dependency-security.md).
