import { ApiError, apiGet, apiPost, setCsrfToken } from './api.js';

const GLOBAL_ROLES = ['super_admin', 'admin', 'chat_admin', 'global_moderator'];
const state = {
  user: null,
  rooms: [],
  manageableRooms: [],
  users: [],
  userSearch: '',
  userCursor: 0,
  selectedUser: null,
  roomSnapshot: null,
  auditEntries: [],
  auditBefore: null,
};
const elements = {};

window.addEventListener('DOMContentLoaded', () => {
  bindElements();
  bindEvents();
  bootstrap().catch(handleFatalError);
});

function bindElements() {
  for (const id of [
    'admin-loading',
    'admin-shell',
    'admin-identity',
    'admin-tabs',
    'users-tab',
    'rooms-tab',
    'audit-tab',
    'admin-error',
    'users-panel',
    'rooms-panel',
    'audit-panel',
    'user-search-form',
    'user-search',
    'user-list',
    'users-more',
    'room-picker',
    'room-admin-empty',
    'room-admin-content',
    'room-settings-form',
    'admin-room-name',
    'admin-room-info',
    'admin-room-visibility',
    'admin-room-age',
    'admin-room-timeout',
    'room-member-list',
    'invitation-search-form',
    'invitation-search',
    'invitation-search-results',
    'room-invitation-list',
    'audit-list',
    'audit-more',
    'user-dialog',
    'user-admin-form',
    'user-dialog-title',
    'user-dialog-status',
    'user-dialog-close',
    'global-role-fieldset',
    'save-global-roles',
    'moderation-reason',
    'ban-expiry',
    'kick-user',
    'ban-user',
    'unban-user',
    'admin-new-password',
    'reset-user-password',
    'toast-region',
  ]) {
    const element = document.getElementById(id);
    if (!element) {
      throw new Error(`Missing administration element: ${id}`);
    }
    elements[id] = element;
  }
}

function bindEvents() {
  for (const tab of elements['admin-tabs'].querySelectorAll('button[data-panel]')) {
    tab.addEventListener('click', () => activatePanel(tab.dataset.panel));
  }
  elements['user-search-form'].addEventListener('submit', async (event) => {
    event.preventDefault();
    state.userSearch = elements['user-search'].value.trim();
    await loadUsers(true);
  });
  elements['users-more'].addEventListener('click', () => loadUsers(false));
  elements['room-picker'].addEventListener('change', () => loadRoomSnapshot(Number(elements['room-picker'].value)));
  elements['room-settings-form'].addEventListener('submit', saveRoomSettings);
  elements['invitation-search-form'].addEventListener('submit', searchInvitableUsers);
  elements['audit-more'].addEventListener('click', () => loadAudit(false));
  elements['user-dialog-close'].addEventListener('click', () => elements['user-dialog'].close());
  elements['save-global-roles'].addEventListener('click', saveGlobalRoles);
  elements['kick-user'].addEventListener('click', kickSelectedUser);
  elements['ban-user'].addEventListener('click', banSelectedUser);
  elements['unban-user'].addEventListener('click', unbanSelectedUser);
  elements['reset-user-password'].addEventListener('click', resetSelectedPassword);
}

async function bootstrap() {
  const session = await apiGet('/api/v1/session.php');
  setCsrfToken(session.csrf_token);
  if (!session.user) {
    window.location.replace('/');
    return;
  }

  state.user = session.user;
  elements['admin-identity'].textContent = `Signed in as ${state.user.username}`;
  const roomsResponse = await apiGet('/api/v1/rooms/list.php');
  state.rooms = Array.isArray(roomsResponse.rooms) ? roomsResponse.rooms : [];
  state.manageableRooms = state.rooms.filter(canManageRoom);

  const userAdmin = canManageUsers();
  elements['users-tab'].classList.toggle('hidden', !userAdmin);
  elements['audit-tab'].classList.toggle('hidden', !userAdmin);
  elements['rooms-tab'].classList.toggle('hidden', state.manageableRooms.length === 0);

  const firstPanel = userAdmin
    ? 'users-panel'
    : state.manageableRooms.length > 0
      ? 'rooms-panel'
      : null;

  elements['admin-loading'].classList.add('hidden');
  elements['admin-shell'].classList.remove('hidden');
  if (!firstPanel) {
    showError('Your account has no administration areas available.');
    return;
  }

  populateRoomPicker();
  await activatePanel(firstPanel);
}

async function activatePanel(panelId) {
  clearError();
  for (const panel of elements['admin-shell'].querySelectorAll('.admin-panel')) {
    panel.classList.toggle('hidden', panel.id !== panelId);
  }
  for (const tab of elements['admin-tabs'].querySelectorAll('button[data-panel]')) {
    tab.setAttribute('aria-selected', String(tab.dataset.panel === panelId));
  }

  try {
    if (panelId === 'users-panel' && state.users.length === 0) {
      await loadUsers(true);
    } else if (panelId === 'rooms-panel' && state.manageableRooms.length > 0 && !state.roomSnapshot) {
      await loadRoomSnapshot(state.manageableRooms[0].id);
    } else if (panelId === 'audit-panel' && state.auditEntries.length === 0) {
      await loadAudit(true);
    }
  } catch (error) {
    handleFailure(error);
  }
}

async function loadUsers(reset) {
  const button = reset ? null : elements['users-more'];
  if (button) button.disabled = true;
  try {
    const parameters = new URLSearchParams({ limit: '50' });
    if (state.userSearch) parameters.set('search', state.userSearch);
    if (!reset && state.userCursor > 0) parameters.set('after_id', String(state.userCursor));
    const response = await apiGet(`/api/v1/admin/users.php?${parameters}`);
    const users = Array.isArray(response.users) ? response.users : [];
    state.users = reset ? users : [...state.users, ...users];
    state.userCursor = state.users.at(-1)?.id ?? 0;
    renderUsers();
    elements['users-more'].classList.toggle('hidden', users.length < 50);
  } finally {
    if (button) button.disabled = false;
  }
}

function renderUsers() {
  elements['user-list'].replaceChildren();
  if (state.users.length === 0) {
    elements['user-list'].append(emptyItem('No matching accounts.'));
    return;
  }

  for (const user of state.users) {
    const item = document.createElement('article');
    item.className = 'admin-list-item';
    const header = document.createElement('div');
    header.className = 'admin-card-header';
    const title = document.createElement('h3');
    title.textContent = `${user.username} · #${user.id}`;
    const manage = document.createElement('button');
    manage.type = 'button';
    manage.className = 'secondary-button';
    manage.textContent = 'Manage';
    manage.addEventListener('click', () => openUserDialog(user));
    header.append(title, manage);

    const badges = document.createElement('div');
    badges.className = 'role-badges';
    for (const role of user.roles ?? []) badges.append(badge(role));
    if (user.active_ban) badges.append(badge('banned', true));
    if (badges.childElementCount === 0) badges.append(badge('standard user'));

    const meta = document.createElement('p');
    meta.className = 'admin-card-meta';
    const details = [`created ${formatDateTime(user.created_at)}`];
    if (user.last_login_at) details.push(`last login ${formatDateTime(user.last_login_at)}`);
    if (user.active_ban) {
      details.push(user.active_ban.expires_at
        ? `ban expires ${formatDateTime(user.active_ban.expires_at)}`
        : 'indefinite ban');
    }
    meta.textContent = details.join(' · ');
    item.append(header, badges, meta);
    elements['user-list'].append(item);
  }
}

function openUserDialog(user) {
  state.selectedUser = user;
  elements['user-dialog-title'].textContent = `Manage ${user.username}`;
  elements['user-dialog-status'].textContent = user.active_ban
    ? `Currently banned${user.active_ban.expires_at ? ` until ${formatDateTime(user.active_ban.expires_at)}` : ' indefinitely'}.`
    : 'Account is not currently banned.';
  for (const checkbox of elements['global-role-fieldset'].querySelectorAll('input[name="role"]')) {
    checkbox.checked = user.roles.includes(checkbox.value);
    checkbox.disabled = !canEditSelectedUser()
      || (checkbox.value === 'super_admin' && !hasRole('super_admin'));
  }
  const editable = canEditSelectedUser();
  for (const id of ['save-global-roles', 'kick-user', 'ban-user', 'reset-user-password']) {
    elements[id].disabled = !editable;
  }
  elements['unban-user'].disabled = !editable || !user.active_ban;
  elements['moderation-reason'].value = '';
  elements['ban-expiry'].value = '';
  elements['admin-new-password'].value = '';
  elements['user-dialog'].showModal();
}

async function saveGlobalRoles() {
  const user = requireSelectedUser();
  const roles = [...elements['global-role-fieldset'].querySelectorAll('input[name="role"]:checked')]
    .map((input) => input.value);
  await withButton(elements['save-global-roles'], async () => {
    await apiPost('/api/v1/admin/roles.php', { target_user_id: user.id, roles });
    toast('Roles updated.');
    elements['user-dialog'].close();
    await loadUsers(true);
  });
}

async function kickSelectedUser() {
  const user = requireSelectedUser();
  if (!window.confirm(`Invalidate all active sessions for ${user.username}?`)) return;
  await withButton(elements['kick-user'], async () => {
    await apiPost('/api/v1/admin/kick.php', {
      target_user_id: user.id,
      reason: elements['moderation-reason'].value,
    });
    toast(`${user.username} was kicked.`);
  });
}

async function banSelectedUser() {
  const user = requireSelectedUser();
  if (!window.confirm(`Ban ${user.username} and invalidate active sessions?`)) return;
  const localExpiry = elements['ban-expiry'].value;
  const expiresAt = localExpiry ? new Date(localExpiry).toISOString() : null;
  await withButton(elements['ban-user'], async () => {
    await apiPost('/api/v1/admin/ban.php', {
      target_user_id: user.id,
      reason: elements['moderation-reason'].value,
      expires_at: expiresAt,
    });
    toast(`${user.username} was banned.`);
    elements['user-dialog'].close();
    await loadUsers(true);
  });
}

async function unbanSelectedUser() {
  const user = requireSelectedUser();
  await withButton(elements['unban-user'], async () => {
    await apiPost('/api/v1/admin/unban.php', { target_user_id: user.id });
    toast(`${user.username} was unbanned.`);
    elements['user-dialog'].close();
    await loadUsers(true);
  });
}

async function resetSelectedPassword() {
  const user = requireSelectedUser();
  const password = elements['admin-new-password'].value;
  if (!password || !window.confirm(`Reset ${user.username}'s password and invalidate active sessions?`)) return;
  await withButton(elements['reset-user-password'], async () => {
    await apiPost('/api/v1/admin/reset-password.php', {
      target_user_id: user.id,
      new_password: password,
    });
    elements['admin-new-password'].value = '';
    toast(`${user.username}'s password was reset.`);
  });
}

function populateRoomPicker() {
  elements['room-picker'].replaceChildren();
  for (const room of state.manageableRooms) {
    const option = document.createElement('option');
    option.value = String(room.id);
    option.textContent = `# ${room.name}`;
    elements['room-picker'].append(option);
  }
  const empty = state.manageableRooms.length === 0;
  elements['room-admin-empty'].classList.toggle('hidden', !empty);
  elements['room-admin-content'].classList.toggle('hidden', empty);
}

async function loadRoomSnapshot(roomId) {
  if (!Number.isInteger(roomId) || roomId < 1) return;
  const response = await apiGet(`/api/v1/admin/rooms/snapshot.php?room_id=${roomId}`);
  state.roomSnapshot = response;
  elements['room-picker'].value = String(roomId);
  renderRoomSnapshot();
}

function renderRoomSnapshot() {
  const snapshot = state.roomSnapshot;
  if (!snapshot?.room) return;
  const room = snapshot.room;
  elements['admin-room-name'].value = room.name;
  elements['admin-room-info'].value = room.info_line;
  elements['admin-room-visibility'].value = room.visibility;
  elements['admin-room-age'].value = String(room.minimum_age);
  elements['admin-room-timeout'].value = String(room.inactivity_timeout_seconds);
  renderRoomMembers(snapshot.members ?? []);
  renderRoomInvitations(snapshot.invitations ?? []);
  elements['invitation-search-results'].replaceChildren();
}

function renderRoomMembers(members) {
  elements['room-member-list'].replaceChildren();
  for (const member of members) {
    const item = document.createElement('article');
    item.className = 'admin-list-item';
    const header = document.createElement('div');
    header.className = 'admin-card-header';
    const name = document.createElement('h3');
    name.textContent = member.username;
    header.append(name, badge(member.active_connections > 0 ? `${member.active_connections} online` : 'offline'));
    const meta = document.createElement('p');
    meta.className = 'admin-card-meta';
    meta.textContent = `${member.role} · joined ${formatDateTime(member.joined_at)}`;
    item.append(header, meta);

    if (member.role !== 'owner') {
      const actions = document.createElement('div');
      actions.className = 'admin-row-actions';
      const role = document.createElement('select');
      for (const value of ['member', 'moderator']) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = value;
        option.selected = member.role === value;
        role.append(option);
      }
      const save = actionButton('Save role', async () => {
        await apiPost('/api/v1/rooms/role.php', {
          room_id: state.roomSnapshot.room.id,
          target_user_id: member.id,
          role: role.value,
        });
        toast(`${member.username}'s room role was updated.`);
        await loadRoomSnapshot(state.roomSnapshot.room.id);
      });
      const remove = actionButton('Remove', async () => {
        if (!window.confirm(`Remove ${member.username} from this room?`)) return;
        await apiPost('/api/v1/admin/rooms/remove-member.php', {
          room_id: state.roomSnapshot.room.id,
          target_user_id: member.id,
        });
        toast(`${member.username} was removed from the room.`);
        await loadRoomSnapshot(state.roomSnapshot.room.id);
      }, true);
      actions.append(role, save, remove);
      item.append(actions);
    }
    elements['room-member-list'].append(item);
  }
}

function renderRoomInvitations(invitations) {
  elements['room-invitation-list'].replaceChildren();
  if (invitations.length === 0) {
    elements['room-invitation-list'].append(emptyItem('No pending invitations.'));
    return;
  }
  for (const invitation of invitations) {
    const item = document.createElement('article');
    item.className = 'admin-list-item';
    const header = document.createElement('div');
    header.className = 'admin-card-header';
    const name = document.createElement('h3');
    name.textContent = invitation.username;
    const revoke = actionButton('Revoke', async () => {
      await apiPost('/api/v1/admin/rooms/revoke-invitation.php', {
        room_id: state.roomSnapshot.room.id,
        target_user_id: invitation.id,
      });
      toast(`Invitation for ${invitation.username} was revoked.`);
      await loadRoomSnapshot(state.roomSnapshot.room.id);
    }, true);
    header.append(name, revoke);
    const meta = document.createElement('p');
    meta.className = 'admin-card-meta';
    meta.textContent = `Invited by ${invitation.invited_by_username} · ${formatDateTime(invitation.created_at)}`;
    item.append(header, meta);
    elements['room-invitation-list'].append(item);
  }
}

async function saveRoomSettings(event) {
  event.preventDefault();
  const room = state.roomSnapshot?.room;
  if (!room) return;
  await withButton(event.submitter, async () => {
    const response = await apiPost('/api/v1/rooms/update.php', {
      room_id: room.id,
      name: elements['admin-room-name'].value,
      info_line: elements['admin-room-info'].value,
      visibility: elements['admin-room-visibility'].value,
      minimum_age: Number.parseInt(elements['admin-room-age'].value, 10),
      inactivity_timeout_seconds: Number.parseInt(elements['admin-room-timeout'].value, 10),
    });
    state.roomSnapshot.room = response.room;
    const index = state.manageableRooms.findIndex((candidate) => candidate.id === response.room.id);
    if (index !== -1) state.manageableRooms[index] = response.room;
    populateRoomPicker();
    elements['room-picker'].value = String(response.room.id);
    renderRoomSnapshot();
    toast('Room settings saved.');
  });
}

async function searchInvitableUsers(event) {
  event.preventDefault();
  const room = state.roomSnapshot?.room;
  if (!room) return;
  const parameters = new URLSearchParams({
    room_id: String(room.id),
    search: elements['invitation-search'].value.trim(),
  });
  const response = await apiGet(`/api/v1/admin/rooms/search-users.php?${parameters}`);
  const users = Array.isArray(response.users) ? response.users : [];
  elements['invitation-search-results'].replaceChildren();
  if (users.length === 0) {
    elements['invitation-search-results'].append(emptyItem('No eligible users found.'));
    return;
  }
  for (const user of users) {
    const item = document.createElement('article');
    item.className = 'admin-list-item';
    const header = document.createElement('div');
    header.className = 'admin-card-header';
    const name = document.createElement('h3');
    name.textContent = user.username;
    const invite = actionButton('Invite', async () => {
      await apiPost('/api/v1/rooms/invite.php', {
        room_id: room.id,
        target_user_id: user.id,
      });
      toast(`${user.username} was invited.`);
      await loadRoomSnapshot(room.id);
    });
    header.append(name, invite);
    item.append(header);
    elements['invitation-search-results'].append(item);
  }
}

async function loadAudit(reset) {
  const parameters = new URLSearchParams({ limit: '50' });
  if (!reset && state.auditBefore) parameters.set('before_id', String(state.auditBefore));
  const response = await apiGet(`/api/v1/admin/audit.php?${parameters}`);
  const entries = Array.isArray(response.entries) ? response.entries : [];
  state.auditEntries = reset ? entries : [...state.auditEntries, ...entries];
  state.auditBefore = state.auditEntries.at(-1)?.id ?? null;
  renderAudit();
  elements['audit-more'].classList.toggle('hidden', entries.length < 50);
}

function renderAudit() {
  elements['audit-list'].replaceChildren();
  for (const entry of state.auditEntries) {
    const item = document.createElement('article');
    item.className = 'audit-entry';
    const title = document.createElement('strong');
    title.textContent = entry.action;
    const meta = document.createElement('p');
    meta.className = 'admin-card-meta';
    const actor = entry.actor_username ?? 'system/deleted user';
    const subject = entry.subject_id === null
      ? entry.subject_type
      : `${entry.subject_type} ${entry.subject_id}`;
    meta.textContent = `${formatDateTime(entry.created_at)} · ${actor} · ${subject} · ${entry.ip_address}`;
    const details = document.createElement('pre');
    details.textContent = JSON.stringify(entry.metadata ?? {}, null, 2);
    item.append(title, meta, details);
    elements['audit-list'].append(item);
  }
}

function canManageUsers() {
  return hasRole('super_admin') || hasRole('admin');
}

function canManageRoom(room) {
  return hasRole('super_admin') || hasRole('admin') || hasRole('chat_admin') || room.member_role === 'owner';
}

function canEditSelectedUser() {
  const user = state.selectedUser;
  if (!user || user.id === state.user.id) return false;
  return !user.roles.includes('super_admin') || hasRole('super_admin');
}

function hasRole(role) {
  return state.user?.roles?.includes(role) ?? false;
}

function requireSelectedUser() {
  if (!state.selectedUser) throw new Error('No user is selected.');
  return state.selectedUser;
}

function badge(text, danger = false) {
  const item = document.createElement('span');
  item.className = `role-badge${danger ? ' ban-badge' : ''}`;
  item.textContent = text;
  return item;
}

function emptyItem(text) {
  const item = document.createElement('p');
  item.className = 'admin-empty';
  item.textContent = text;
  return item;
}

function actionButton(label, handler, danger = false) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = danger ? 'danger-button' : 'secondary-button';
  button.textContent = label;
  button.addEventListener('click', () => withButton(button, handler));
  return button;
}

async function withButton(button, task) {
  if (button) button.disabled = true;
  clearError();
  try {
    await task();
  } catch (error) {
    handleFailure(error);
  } finally {
    if (button?.isConnected) button.disabled = false;
  }
}

function formatDateTime(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

function toast(message, type = 'info') {
  const item = document.createElement('div');
  item.className = `toast ${type}`;
  item.setAttribute('role', type === 'error' ? 'alert' : 'status');
  item.textContent = message;
  elements['toast-region'].append(item);
  window.setTimeout(() => item.remove(), 6000);
}

function clearError() {
  elements['admin-error'].textContent = '';
}

function showError(message) {
  elements['admin-error'].textContent = message;
}

function handleFailure(error) {
  if (error instanceof ApiError && error.status === 401) {
    window.location.replace('/');
    return;
  }
  const message = error instanceof Error ? error.message : 'An unexpected error occurred.';
  showError(message);
  toast(message, 'error');
}

function handleFatalError(error) {
  const message = error instanceof Error ? error.message : 'Unable to load administration.';
  elements['admin-loading'].textContent = message;
  elements['admin-loading'].classList.remove('hidden');
  console.error(error);
}
