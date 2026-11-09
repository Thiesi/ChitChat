import { test, expect } from '@playwright/test';
import http from 'node:http';
import { spawn } from 'node:child_process';
import path from 'node:path';

// A real PHP response and a second origin reproduce the POST -> 303 CSP check.
test('native confirmation permits its provider redirect while global forms stay self only', async ({ page, request, browserName }) => {
  let providerVisits = 0;
  const provider = http.createServer((req, res) => { providerVisits++; res.end('Provider'); });
  await new Promise(resolve => provider.listen(0, '127.0.0.1', resolve));
  const providerOrigin = `http://127.0.0.1:${provider.address().port}`;
  const portProbe = http.createServer();
  await new Promise(resolve => portProbe.listen(0, '127.0.0.1', resolve));
  const port = portProbe.address().port;
  await new Promise(resolve => portProbe.close(resolve));
  const origin = `http://127.0.0.1:${port}`;
  const server = spawn(process.env.PHP_BINARY ?? 'php', ['-S', `127.0.0.1:${port}`, path.resolve('tests/Support/native-csp-router.php')], {
    env: { ...process.env, APP_ENV: 'test', OIDC_REDIRECT_ORIGIN: 'https://chat.test',
      GOOGLE_OIDC_CLIENT_ID: 'client', GOOGLE_OIDC_CLIENT_SECRET: 'secret', OIDC_GOOGLE_ENDPOINT_OVERRIDE: providerOrigin,
      TWITCH_OIDC_CLIENT_ID: '', TWITCH_OIDC_CLIENT_SECRET: '', SESSION_COOKIE_SECURE: '0' },
    windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
  });
  let errors = '';
  server.stderr.on('data', bytes => { errors = (errors + bytes.toString()).slice(-4096); });
  server.stdout.resume();
  try {
    await expect.poll(async () => {
      if (server.exitCode !== null) throw new Error(errors);
      try { return (await request.get(origin, { timeout: 1000 })).status(); } catch { return 0; }
    }).toBe(200);
    const global = await page.goto(origin);
    const globalPolicy = global.headers()['content-security-policy'];
    expect(globalPolicy).toMatch(/(?:^|; )form-action 'self'$/);
    // The redirect/console behavior differs across engines. Chromium is the
    // native Custom Tabs regression; every engine checks both policy headers
    // and the permitted native navigation below.
    if (browserName === 'chromium') {
      const violation = page.waitForEvent('console', { predicate: msg => msg.type() === 'error' && msg.text().includes('form-action') });
      await page.getByRole('button', { name: 'Continue' }).click({ noWaitAfter: true });
      await violation;
      expect(providerVisits).toBe(0);
    }
    const native = await page.goto(origin + '/native');
    expect(native.headers()['content-security-policy']).toBe(globalPolicy + ' ' + providerOrigin);
    await Promise.all([page.waitForURL(providerOrigin + '/authorize'), page.getByRole('button', { name: 'Continue' }).click()]);
    expect(providerVisits).toBe(1);
    // A later ordinary page still receives the unchanged global policy.
    expect((await request.get(origin + '/rules.php')).headers()['content-security-policy']).toBe(globalPolicy);
  } finally {
    if (server.exitCode === null) {
      const exited = new Promise(resolve => server.once('exit', resolve));
      server.kill();
      await exited;
    }
    provider.closeAllConnections();
    await new Promise(resolve => provider.close(resolve));
  }
});