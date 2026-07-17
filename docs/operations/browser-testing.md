# Browser end-to-end testing

ChitChat uses Playwright to exercise the deployed browser interface against real PHP workers and PostgreSQL. The browser suite is separate from PHPUnit because it validates JavaScript modules, sessions, Server-Sent Events, multipart uploads, protected downloads, and interactions between multiple logged-in browser contexts.

## Covered journey

The same release journey runs in current Chromium and Firefox and verifies:

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

The test uses separate browser contexts for the two accounts. Cookies, sessions, and realtime streams are therefore isolated in the same way as two independent browsers.

Chromium and Firefox run as separate CI jobs with separate PostgreSQL service containers and attachment directories. A failure in one engine does not cancel or contaminate the other engine's run.

## Local prerequisites

Install PHP and Composer dependencies, migrate an empty test database, and install the locked Node dependencies:

```sh
composer install
composer migrate
npm ci
npx playwright install chromium firefox
```

Use a disposable PostgreSQL database. The browser test creates accounts, rooms, messages, attachments, DMs, audit entries, and policy changes.

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
```

For an interactive inspector:

```sh
npm run test:e2e:debug -- --project=chromium
```

The configuration uses one worker per browser project and runs tests serially because the full journey intentionally builds on a fresh database. Do not run both projects against the same database concurrently outside the CI matrix.

## Failure diagnostics

CI retains these only when a browser job fails:

- the PHP development-server log;
- Playwright traces;
- screenshots;
- videos;
- the HTML report.

Artifacts are named for the browser project, such as `browser-diagnostics-chromium` and `browser-diagnostics-firefox`.

Open a trace locally with:

```sh
npx playwright show-trace test-results/<test-directory>/trace.zip
```

Browser failures should be fixed against the exact trace and server log. Do not merely increase timeouts unless the trace proves the application completed correctly but exceeded a legitimate operational bound.

## Scope

These are deep release-smoke journeys, not visual-regression tests and not exhaustive accessibility audits. WebKit/Safari remains a manual release-candidate evaluation target until a reliable CI gate is added.
