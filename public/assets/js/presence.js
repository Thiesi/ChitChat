import { ApiError, apiGet, apiPost } from './api.js';

export function createPresenceClient({
  getCurrentRoom,
  canOccupy,
  onExpired,
  onUnauthorized,
  toast,
}) {
  const connectionId = crypto.randomUUID();
  const panel = document.getElementById('presence-panel');
  const list = document.getElementById('presence-list');
  if (!panel || !list) {
    throw new Error('Presence interface is incomplete.');
  }

  let timer = null;
  let inFlight = false;
  let pendingInteraction = false;
  let warningRoomId = null;

  function start() {
    stopTimer();
    timer = window.setInterval(() => {
      heartbeat(false).catch(handleError);
    }, 20_000);
    document.addEventListener('visibilitychange', handleVisibilityChange);
  }

  function stop() {
    stopTimer();
    document.removeEventListener('visibilitychange', handleVisibilityChange);
    warningRoomId = null;
    clear();
  }

  async function enterCurrentRoom(interacted = true) {
    await heartbeat(interacted);
    await refresh();
  }

  async function interact() {
    await heartbeat(true);
  }

  async function heartbeat(interacted = false) {
    pendingInteraction = pendingInteraction || interacted;
    if (inFlight) {
      return;
    }

    inFlight = true;
    try {
      do {
        const currentInteraction = pendingInteraction;
        pendingInteraction = false;
        const room = getCurrentRoom();
        const roomId = room && canOccupy(room) ? room.id : null;
        const response = await apiPost('/api/v1/presence/heartbeat.php', {
          connection_id: connectionId,
          room_id: roomId,
          interacted: currentInteraction,
        });
        const status = response.presence;

        if (status?.expired && room && status.room_id === null) {
          warningRoomId = null;
          clear();
          onExpired(room);
          return;
        }

        if (room && status?.warning_seconds !== null && status?.warning_seconds !== undefined) {
          if (warningRoomId !== room.id) {
            warningRoomId = room.id;
            toast(
              `You will leave #${room.name} in about ${status.warning_seconds} seconds unless you interact.`,
              'warning',
            );
          }
        } else {
          warningRoomId = null;
        }
      } while (pendingInteraction);
    } finally {
      inFlight = false;
    }
  }

  async function refresh() {
    const room = getCurrentRoom();
    if (!room || !canOccupy(room)) {
      clear();
      return;
    }

    try {
      const response = await apiGet(`/api/v1/rooms/presence.php?room_id=${encodeURIComponent(room.id)}`);
      if (getCurrentRoom()?.id !== room.id) {
        return;
      }
      render(Array.isArray(response.users) ? response.users : []);
    } catch (error) {
      if (error instanceof ApiError && [401, 403, 404].includes(error.status)) {
        clear();
      }
      handleError(error);
    }
  }

  async function handleChanged(roomId) {
    if (getCurrentRoom()?.id === roomId) {
      await refresh();
    }
  }

  function render(users) {
    list.replaceChildren();
    panel.classList.remove('hidden');

    if (users.length === 0) {
      const empty = document.createElement('li');
      empty.className = 'room-meta';
      empty.textContent = 'No active users.';
      list.append(empty);
      return;
    }

    for (const user of users) {
      const item = document.createElement('li');
      item.className = 'presence-user';

      const name = document.createElement('span');
      name.className = 'presence-name';
      name.textContent = user.username;

      const idle = document.createElement('span');
      idle.className = 'presence-idle';
      idle.textContent = formatIdle(user.idle_seconds);

      item.append(name, idle);
      list.append(item);
    }
  }

  function clear() {
    list.replaceChildren();
    panel.classList.add('hidden');
  }

  function handleVisibilityChange() {
    if (document.visibilityState === 'visible') {
      heartbeat(false).then(refresh).catch(handleError);
    }
  }

  function handleError(error) {
    if (error instanceof ApiError && error.status === 401) {
      onUnauthorized();
      return;
    }
    if (error instanceof ApiError && [403, 404].includes(error.status)) {
      clear();
      return;
    }
    console.error(error);
  }

  function stopTimer() {
    if (timer !== null) {
      window.clearInterval(timer);
      timer = null;
    }
  }

  return {
    start,
    stop,
    enterCurrentRoom,
    interact,
    refresh,
    handleChanged,
    clear,
  };
}

function formatIdle(value) {
  const seconds = Number.isFinite(value) ? Math.max(0, value) : 0;
  if (seconds < 60) {
    return 'active';
  }
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) {
    return `idle ${minutes}m`;
  }
  return `idle ${Math.floor(minutes / 60)}h`;
}
