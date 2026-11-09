import { test, expect, request as apiFactory } from '@playwright/test';
import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import http from 'node:http';
import https from 'node:https';
import crypto from 'node:crypto';

const php = process.env.PHP_BINARY ?? 'php';
const setup = path.resolve('tests/e2e/fixtures/native-cookie-setup.php');
const verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
const cookieName = 'CCNATIVEBROWSERTEST';

async function freePort() {
  const server = net.createServer();
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;
  await new Promise(resolve => server.close(resolve));
  return port;
}

async function ready(process, port, log) {
  await expect.poll(async () => {
    if (process.exitCode !== null) throw new Error(fs.readFileSync(log, 'utf8'));
    return new Promise(resolve => {
      const socket = net.connect(port, '127.0.0.1');
      socket.once('connect', () => { socket.destroy(); resolve(true); });
      socket.once('error', () => resolve(false));
      socket.setTimeout(500, () => { socket.destroy(); resolve(false); });
    });
  }).toBe(true);
}

function startPhp(args, env, log) {
  const fd = fs.openSync(log, 'w');
  try { return spawn(php, args, { env, windowsHide: true, stdio: ['ignore', fd, fd] }); }
  finally { fs.closeSync(fd); }
}

async function stop(process) {
  if (!process || process.exitCode !== null) return;
  const exited = new Promise(resolve => process.once('exit', resolve));
  process.kill();
  await exited;
}

const scenarios = ['native', 'web-login', 'web-step-up'].flatMap(flow => ['Strict', 'Lax'].map(sameSite => ({ flow, sameSite })));
for (const { flow, sameSite } of scenarios) {
  test(`real cross-site ${sameSite} ${flow} preserves the bound browser session`, async ({ browser, browserName }, testInfo) => {
    test.skip(browserName !== 'chromium', 'Cross-site cookie delivery is proved in real Chromium.');
    test.setTimeout(90_000);
    const directory = testInfo.outputPath('native-cookie');
    fs.mkdirSync(directory, { recursive: true });
    const statePath = path.join(directory, 'provider.json');
    const appPort = await freePort();
    const providerPort = await freePort();
    const tlsPort = await freePort();
    const origin = `https://localhost:${tlsPort}`;
    const providerOrigin = `http://127.0.0.1:${providerPort}`;
    const username = 'NativeCookie' + crypto.randomBytes(4).toString('hex');
    const transactions = [];
    const env = { ...process.env, APP_ENV: 'test', APP_DEBUG: '1', APP_ORIGIN: origin, OIDC_REDIRECT_ORIGIN: origin,
      SESSION_NAME: cookieName, SESSION_COOKIE_SECURE: '1', SESSION_COOKIE_SAMESITE: sameSite,
      WEBAUTHN_RP_ID: 'localhost', WEBAUTHN_ORIGIN: origin,
      GOOGLE_OIDC_CLIENT_ID: 'chitchat-client', GOOGLE_OIDC_CLIENT_SECRET: 'client-secret', OIDC_GOOGLE_ENDPOINT_OVERRIDE: providerOrigin,
      TWITCH_OIDC_CLIENT_ID: '', TWITCH_OIDC_CLIENT_SECRET: '', OIDC_TWITCH_ENDPOINT_OVERRIDE: '',
      CC_NATIVE_PROVIDER_FIXTURE: statePath,
      NATIVE_ANDROID_APPS: JSON.stringify([{ package: 'de.networkxxiii.chitchat', sha256_cert_fingerprint: Array(32).fill('AB').join(':') }]),
    };
    delete env.PHP_CLI_SERVER_WORKERS;
    let user, app, provider, proxy, context, native;
    try {
      user = JSON.parse(execFileSync(php, [setup], { env, input: JSON.stringify({ path: statePath, origin, issuer: providerOrigin, username }), windowsHide: true }).toString());
      const material = JSON.parse(fs.readFileSync(statePath, 'utf8'));
      const appSessions = path.join(directory, 'sessions');
      fs.mkdirSync(appSessions);
      app = startPhp(['-d', `session.save_path=${appSessions}`, '-S', `127.0.0.1:${appPort}`, '-t', path.resolve('public')], env, path.join(directory, 'app.log'));
      provider = startPhp(['-S', `127.0.0.1:${providerPort}`, path.resolve('tests/Support/native-oidc-router.php')], env, path.join(directory, 'provider.log'));
      await ready(app, appPort, path.join(directory, 'app.log'));
      await ready(provider, providerPort, path.join(directory, 'provider.log'));
      // Test-only TLS terminator: the production PHP controller handles real Secure __Host cookies.
      proxy = https.createServer({ key: material.pem, cert: material.tls_cert }, (req, res) => {
        const upstream = http.request({ hostname: '127.0.0.1', port: appPort, path: req.url, method: req.method, headers: req.headers }, response => {
          res.writeHead(response.statusCode, response.headers);
          response.pipe(res);
        });
        upstream.on('error', () => { res.writeHead(502); res.end(); });
        req.pipe(upstream);
      });
      await new Promise(resolve => proxy.listen(tlsPort, '127.0.0.1', resolve));
      context = await browser.newContext({ ignoreHTTPSErrors: true });
      native = await apiFactory.newContext({ baseURL: origin, ignoreHTTPSErrors: true });
      const bootstrap = await (await context.request.get(origin + '/api/v1/session.php')).json();
      if (flow !== 'web-login') {
        const login = await context.request.post(origin + '/api/v1/login.php', { headers: { 'X-CSRF-Token': bootstrap.csrf_token }, data: { username, password: 'a very secure password' } });
        expect(login.status()).toBe(200);
      }
      const sid = (await context.cookies(origin)).find(cookie => cookie.name === cookieName);
      expect(sid.sameSite).toBe(sameSite);
      expect(sid.secure).toBe(true);
      if (flow !== 'native') {
        const begun = flow === 'web-step-up'
          ? await context.request.post(origin + '/api/v1/oidc/begin.php', {
            headers: { 'X-CSRF-Token': (await (await context.request.get(origin + '/api/v1/session.php')).json()).csrf_token },
            data: { provider: 'google', purpose: 'step_up' },
          })
          : await context.request.get(origin + '/api/v1/oidc/start.php?provider=google', { maxRedirects: 0 });
        const authorization = flow === 'web-step-up' ? (await begun.json()).url : begun.headers().location;
        expect(new URL(authorization).origin).toBe(providerOrigin);
        const page = await context.newPage();
        await page.goto(authorization);
        // A different cookie jar cannot consume a valid flow owned by this browser.
        const returnHref = await page.getByRole('link', { name: 'Return to chat' }).getAttribute('href');
        await native.get('/api/v1/session.php');
        const foreign = await native.get(returnHref, { maxRedirects: 0 });
        expect(foreign.status()).toBe(302);
        expect(foreign.headers().location).toContain('sign_in_error=');
        expect((await (await native.get('/api/v1/session.php')).json()).user).toBeNull();
        await native.dispose();
        native = await apiFactory.newContext({ baseURL: origin, ignoreHTTPSErrors: true });
        const callbackRequestPromise = page.waitForRequest(req => new URL(req.url()).pathname === '/api/v1/oidc/callback.php');
        const callbackResponsePromise = page.waitForResponse(res => new URL(res.url()).pathname === '/api/v1/oidc/callback.php');
        await page.getByRole('link', { name: 'Return to chat' }).click();
        const firstRequest = await callbackRequestPromise;
        const firstResponse = await callbackResponsePromise;
        const received = await firstRequest.allHeaders();
        expect(received['sec-fetch-site']).toBe('cross-site');
        if (sameSite === 'Strict') {
          expect(received.cookie ?? '').not.toContain(cookieName + '=');
          expect(firstResponse.status()).toBe(200);
          expect((await firstResponse.allHeaders())['set-cookie']).toBeUndefined();
          expect(await firstResponse.text()).toContain('http-equiv="refresh"');
        } else {
          expect(received.cookie).toContain(`${cookieName}=${sid.value}`);
          expect(firstResponse.status()).toBe(302);
        }
        await page.waitForURL(url => url.origin === origin && url.pathname === (flow === 'web-step-up' ? '/step-up-complete.php' : '/') && !url.searchParams.has('sign_in_error'));
        const session = await (await context.request.get(origin + '/api/v1/session.php')).json();
        expect(session.user.id).toBe(user.user_id);
        const finalSid = (await context.cookies(origin)).find(cookie => cookie.name === cookieName).value;
        if (flow === 'web-step-up') {
          expect(page.url()).toContain('confirmed=1');
          expect(session.security.privileged_step_up.method).toBe('google');
          expect(finalSid).toBe(sid.value);
        } else expect(finalSid).not.toBe(sid.value); // Ordinary sign-in rotates only after proof.
        // A separate jar models the cross-site callback's temporarily withheld cookie.
        // Missing/malformed state and a second cookie-less bounce must never create a SID.
        for (const query of ['', '?state[]=bad', '?state=bad', `?state=${'x'.repeat(43)}&bounced=1`]) {
          const rejected = await native.get('/api/v1/oidc/callback.php' + query, { maxRedirects: 0 });
          expect(rejected.status()).toBe(302);
          expect(rejected.headers()['set-cookie']).toBeUndefined();
          expect(rejected.headers().location).toContain('sign_in_error=');
        }
        expect((await native.storageState()).cookies).toEqual([]);
        const replay = await context.request.get(firstRequest.url() + '&bounced=1', { maxRedirects: 0 });
        expect(replay.status()).toBe(302);
        expect(replay.headers().location).toContain('sign_in_error=');
        expect((await (await context.request.get(origin + '/api/v1/session.php')).json()).user.id).toBe(user.user_id);
        return;
      }
      const appBootstrap = await (await native.get('/api/v1/session.php')).json();
      const state = crypto.randomBytes(32).toString('base64url');
      const started = await native.post('/api/v1/native-auth/start.php', { headers: { 'X-CSRF-Token': appBootstrap.csrf_token }, data: {
        client_id: 'de.networkxxiii.chitchat', purpose: 'login', provider: 'google', state,
        code_challenge_method: 'S256', code_challenge: crypto.createHash('sha256').update(verifier).digest('base64url'),
      } });
      expect(started.status()).toBe(200);
      const transaction = await started.json();
      transactions.push(transaction.transaction_id);
      expect(new URL(transaction.browser_url).origin).toBe(origin);
      material.browser_entry = transaction.browser_url;
      fs.writeFileSync(statePath, JSON.stringify(material));
      const page = await context.newPage();
      await page.goto(providerOrigin + '/launch');
      const entryRequestPromise = page.waitForRequest(req => req.url() === transaction.browser_url);
      const entryResponsePromise = page.waitForResponse(res => res.url() === transaction.browser_url);
      await page.getByRole('link', { name: 'Start from app' }).click();
      const entryRequest = await entryRequestPromise;
      const entryResponse = await entryResponsePromise;
      const entryResponseHeaders = await entryResponse.allHeaders();
      const entryHeaders = await entryRequest.allHeaders();
      expect(entryHeaders['sec-fetch-site']).toBe('cross-site');
      if (sameSite === 'Strict') {
        expect(entryHeaders.cookie ?? '').not.toContain(cookieName + '=');
        expect(entryResponse.status()).toBe(200);
        expect(entryResponseHeaders.location).toBeUndefined();
        expect(entryResponseHeaders['set-cookie']).toContain('__Host-chitchat-native=');
      } else {
        expect(entryHeaders.cookie).toContain(`${cookieName}=${sid.value}`);
        expect(entryResponse.status()).toBe(303);
      }
      await expect(page.locator('main[data-phase="entry"]')).toBeVisible();
      expect((await context.cookies(origin)).find(cookie => cookie.name === cookieName).value).toBe(sid.value);
      const continueRequest = page.waitForRequest(req => req.url().endsWith('/native-auth/browser/continue.php'));
      await page.locator('form[action$="/continue.php"] button').click();
      const continueHeaders = await (await continueRequest).allHeaders();
      expect(continueHeaders.cookie).toContain(`${cookieName}=${sid.value}`);
      if (sameSite === 'Strict') expect(continueHeaders.cookie).toContain('__Host-chitchat-native=');
      await page.waitForURL(url => url.origin === providerOrigin && url.pathname === '/authorize');
      const callbackRequestPromise = page.waitForRequest(req => new URL(req.url()).pathname === '/api/v1/oidc/callback.php');
      const callbackResponsePromise = page.waitForResponse(res => new URL(res.url()).pathname === '/api/v1/oidc/callback.php');
      await page.getByRole('link', { name: 'Return to chat' }).click();
      const callbackHeaders = await (await callbackRequestPromise).allHeaders();
      const callbackResponse = await callbackResponsePromise;
      const callbackResponseHeaders = await callbackResponse.allHeaders();
      expect(callbackHeaders['sec-fetch-site']).toBe('cross-site');
      if (sameSite === 'Strict') {
        expect(callbackHeaders.cookie ?? '').not.toContain(cookieName + '=');
        expect(callbackResponse.status()).toBe(200);
        expect(callbackResponseHeaders.location).toBeUndefined();
        expect(callbackResponseHeaders['set-cookie']).toContain('__Host-chitchat-native=');
        await expect(page.locator('main[data-phase="verifying"]')).toBeVisible();
        const resumeRequest = page.waitForRequest(req => req.url().endsWith('/native-auth/browser/resume.php'));
        await page.locator('form[action$="/resume.php"] button').click();
        const resumedHeaders = await (await resumeRequest).allHeaders();
        expect(resumedHeaders.cookie).toContain(`${cookieName}=${sid.value}`);
        expect(resumedHeaders.cookie).toContain('__Host-chitchat-native=');
      } else {
        expect(callbackHeaders.cookie).toContain(`${cookieName}=${sid.value}`);
        expect(callbackResponse.status()).toBe(303);
        expect(callbackResponseHeaders['set-cookie'] ?? '').not.toMatch(/__Host-chitchat-native=[A-Za-z0-9_-]{43}\.callback\./);
      }
      await expect(page.locator('main[data-phase="consent"]')).toBeVisible();
      expect((await context.cookies(origin)).find(cookie => cookie.name === cookieName).value).toBe(sid.value);
      const existing = await (await context.request.get(origin + '/api/v1/session.php')).json();
      expect(existing.user.id).toBe(user.user_id);
      expect((await (await native.get('/api/v1/session.php')).json()).user).toBeNull();
      await page.locator('form[action$="/approve.php"] button').click();
      const link = page.getByRole('link', { name: 'Return to the ChitChat app', exact: true });
      const returned = new URL(await link.getAttribute('href'));
      expect(returned.searchParams.get('state')).toBe(state);
      const exchange = await native.post('/api/v1/native-auth/exchange.php', { headers: { 'X-CSRF-Token': appBootstrap.csrf_token }, data: {
        transaction_id: returned.searchParams.get('transaction_id'), code: returned.searchParams.get('code'), code_verifier: verifier, state,
      } });
      expect(exchange.status()).toBe(200);
      expect((await (await native.get('/api/v1/session.php')).json()).user.id).toBe(user.user_id);
      expect((await context.cookies(origin)).find(cookie => cookie.name === cookieName).value).toBe(sid.value);
    } finally {
      await context?.close().catch(() => {});
      await native?.dispose().catch(() => {});
      if (proxy) { proxy.closeAllConnections(); await new Promise(resolve => proxy.close(resolve)); }
      await stop(app);
      await stop(provider);
      if (user) execFileSync(php, [setup], { env, input: JSON.stringify({ action: 'cleanup', user_id: user.user_id, username, transactions }), windowsHide: true });
      // The target is created by this test and verified inside Playwright's output directory.
      const outputRoot = path.resolve(testInfo.outputDir) + path.sep;
      if (!path.resolve(directory).startsWith(outputRoot)) throw new Error('Unexpected fixture cleanup target.');
      fs.rmSync(directory, { recursive: true, force: true });
    }
  });
}
