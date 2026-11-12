// The signed-in shell (S2, #168; markup in src/View/AppShell.php). The rail
// on wide screens and the bottom bar on phones are one navigation landmark.
// This module shows it once the session is known, decides who sees what, and
// fills the Activity badge. It adds no endpoint: the badge uses the same
// summary request the old bell used, and only on pages whose own scripts
// don't already report the count (they send `chitchat:activity-count`).

import { apiGet } from './api.js';
import { isGuestName } from './guest.js';

const ADMIN_ROLES = ['super_admin', 'admin', 'chat_admin'];
const STEWARD_CACHE = 'chitchat-steward';

// Each start() supersedes an earlier one still waiting for the session.
let generation = 0;

const frame = document.querySelector('.app-frame');
const nav = document.getElementById('app-nav');

if (frame && nav) {
  window.addEventListener('chitchat:activity-count', (event) => renderActivity(event.detail?.count));
  // Direct messages (S2c): the Direct page reports exact counts; elsewhere a new DM refreshes them.
  window.addEventListener('chitchat:direct-count', (event) => renderBadge('direct', event.detail?.count));
  window.addEventListener('chitchat:realtime', (event) => {
    if (event.detail?.type === 'direct_message' && !directIsPage()) scheduleDirect();
  });
  // Signing in, out or starting a guest visit happens in place on the Rooms page.
  window.addEventListener('chitchat:session-changed', () => { void start(); });
  watchKeyboard();
  void start();
}

async function start() {
  const run = ++generation;
  let session;
  try {
    session = await apiGet('/api/v1/session.php');
  } catch {
    return;
  }
  if (run !== generation) return;
  const user = session?.user;
  if (!user) {
    nav.hidden = true;
    frame.classList.remove('has-nav');
    return;
  }

  const guest = user.guest === true || isGuestName(user.username);
  // Guests look around without an account: Direct and Activity are for members; You is short.
  for (const entry of nav.querySelectorAll('[data-members-only]')) entry.hidden = guest;
  nav.hidden = false;
  frame.classList.add('has-nav');
  if (guest) return;

  if (frame.dataset.activitySource !== 'page') void refreshActivity();
  if (!directIsPage()) void refreshDirect();
  await placeSteward(user);
}

async function refreshActivity() {
  try {
    const payload = await apiGet('/api/v1/account/notifications/list.php?limit=1');
    renderActivity(payload?.unread_count);
  } catch {
    // The badge stays empty; the Activity page itself shows any error.
  }
}

function renderActivity(count) {
  renderBadge('activity', count);
}

let directTimer = 0;

/** The Direct page counts its own conversations and reports them. */
function directIsPage() {
  return frame.dataset.tab === 'direct';
}

function scheduleDirect() {
  window.clearTimeout(directTimer);
  directTimer = window.setTimeout(() => void refreshDirect(), 300);
}

async function refreshDirect() {
  try {
    const payload = await apiGet('/api/v1/direct-messages/conversations.php');
    const conversations = Array.isArray(payload?.conversations) ? payload.conversations : [];
    renderBadge('direct', conversations.reduce((sum, item) => sum + (Number.isInteger(item?.unread_count) ? item.unread_count : 0), 0));
  } catch {
    // Without a count the tab simply shows no badge.
  }
}

/** A tab's unread badge, read as part of its name ("Direct, 3 unread"). */
function renderBadge(key, count) {
  const unread = Number.isInteger(count) && count > 0 ? count : 0;
  const link = nav.querySelector(`.app-nav-item[data-tab="${key}"]`);
  const badge = link?.querySelector(`[data-badge="${key}"]`);
  const spoken = link?.querySelector(`[data-badge-label="${key}"]`);
  if (!link || !badge) return;
  badge.textContent = unread > 99 ? '99+' : String(unread);
  badge.hidden = unread === 0;
  // Read after the visible label: "Activity, 3 unread" (no aria-label, so the name keeps the visible text).
  if (spoken) spoken.textContent = unread === 0 ? '' : `, ${unread} unread`;
}

/**
 * Stewarding: administrators and room owners go to administration, as the old
 * Administration link did; moderators without an admin area go to the
 * moderation queue instead of a dead end. Room roles need the room list, so
 * the answer is kept for this tab's session and refreshed on the Rooms page,
 * which loads rooms anyway.
 */
async function placeSteward(user) {
  const roles = Array.isArray(user.roles) ? user.roles : [];
  let destination = null;
  if (roles.some((role) => ADMIN_ROLES.includes(role))) {
    destination = '/admin.php';
  } else {
    const key = `${STEWARD_CACHE}:${user.id}`;
    const cached = readCache(key);
    let fromRooms;
    if (cached !== null && frame.dataset.tab !== 'rooms') {
      fromRooms = cached || null;
    } else {
      fromRooms = await roomStewardship();
      if (fromRooms === undefined) fromRooms = cached || null;
      else writeCache(key, fromRooms ?? '');
    }
    // A room owner has an admin area; otherwise a moderator (room or global) gets the queue.
    destination = fromRooms ?? (roles.includes('global_moderator') ? '/moderation.php' : null);
  }

  const holder = nav.querySelector('.app-nav-steward');
  const link = holder?.querySelector('a');
  if (holder && link) {
    holder.hidden = destination === null;
    if (destination) link.href = destination;
  }
  // On You, the same way in; it is the only one on phones, where the bar has no Stewarding.
  const card = document.getElementById('you-steward-card');
  const cardLink = document.getElementById('you-steward-link');
  if (card && cardLink) {
    card.classList.toggle('hidden', destination === null);
    if (destination) cardLink.href = destination;
  }
}

/** '/admin.php' for a room owner, '/moderation.php' for a room moderator, null for neither, undefined if unknown. */
async function roomStewardship() {
  try {
    const response = await apiGet('/api/v1/rooms/list.php');
    const rooms = Array.isArray(response?.rooms) ? response.rooms : [];
    if (rooms.some((room) => room.member_role === 'owner')) return '/admin.php';
    if (rooms.some((room) => room.member_role === 'moderator')) return '/moderation.php';
    return null;
  } catch {
    return undefined;
  }
}

function readCache(key) {
  try {
    return window.sessionStorage.getItem(key);
  } catch {
    return null;
  }
}

function writeCache(key, value) {
  try {
    window.sessionStorage.setItem(key, value);
  } catch {
    // Without storage the room list is asked again on the next page.
  }
}

/**
 * The bottom bar leaves while the on-screen keyboard is up, so the composer
 * keeps its room. CSS covers a focused text field; this covers keyboards that
 * stay open without one (the visual viewport shrinks well below the window).
 */
function watchKeyboard() {
  const viewport = window.visualViewport;
  if (!viewport) return;
  const update = () => {
    frame.toggleAttribute('data-keyboard', viewport.height < window.innerHeight * 0.75);
  };
  viewport.addEventListener('resize', update);
  update();
}
