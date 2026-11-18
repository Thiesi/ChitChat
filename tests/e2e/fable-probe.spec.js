import { test } from '@playwright/test';

// Throwaway probe: which elements stick out of a narrow window? Never committed.
test.use({ serviceWorkers: 'block' });
const NOW = new Date(2026, 10, 14, 12, 0, 0);
const at = (minutesAgo) => new Date(NOW.getTime() - minutesAgo * 60_000).toISOString();
const jamie = {
  id: 1, username: 'Jamie', roles: ['user'], session_version: 1, guest: false, username_change: null,
  rules: { accepted_version: 1, accepted_at: '2026-10-01T10:00:00Z', acceptance_required: false },
  recovery_key: { exists: true, created_at: '2026-11-01T10:00:00Z' },
};
const notifications = [
  { id: 60, kind: 'mentioned', title: 'You were mentioned', message: 'Jonas mentioned you in “Board games night”.', details: [], link: '/?room_id=4&message_id=77', read: false, created_at: at(30) },
  { id: 59, kind: 'pinged', title: 'Rowan pinged you', message: 'Rowan pinged you in “Garden”.', details: [], link: '/?room_id=5&ping_id=3', read: false, created_at: at(90) },
  { id: 58, kind: 'direct_message_received', title: 'New direct message', message: 'Rowan sent you a direct message.', details: [], link: '/messages.php?with=2', read: true, created_at: at(275) },
];

for (const font of (process.env.PROBE_FONTS ?? 'default,Verdana').split(',')) {
  test(`probe ${process.env.PROBE_PATH ?? '/notifications.php'} at ${process.env.PROBE_WIDTH ?? 180} in ${font}`, async ({ page }) => {
    const width = Number(process.env.PROBE_WIDTH ?? 180);
    await page.setViewportSize({ width, height: Math.round(width * 2.1) });
    await page.clock.setFixedTime(NOW);
    await page.addInitScript(() => localStorage.setItem('chitchat-new-look-seen', '1'));
    if (font !== 'default') {
      await page.route('**/assets/css/tokens.css', async (route) => {
        const response = await route.fetch();
        await route.fulfill({ response, body: `${await response.text()}\n:root, button, input, select, textarea { font-family: ${font}, sans-serif !important; }` });
      });
    }
    await page.route('**/api/v1/**', async (route) => {
      const path = new URL(route.request().url()).pathname.replace('/api/v1/', '');
      if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
      const responses = {
        'session.php': { user: jamie, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], guest_access: true, capabilities: { totp: true, signed_in_sessions: true } },
        'account/notifications/list.php': { notifications, unread_count: 2 },
        'direct-messages/conversations.php': { conversations: [] },
      };
      await route.fulfill({ json: responses[path] ?? {} });
    });
    await page.goto(process.env.PROBE_PATH ?? '/notifications.php');
    await page.waitForTimeout(1500);
    const report = await page.evaluate(() => {
      const limit = window.innerWidth;
      const out = [];
      for (const node of document.querySelectorAll('body *')) {
        const box = node.getBoundingClientRect();
        if (box.width > 0 && box.right > limit + 0.5) out.push(`${node.tagName.toLowerCase()}${node.id ? `#${node.id}` : ''}.${String(node.className).replace(/\s+/g, '.')} right=${Math.round(box.right)} width=${Math.round(box.width)} "${(node.textContent ?? '').trim().slice(0, 30)}"`);
      }
      return { overflow: document.documentElement.scrollWidth - limit, out: out.slice(0, 25) };
    });
    console.log(`PROBE ${font}: overflow ${report.overflow}\n${report.out.join('\n')}`);
  });
}
