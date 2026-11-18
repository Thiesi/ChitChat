import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// Throwaway: the phase-1 audit capture set for the premium overhaul.
// Mocked presentation harness only, no live data. Never committed.
test.use({ serviceWorkers: 'block' });
const OUT = process.env.FABLE_OUT;
const ONLY = (process.env.FABLE_ONLY ?? '').split(',').filter(Boolean);
const NOW = new Date(2026, 10, 14, 12, 0, 0);
const at = (minutesAgo) => new Date(NOW.getTime() - minutesAgo * 60_000).toISOString();
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

const base = {
  session_version: 1, guest: false, username_change: null,
  rules: { accepted_version: 1, accepted_at: '2026-10-01T10:00:00Z', acceptance_required: false },
  recovery_key: { exists: true, created_at: '2026-11-01T10:00:00Z' },
};
const jamie = { id: 1, username: 'Jamie', roles: ['user'], ...base };
const root = { id: 1, username: 'Jamie', roles: ['super_admin', 'admin'], ...base };
const rowan = { id: 2, username: 'Rowan' };
const priya = { id: 3, username: 'Priya' };
const jonas = { id: 4, username: 'Jonas' };
const room = (id, name, extra = {}) => ({
  id, name, key: `room-${id}`, visibility: 'public', member_role: 'member', minimum_age: 0, inactivity_timeout_seconds: 0,
  info_line: '', unread_count: 0, guest_access: 'none', last_message_at: at(id * 7), last_activity_at: at(id * 7), active_count: id === 1 ? 3 : 0, ...extra,
});
const rooms = [
  room(1, 'The living room', { info_line: 'Everyday talk, all welcome.', unread_count: 0 }),
  room(4, 'Board games night', { info_line: 'Fridays at eight.', unread_count: 3 }),
  room(5, 'Garden', { unread_count: 1 }),
  room(6, 'Book club', { member_role: null, info_line: 'This month: The Left Hand of Darkness.' }),
  room(7, 'Quiet corner', { member_role: null }),
];
let id = 100;
const say = (sender, body, minutes, extra = {}) => ({
  id: id++, room_id: 1, sender_id: sender.id, user_id: sender.id, username: sender.username, body, type: 'text', deleted: false,
  mentions: [], reactions: [], reply_to: null, attachment: null, created_at: at(minutes), ...extra,
});
const history = [
  say(rowan, 'Morning, all. Is anyone up for a walk later?', 95),
  say(rowan, 'The weather looks good until about four.', 94),
  say(priya, 'Count me in. Two-ish?', 80, { reactions: [{ emoji: '👍', users: [rowan, jonas] }] }),
  say(jamie, 'Two works for me too. I’ll bring a flask of tea. Route: https://example.org/walk', 62),
  say(jonas, 'I can only make it for an hour, but I’ll join you at the bridge.', 41),
  say(rowan, 'Perfect. Bridge at two, then. 🌤', 12),
];
const dms = [
  { id: 900, body: 'Did you get the book back from Priya?', created_at: at(300), sender: rowan, recipient: jamie, outgoing: false },
  { id: 901, body: 'Yes, last night. Want to borrow it next? https://example.org/book', created_at: at(280), sender: jamie, recipient: rowan, outgoing: true },
  { id: 902, body: 'Please! I’ll pick it up on Friday.', created_at: at(275), sender: rowan, recipient: jamie, outgoing: false },
].map((message) => ({ ...message, mentions: [], reactions: [], reply_to: null, read_at: null }));
const notifications = [
  { id: 60, kind: 'mentioned', title: 'You were mentioned', message: 'Jonas mentioned you in “Board games night”.', details: [], link: '/?room_id=4&message_id=77', read: false, created_at: at(30) },
  { id: 59, kind: 'pinged', title: 'Rowan pinged you', message: 'Rowan pinged you in “Garden”.', details: [], link: '/?room_id=5&ping_id=3', read: false, created_at: at(90) },
  { id: 58, kind: 'direct_message_received', title: 'New direct message', message: 'Rowan sent you a direct message.', details: [], link: '/messages.php?with=2', read: true, created_at: at(275) },
];
const results = [
  { kind: 'room', message_id: 101, excerpt: 'The weather looks good until about four.', excerpt_matches: [{ start: 4, length: 7 }], sender: rowan, room: { id: 1, key: 'room-1', name: 'The living room' }, peer: null, outgoing: null, created_at: at(94), edited_at: null },
  { kind: 'direct', message_id: 900, excerpt: 'Is the weather holding for Friday?', excerpt_matches: [{ start: 7, length: 7 }], sender: rowan, room: null, peer: rowan, outgoing: false, created_at: at(2000), edited_at: null },
];
const settings = {
  registration_enabled: true, guest_access_enabled: false, mfa_required_for_admin_roles: false,
  audit_retention_days: 0, deleted_attachment_retention_days: 0, deleted_room_grace_days: 30,
  direct_message_retention_days: 0, login_attempt_retention_days: 30, orphan_attachment_grace_hours: 24,
  realtime_event_retention_hours: 24, room_message_retention_days: 0, updated_at: '2026-11-01T10:00:00Z', username_reservation_days: 365,
};
const SYSTEM_STATUS = {
  generated_at: at(120),
  application: { name: 'ChitChat', version: '3.14.0', environment: 'production' },
  database: { name: 'chitchat', size_bytes: 52428800, query_latency_ms: 0.412, lock_manager_waits: 0, lock_manager_waits_scope: 'all_roles' },
  realtime: { active_sse_connections: 4, active_sse_users: 3, active_presence_leases: 3, active_presence_users: 3, retained_events: 1200 },
  attachments: { active_files: 18, deleted_files: 2, tracked_bytes: 7340032, storage_available: true, disk_free_bytes: 10737418240, disk_total_bytes: 21474836480, disk_used_percent: 50 },
  security: {
    failed_logins_24h: 1, rate_limit_rows: 6,
    rate_limit_policies: { login: { maximum_attempts: 10, window_seconds: 900 }, register: { maximum_attempts: 5, window_seconds: 3600 } },
    rate_limit_decisions: [{ policy: 'login', allowed: 40, rejected: 1, last_rejected_at: at(180) }],
  },
  maintenance: {
    overdue: false, maximum_age_hours: 26, latest_success_age_seconds: 3600,
    latest_run: { status: 'succeeded', dry_run: false, finished_at: at(60), duration_ms: 812, result: null, error_message: null },
    latest_successful_destructive_run: { finished_at: at(60) },
  },
  metrics: { enabled: false },
};
const adminRoom = { ...room(4, 'Garden', { info_line: 'Seeds, soil and patience.' }), member_role: null, can_take_ownership: true };
const personCase = {
  id: 42, subject_kind: 'user', status: 'open', report_count: 2, subject: { id: 2, username: 'Rowan' },
  room: { id: 1, name: 'The living room' }, first_reported_at: at(600), last_reported_at: at(500), assigned_to: null,
};
const messageCase = {
  id: 41, subject_kind: 'message', status: 'open', report_count: 1, subject: { id: 2, username: 'Rowan' },
  message_kind: 'room', message_id: 1, room: { id: 1, name: 'The living room' }, first_reported_at: at(900), last_reported_at: at(900), assigned_to: null,
};
const privacy = {
  direct_messages: { admin_inspection_enabled: true, admin_inspection_role: 'super_admin' },
  message_revisions: { admin_review_enabled: true, admin_review_role: 'super_admin' },
};

async function fixture(page, { user = jamie, emptyRoom = false, overrides = {} } = {}) {
  await page.clock.setFixedTime(NOW);
  await page.route('**/installation-icon.php*', (route) => route.fulfill({ contentType: 'image/png', body: PNG }));
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path === 'admin/settings/totp/get.php') return route.fulfill({ status: 403, json: { error: { code: 'forbidden', message: 'Forbidden.' } } });
    if (path in overrides) {
      const value = typeof overrides[path] === 'function' ? overrides[path](request) : overrides[path];
      if (Number.isInteger(value?.status)) return route.fulfill({ status: value.status, json: value.json });
      return route.fulfill({ json: value });
    }
    const responses = {
      'session.php': {
        user, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], guest_access: true, privacy,
        capabilities: { totp: true, signed_in_sessions: true },
      },
      'registration-challenge.php': { challenge: null },
      'password-reset/challenge.php': { challenge: null },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages: emptyRoom || url.searchParams.get('room_id') !== '1' ? [] : structuredClone(history) },
      'rooms/message-mutations.php': { messages: history.map((m) => ({ ...m, edited_at: null, can_edit: m.sender_id === 1, can_delete: m.sender_id === 1, can_report: m.sender_id !== 1, can_moderate: false })) },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [{ id: 1, username: 'Jamie', idle_seconds: 0 }, { id: 2, username: 'Rowan', idle_seconds: 0 }, { id: 3, username: 'Priya', idle_seconds: 0 }] },
      'rooms/mentionable-users.php': { users: [rowan, priya, jonas] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 105 },
      'account/notifications/list.php': { notifications, unread_count: 2 },
      'direct-messages/conversations.php': { conversations: [
        { user: rowan, unread_count: 0, last_message: { body: dms[2].body, outgoing: false, created_at: dms[2].created_at } },
        { user: priya, unread_count: 1, last_message: { body: 'See you at two!', outgoing: false, created_at: at(70) } },
      ] },
      'direct-messages/block-status.php': { relationship: { blocked_by_me: false, messaging_available: true } },
      'direct-messages/history.php': { messages: dms },
      'direct-messages/read.php': {},
      'direct-messages/attachments/metadata.php': { attachments: [] },
      'direct-messages/message-mutations.php': { messages: [] },
      'search/messages.php': { results, has_more: false, next_offset: null },
      'users/profile.php': url.searchParams.get('user_id') === '2'
        ? { profile: { id: 2, username: 'Rowan', member_since: '2026-07-01T10:00:00Z', badge: null, has_avatar: false, avatar_version: null, can_remove_avatar: false, ignored: false, can_report: true, moderation: null }, avatars_available: true }
        : { profile: { id: 1, username: 'Jamie', member_since: '2025-03-14T09:00:00Z', has_avatar: false }, avatars_available: true },
      'account/mfa/status.php': { mfa: { enabled: false, available: true, credentials: [], recovery_codes_remaining: 0, usable_methods: [], totp: { enabled: false, available: true, created_at: null, last_used_at: null } } },
      'account/identities/list.php': { providers: [], identities: [], has_password: true },
      'account/sessions/list.php': { sessions: [
        { id: 'a', current: true, created_at: at(3000), last_seen_at: at(1), user_agent_summary: 'Firefox on Windows', client: 'Firefox on Windows', kind: 'web' },
        { id: 'b', current: false, created_at: at(9000), last_seen_at: at(700), user_agent_summary: 'ChitChat Mobile on Android', client: 'ChitChat Mobile on Android', kind: 'native' },
      ], maximum_sessions: 50 },
      'push/preferences.php': { preferences: { mentioned_push_enabled: true, pinged_push_enabled: true, direct_message_push_enabled: false, quiet_hours: null }, devices: [] },
      'policies.php': { policies: { rules: { source: 'none', version: 0 }, privacy: { source: 'none' } } },
      // stewardship
      'rooms/deleted.php': { rooms: [{ id: 9, name: 'Attic', deleted_at: at(5000), purge_after: at(-40000) }] },
      'admin/rooms/snapshot.php': { room: adminRoom, members: [
        { id: 5, username: 'Morgan', role: 'owner', joined_at: at(400000), active_connections: 1, can_transfer_ownership: false },
        { id: 2, username: 'Rowan', role: 'moderator', joined_at: at(300000), active_connections: 0, can_transfer_ownership: true },
        { id: 3, username: 'Priya', role: 'member', joined_at: at(200000), active_connections: 2, can_transfer_ownership: false },
      ], invitations: [{ id: 6, username: 'Sam', invited_by_username: 'Morgan', created_at: at(4000) }] },
      'admin/rooms/search-users.php': { users: [{ id: 7, username: 'Kit' }] },
      'admin/users.php': { users: [
        { ...rowan, roles: [], created_at: at(300000), last_login_at: at(900), active_ban: null },
        { ...priya, roles: ['global_moderator'], created_at: at(200000), last_login_at: null, active_ban: { expires_at: at(-9000) } },
      ] },
      'admin/audit.php': { entries: url.searchParams.has('before_id') ? [] : Array.from({ length: 12 }, (_, index) => ({
        id: 900 - index, action: index === 0 ? 'room.ownership_transferred' : 'room.updated', created_at: at(60 + index * 45),
        actor_username: 'Jamie', subject_type: 'room', subject_id: 4, ip_address: '192.0.2.10', metadata: index === 0 ? { from_user_id: 5, to_user_id: 2 } : {},
      })) },
      'step-up.php': { verified: true },
      'admin/settings/get.php': { settings },
      'admin/settings/lockdown/get.php': { lockdown: { enabled: false, custom_message: null, since: null } },
      'admin/system-status.php': { status: SYSTEM_STATUS },
      'admin/settings/application-name/get.php': { application_name: { default: 'ChitChat', override: null } },
      'admin/settings/registration-protection/get.php': { registration_protection: { overrides: { rate_limit_max_attempts: null, rate_limit_window_seconds: null, min_fill_seconds: null, proof_of_work_bits: null }, defaults: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 }, effective: { rate_limit_max_attempts: 5, rate_limit_window_seconds: 3600, min_fill_seconds: 3, proof_of_work_bits: 16 } } },
      'admin/policies.php': { policies: { rules: { source: 'none', markdown: '', url: null, version: 0, updated_at: null }, privacy: { source: 'none', markdown: '', url: null, version: 0, updated_at: null }, legal_notice: { url: null } } },
      'admin/settings/sign-in-decoration/get.php': { sign_in_decoration: { value: 'off', options: ['off', 'winter', 'spring', 'celebration'] } },
      'admin/settings/accessibility-feedback/get.php': { accessibility_feedback: { enabled: true, url: null, default_url: 'https://github.com/Thiesi/ChitChat/issues/new?template=accessibility.yml', effective_url: 'https://github.com/Thiesi/ChitChat/issues/new?template=accessibility.yml' } },
      'moderation/cases.php': { cases: [personCase, messageCase], has_more: false, next_before_id: null },
      'moderation/case.php': { case: {
        ...personCase, snapshot: { username: 'Rowan_x', avatar_url: null }, avatar_changed_since_report: false, subject: { id: 2, username: 'Rowan' },
        reports: [
          { reporter: { id: 1, username: 'Jamie' }, category: 'offensive_username', details: 'The name is a slur.', created_at: at(600) },
          { reporter: { id: 4, username: 'Alex' }, category: 'offensive_profile_picture', details: null, created_at: at(500) },
        ],
      } },
      'admin/direct-messages/users.php': { users: [rowan] },
      'admin/message-revisions/review.php': {
        kind: 'room',
        message: { id: 77, room: { id: 4, key: 'garden', name: 'Garden' }, author: rowan, message_type: 'text', created_at: at(5000), edited_at: at(4995), last_editor: rowan, deleted_at: null, deleted_by: null },
        revisions: [{ id: 501, action: 'edit', actor: rowan, message_type: 'text', created_at: at(4995), body_before: 'Seeds on Friday', body_after: 'Seeds on Saturday' }],
      },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
}

const phone = (page) => (page.viewportSize()?.width ?? 1280) < 768;
const openRoom = async (page, name = 'The living room') => {
  if (phone(page)) await page.locator('#room-list .room-button', { hasText: name }).click();
  await expect(page.locator('#message-list article.message')).toHaveCount(6);
};
const youReady = (page) => expect(page.locator('#account-loading')).toBeHidden();
const youSection = (hash) => ({
  name: `you-${hash}`, path: `/account.php#${hash}`, ready: async (page) => { await youReady(page); await expect(page.locator(`#you-section-${hash}`)).toBeVisible(); },
});

const SCREENS = [
  { name: 'sign-in', path: '/', user: null, ready: (page) => expect(page.locator('#auth-greeting')).toBeVisible() },
  { name: 'register', path: '/?register=1', user: null, ready: (page) => expect(page.getByRole('button', { name: 'Create account' })).toBeVisible() },
  { name: 'password-reset', path: '/?reset=1', user: null, ready: (page) => expect(page.getByRole('button', { name: 'Set new password' })).toBeVisible() },
  {
    name: 'sign-in-error', path: '/', user: null,
    overrides: { 'login.php': { status: 401, json: { error: { code: 'invalid_credentials', message: 'Wrong username or password.' } } } },
    ready: async (page) => {
      await page.locator('#login-form input[name="username"], #login-username').first().fill('Jamie');
      await page.locator('#login-form input[type="password"]').first().fill('not-my-password');
      await page.locator('#login-form button[type="submit"]').click();
      await expect(page.locator('#auth-error')).not.toBeEmpty();
    },
  },
  {
    name: 'mfa-sign-in', path: '/', user: null,
    overrides: { 'login.php': { mfa_required: true, methods: ['passkey', 'recovery_code', 'totp'], csrf_token: 'pending' } },
    ready: async (page) => {
      await page.locator('#login-form input[name="username"], #login-username').first().fill('Jamie');
      await page.locator('#login-form input[type="password"]').first().fill('a-long-password');
      await page.locator('#login-form button[type="submit"]').click();
      await expect(page.locator('#mfa-login-panel')).toBeVisible();
    },
  },
  { name: 'rooms-list', path: '/', ready: (page) => expect(page.locator('#room-list .room-button').first()).toBeVisible() },
  { name: 'room-conversation', path: '/', ready: (page) => openRoom(page) },
  {
    name: 'room-empty', path: '/', emptyRoom: true,
    ready: async (page) => {
      await page.locator('#room-list .room-button', { hasText: 'Garden' }).click();
      await expect(page.locator('#empty-state')).toContainText('Say hello');
    },
  },
  {
    name: 'room-composing', path: '/',
    ready: async (page) => {
      await openRoom(page);
      await page.locator('#composer-input').fill('See you at the bridge. I’ll bring the tea and maybe some of those ginger biscuits.');
    },
  },
  {
    name: 'members-panel', path: '/',
    ready: async (page) => { await openRoom(page); await page.locator('#members-toggle').click(); await page.waitForTimeout(400); },
  },
  {
    name: 'leave-sheet', path: '/',
    ready: async (page) => {
      await openRoom(page);
      await page.locator('#members-toggle').click();
      await page.getByRole('button', { name: /^Leave/ }).click();
      await expect(page.getByRole('dialog')).toBeVisible();
    },
  },
  {
    name: 'name-menu', path: '/',
    ready: async (page) => {
      await openRoom(page);
      await page.locator('#message-list .user-name[data-user-id="2"]').first().click();
      await page.waitForTimeout(500);
    },
  },
  {
    name: 'emoji-picker', path: '/',
    ready: async (page) => {
      await openRoom(page);
      await page.locator('#emoji-button').first().click();
      await page.waitForTimeout(500);
    },
  },
  {
    name: 'message-menu', path: '/',
    ready: async (page) => {
      await openRoom(page);
      const message = page.locator('#message-list article.message').nth(4);
      await message.hover();
      await message.locator('.message-more-button').click();
      await page.waitForTimeout(400);
    },
  },
  {
    name: 'toast', path: '/admin-message-revisions.php', user: root,
    ready: async (page) => {
      await page.waitForLoadState('networkidle');
      await page.evaluate(() => { const r = document.getElementById('toast-region'); for (const [k, t] of [['info', 'Legal notice link saved.'], ['warning', 'Revision review audited for 1 retained revision.'], ['error', 'That didn’t save. Try again.']]) { const n = document.createElement('div'); n.className = 'toast ' + k; n.textContent = t; r.append(n); } });
    },
  },
  {
    name: 'direct', path: '/messages.php?user=2',
    ready: async (page) => {
      if (phone(page)) await page.locator('#dm-conversation-list .conversation-button').first().click();
      await expect(page.locator('#dm-message-list article.dm-message')).toHaveCount(3);
    },
  },
  { name: 'direct-list', path: '/messages.php', ready: (page) => expect(page.locator('#dm-conversation-list .conversation-button').first()).toBeVisible() },
  { name: 'activity', path: '/notifications.php', ready: (page) => expect(page.locator('#privacy-notifications-status')).toContainText('unread') },
  {
    name: 'activity-empty', path: '/notifications.php', overrides: { 'account/notifications/list.php': { notifications: [], unread_count: 0 } },
    ready: (page) => page.waitForTimeout(800),
  },
  {
    name: 'search', path: '/search.php',
    ready: async (page) => {
      await page.locator('#message-search-query').fill('weather');
      await page.getByRole('button', { name: 'Search', exact: true }).click();
      await expect(page.locator('#message-search-results > li')).toHaveCount(2);
    },
  },
  { name: 'search-empty', path: '/search.php', ready: (page) => expect(page.locator('#message-search-query')).toBeVisible() },
  { name: 'you', path: '/account.php', ready: youReady },
  youSection('profile'), youSection('alerts'), youSection('appearance'), youSection('security'), youSection('privacy'), youSection('account'),
  { name: 'admin', path: '/admin.php', user: root, full: true, ready: (page) => expect(page.locator('#admin-shell')).toBeVisible() },
  {
    name: 'admin-rooms', path: '/admin.php', user: root, full: true,
    ready: async (page) => {
      await expect(page.locator('#admin-shell')).toBeVisible();
      await page.locator('#admin-tabs').getByRole('button', { name: 'Rooms' }).click();
      await expect(page.locator('#room-member-list')).toContainText('Rowan');
    },
  },
  { name: 'admin-settings', path: '/admin-settings.php', user: root, full: true, ready: (page) => expect(page.locator('#settings-shell')).toBeVisible() },
  { name: 'admin-status', path: '/admin-status.php', user: root, full: true, ready: async (page) => { await page.waitForLoadState('networkidle'); await page.waitForTimeout(500); } },
  { name: 'moderation', path: '/moderation.php', user: root, full: true, ready: (page) => expect(page.locator('.moderation-case-button').first()).toBeVisible() },
  { name: 'dm-inspection', path: '/admin-messages.php', user: root, full: true, ready: async (page) => { await page.waitForLoadState('networkidle'); await page.waitForTimeout(400); } },
  { name: 'revision-review', path: '/admin-message-revisions.php', user: root, full: true, ready: async (page) => { await page.waitForLoadState('networkidle'); await page.waitForTimeout(400); } },
  { name: 'rules-unpublished', path: '/rules.php', user: null, ready: (page) => expect(page.getByRole('heading', { level: 1 })).toBeVisible() },
  { name: 'privacy-unpublished', path: '/privacy.php', user: null, ready: (page) => expect(page.getByRole('heading', { level: 1 })).toBeVisible() },
  { name: 'not-found', path: '/no-such-page', ready: (page) => expect(page.locator('main.status-page')).toBeVisible() },
];

const THEMES = process.env.FABLE_THEMES ? process.env.FABLE_THEMES.split(',').map((t) => t.split('/')) : [['lantern', 'light'], ['lantern', 'dark']];
const AXE = Boolean(process.env.FABLE_AXE);
const SIZES = [[1280, 900, '1280'], [360, 780, '360']];

for (const screen of SCREENS) {
  if (ONLY.length && !ONLY.includes(screen.name)) continue;
  test(`capture ${screen.name}`, async ({ browser }) => {
    test.setTimeout(240_000);
    const failures = [];
    for (const [scheme, theme] of THEMES) {
      for (const [width, height, label] of SIZES) {
        const context = await browser.newContext({
          viewport: { width, height }, deviceScaleFactor: 1, colorScheme: theme, reducedMotion: 'reduce', serviceWorkers: 'block',
        });
        const page = await context.newPage();
        page.setDefaultTimeout(8000);
        try {
          await page.addInitScript(([chosenScheme, chosenTheme]) => {
            localStorage.setItem('chitchat-theme', chosenTheme);
            localStorage.setItem('chitchat-scheme', chosenScheme);
            localStorage.setItem('chitchat-new-look-seen', '1');
          }, [scheme, theme]);
          await fixture(page, { user: screen.user === undefined ? jamie : screen.user, emptyRoom: screen.emptyRoom, overrides: screen.overrides });
          await page.goto(screen.path);
          await screen.ready(page);
          await page.mouse.move(0, 0);
          await page.evaluate(() => document.fonts.ready);
          await page.waitForTimeout(400);
          await page.screenshot({ path: `${OUT}/${screen.name}--${label}--${scheme}-${theme}.png`, fullPage: Boolean(screen.full) });
          if (AXE) {
            const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
            for (const v of result.violations) failures.push(`AXE ${label} ${scheme}/${theme}: ${v.id} ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
          }
        } catch (error) {
          failures.push(`${label} ${theme}: ${String(error.message).split('\n')[0]}`);
          await page.screenshot({ path: `${OUT}/_failed--${screen.name}--${label}--${scheme}-${theme}.png` }).catch(() => {});
        }
        await context.close();
      }
    }
    expect(failures, failures.join(' | ')).toEqual([]);
  });
}
