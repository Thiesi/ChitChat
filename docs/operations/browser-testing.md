# Browser end-to-end testing

ChitChat uses Playwright to exercise the deployed browser interface against real PHP workers and PostgreSQL. The browser suite is separate from PHPUnit because it validates JavaScript modules, sessions, Server-Sent Events, multipart uploads, protected downloads, accessibility semantics, responsive layout, and interactions between multiple logged-in browser contexts.

## Covered journey

The same release journey runs in current Chromium, Firefox, and WebKit and verifies:

- hardened HTTP response headers and anonymous API rejection;
- first-account Super-Administrator bootstrap;
- room creation, discovery, joining, and presence;
- two-way realtime room messages and `/me` rendering;
- targeted `/ping` delivery;
- multipart attachment upload, realtime rendering, authorized download, and exact file content;
- direct-message privacy disclosure, user search, two-way history, and realtime delivery;
- administration and operational-settings access for the first Super-Administrator;
- disabling public registration and the anonymous browser hiding the registration interface;
- restoring registration at the end of the test.

Later feature work added its own dedicated journeys as separate spec files in the same suite, each following the same real-PHP/real-PostgreSQL approach: passkey MFA enrollment and login (`mfa-passkeys.spec.js`), direct-message attachments (`zz-dm-attachments.spec.js`), message editing and delete-for-everyone (`zzz-message-mutations.spec.js`), administrative revision review (`zzzz-message-revision-review.spec.js`), system status (`zzzzzz-system-status.spec.js`), privileged step-up (`zzzzzzz-privileged-step-up.spec.js`), personal-data export (`zzzzzzzz-personal-data-export.spec.js`), account closure and restoration (`zzzzzzzzz-account-closure.spec.js`), message search (`zzzzzzzzzz-message-search.spec.js`), moderation reports (`zzzzzzzzzzz-moderation-reports.spec.js`), and replies/mentions (`zzzzzzzzzzzz-replies-mentions.spec.js`). Message reactions (`ReactionServiceTest.php`) and Web Push currently have PHPUnit integration coverage but no dedicated browser journey.

The test uses separate browser contexts for the two accounts. Cookies, sessions, and realtime streams are therefore isolated in the same way as two independent browsers.

Chromium, Firefox, and WebKit run as separate CI jobs with separate PostgreSQL service containers and attachment directories. A failure in one engine does not cancel or contaminate another engine's run.

## Accessibility layers

The browser suite keeps accessibility checks in separate layers because each catches a different class of regression.

### Structural cross-browser smoke checks

Chromium, Firefox, and WebKit validate document language and title, one visible main landmark and level-one heading, unique IDs, labelled visible form controls, named interactive elements and dialogs, keyboard-operated authentication tabs, and a visible focus indicator.

### axe-core semantic checks

Pinned Chromium runs `@axe-core/playwright` against signed-out authentication, account restoration, signed-in chat, direct messages, Account, and Privacy notifications. The gate enables automated WCAG 2.0, 2.1, and 2.2 Level A/AA rule tags. A failure reports rule IDs, impact, help text, targets, and failure summaries.

This is intentionally one deterministic semantic gate rather than three engine-identical scans. The structural and functional journeys still run across all three engines.

### Reflow and user-preference checks

Pinned Chromium also validates:

- no document-level horizontal overflow at 640 CSS pixels, representing a 1280-pixel layout at approximately 200% zoom;
- no document-level horizontal overflow on core account/privacy surfaces at 320 CSS pixels;
- selected controls, primary actions, and focus indicators under forced-colors emulation;
- negligible animation and transition durations under `prefers-reduced-motion: reduce`.

Emulation cannot prove behavior with real browser zoom, Windows contrast themes, NVDA, or VoiceOver. Follow [`accessibility-review.md`](accessibility-review.md) for the required manual procedure and honest result recording.

## Targeted visual regression

Pinned Chromium on Linux compares three deliberately narrow screenshot baselines:

- signed-out authentication at 1280×900;
- the loaded Account page at 1280×900;
- the loaded Account page at 390×844.

The baselines protect critical typography, spacing, card, focus-independent, and responsive-layout states without snapshotting message timelines, timestamps, realtime indicators, or other frequently changing content. Animations and carets are disabled during capture. A small pixel-difference allowance absorbs antialiasing noise while still rejecting meaningful layout drift.

Snapshot files live beside the visual test in:

```text
tests/e2e/zzzzzzzzz-visual.spec.js-snapshots/
```

Do not regenerate baselines merely to make CI green. Review the received actual/diff images, confirm that the product change is intentional, and regenerate only with the pinned Playwright Chromium build on Linux:

```sh
npm run test:e2e -- tests/e2e/zzzzzzzzz-visual.spec.js \
  --project=chromium \
  --update-snapshots=all
```

Commit the changed PNGs together with the code or CSS change that justified them. Pull requests should explain the visible difference.

## Local prerequisites

Install PHP and Composer dependencies, migrate an empty test database, and install the locked Node dependencies:

```sh
composer install
composer migrate
npm ci
npx playwright install chromium firefox webkit
```

Use a disposable PostgreSQL database. The browser test creates accounts, rooms, messages, attachments, DMs, audit entries, policy changes, and visual-test accounts.

## Start the application

The PHP development server must use multiple workers because each open SSE request occupies one worker for approximately 25 seconds:

```sh
mkdir -p /tmp/chitchat-browser-uploads
chmod 700 /tmp/chitchat-browser-uploads

export APP_ENV=test
export APP_DEBUG=1
export SESSION_COOKIE_SECURE=0
export ATTACHMENT_STORAGE_PATH=/tmp/chitchat-browser-uploads
export PHP_CLI_SERVER_WORKERS=8

php -S 127.0.0.1:8080 -t public
```

Set the normal `DB_*` variables for the disposable test database in the same shell or `.env`.

## Run the suite

In another shell:

```sh
export CHITCHAT_BASE_URL=http://127.0.0.1:8080
npm run test:e2e
```

Run one browser explicitly with:

```sh
npm run test:e2e -- --project=chromium
npm run test:e2e -- --project=firefox
npm run test:e2e -- --project=webkit
```

Run only the deep accessibility or visual layer with:

```sh
npm run test:e2e -- tests/e2e/zzzzzzzz-accessibility-deep.spec.js --project=chromium
npm run test:e2e -- tests/e2e/zzzzzzzzz-visual.spec.js --project=chromium
```

For an interactive inspector:

```sh
npm run test:e2e:debug -- --project=chromium
```

The configuration uses one worker per browser project and runs tests serially because the full journey intentionally builds on a fresh database. Do not run multiple projects against the same database concurrently outside the CI jobs.

## Failure diagnostics

CI retains these only when a browser job fails:

- the PHP development-server log;
- Playwright traces;
- actual, expected, and diff screenshots;
- videos;
- the HTML report.

Artifacts are named for the browser project, such as `browser-diagnostics-chromium`, `browser-diagnostics-firefox`, and `browser-diagnostics-webkit`.

Open a trace locally with:

```sh
npx playwright show-trace test-results/<test-directory>/trace.zip
```

Browser failures should be fixed against the exact trace and server log. Do not merely increase timeouts or replace screenshot baselines unless the evidence proves the application behavior is correct and the expected result has intentionally changed.

## Scope

The release journeys and automated accessibility layers are regression gates, not a complete WCAG audit. They do not validate spoken phrasing, rotor or element-list usefulness, braille output, cognitive accessibility, real platform contrast themes, all user stylesheets, or every combination of zoom and font settings. Manual assistive-technology review remains an explicit release-quality activity rather than an implied result of green automation.
