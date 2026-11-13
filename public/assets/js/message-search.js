import { ApiError, apiGet, apiPost, isPageLeft, setCsrfToken } from './api.js';
import { applicationName } from './application-name.js';
import { nameButton } from './name-menu.js';
import { formatDateTime } from './datetime.js';
import { identityHueIndex } from './identity-hue.js';
import { initials } from './avatar.js';
import { listTime } from './room-list.js';
import { queryTerms, renderHighlighted } from './search-highlight.js';
import { ROOM_UNAVAILABLE } from './unavailable.js';

const SCOPES = ['all', 'rooms', 'direct'];

const state = {
  query: '',
  scope: 'all',
  nextOffset: null,
  resultCount: 0,
  loading: false,
  // One room only (#318): { id, name } from ?room_id=, until the chip is cleared.
  room: null,
};

const elements = {};

window.addEventListener('DOMContentLoaded', () => {
  bindElements();
  bindEvents();
  bootstrap().catch(handleFatal);
});

function bindElements() {
  for (const id of [
    'message-search-loading',
    'message-search-shell',
    'message-search-identity',
    'message-search-form',
    'message-search-query',
    'message-search-scope',
    'message-search-submit',
    'message-search-error',
    'message-search-status',
    'message-search-results',
    'message-search-more',
    'message-search-room',
    'message-search-room-name',
    'message-search-room-clear',
  ]) {
    const element = document.getElementById(id);
    if (!element) throw new Error(`Missing message-search interface element: ${id}`);
    elements[id] = element;
  }
}

function bindEvents() {
  elements['message-search-form'].addEventListener('submit', submitSearch);
  elements['message-search-more'].addEventListener('click', loadMore);
  // A scope chip re-runs the last search in the new scope.
  elements['message-search-scope'].addEventListener('change', () => {
    if (state.query) void runSearch({ replace: true });
    else updateAddress();
  });
  elements['message-search-room-clear'].addEventListener('click', async () => {
    setRoom(null);
    // The button goes away with the chip: focus returns to the words, after the search re-runs.
    if (state.query) await runSearch({ replace: true });
    elements['message-search-query'].focus();
  });
}

/** Shows the room chip in place of the scope chips, or takes it away. */
function setRoom(room) {
  state.room = room;
  elements['message-search-room'].hidden = room === null;
  elements['message-search-scope'].hidden = room !== null;
  elements['message-search-room-name'].textContent = room ? `In # ${room.name}` : '';
  updateAddress();
}

/** ?room_id=N: the room's name for the chip. A room that's gone says so, and search covers everything. */
async function loadRoomFilter(roomId) {
  try {
    const { room } = await apiGet(`/api/v1/rooms/detail.php?room_id=${encodeURIComponent(roomId)}`);
    setRoom({ id: roomId, name: typeof room?.name === 'string' ? room.name : 'this room' });
  } catch (error) {
    if (isPageLeft(error)) return;
    setRoom(null);
    if (error instanceof ApiError && error.code === 'room_not_found') {
      elements['message-search-error'].textContent = `${ROOM_UNAVAILABLE} Search looks everywhere instead.`;
      return;
    }
    // Couldn't check: keep the filter, the search itself will say if the room is gone.
    setRoom({ id: roomId, name: 'this room' });
  }
}

/** The checked scope chip. */
function selectedScope() {
  const checked = elements['message-search-scope'].querySelector('input[name="scope"]:checked');
  return SCOPES.includes(checked?.value) ? checked.value : 'all';
}

async function bootstrap() {
  const session = await apiGet('/api/v1/session.php');
  setCsrfToken(session.csrf_token);
  if (!session.user) {
    window.location.assign('/');
    return;
  }

  elements['message-search-identity'].textContent = `Signed in as ${session.user.username}`;
  elements['message-search-loading'].classList.add('hidden');
  elements['message-search-shell'].classList.remove('hidden');

  const parameters = new URLSearchParams(window.location.search);
  const scope = parameters.get('scope') ?? 'all';
  const chosen = SCOPES.includes(scope) ? scope : 'all';
  const chip = elements['message-search-scope'].querySelector(`input[value="${chosen}"]`);
  if (chip) chip.checked = true;
  const roomId = Number(parameters.get('room_id'));
  if (Number.isInteger(roomId) && roomId > 0) await loadRoomFilter(roomId);
  elements['message-search-query'].focus();
}

async function submitSearch(event) {
  event.preventDefault();
  await runSearch({ replace: true });
}

async function loadMore() {
  if (state.nextOffset !== null) {
    await runSearch({ replace: false, offset: state.nextOffset });
  }
}

async function runSearch({ replace, offset = 0 }) {
  if (state.loading) return;
  const query = elements['message-search-query'].value.trim();
  const scope = selectedScope();
  if (query.length < 2) {
    elements['message-search-query'].focus();
    return;
  }

  state.loading = true;
  setBusy(true);
  clearError();
  elements['message-search-status'].textContent = replace ? 'Searching…' : 'Loading more results…';

  try {
    // One room: the server takes room_id with the rooms scope (direct can't have one).
    const room = state.room;
    const response = await apiPost('/api/v1/search/messages.php', room
      ? { query, scope: 'rooms', room_id: room.id, limit: 25, offset }
      : { query, scope, limit: 25, offset });
    const results = Array.isArray(response.results) ? response.results : [];

    if (replace) {
      elements['message-search-results'].replaceChildren();
      state.resultCount = 0;
      state.query = query;
      state.scope = scope;
      updateAddress();
    }

    const terms = queryTerms(state.query);
    for (const result of results) {
      elements['message-search-results'].append(buildResult(result, terms));
    }
    state.resultCount += results.length;
    state.nextOffset = Number.isInteger(response.next_offset) ? response.next_offset : null;
    elements['message-search-more'].classList.toggle('hidden', !response.has_more);

    if (state.resultCount === 0) {
      // The door is always open: say what to try next.
      if (room) {
        elements['message-search-status'].textContent = `Nothing in # ${room.name} matches “${state.query}”. Try fewer words, or search everywhere.`;
      } else {
        const elsewhere = state.scope === 'all' ? 'Try fewer or different words.' : 'Try fewer words, or search All.';
        elements['message-search-status'].textContent = `Nothing matches “${state.query}”. ${elsewhere}`;
      }
    } else if (response.has_more) {
      elements['message-search-status'].textContent = `${state.resultCount} results shown. More results are available.`;
    } else {
      elements['message-search-status'].textContent = `${state.resultCount} ${state.resultCount === 1 ? 'result' : 'results'} shown.`;
    }
  } catch (error) {
    if (state.room && error instanceof ApiError && error.code === 'room_not_found') {
      // The room went away, or out of sight (#326): drop the chip and say so.
      setRoom(null);
      elements['message-search-error'].textContent = `${ROOM_UNAVAILABLE} Search again to look everywhere.`;
      elements['message-search-status'].textContent = 'Search could not be completed.';
      return;
    }
    showError(error);
    elements['message-search-status'].textContent = 'Search could not be completed.';
  } finally {
    state.loading = false;
    setBusy(false);
  }
}

/** A room's lantern or a person's pebble, in their identity hue (S4d). Decorative. */
function resultMark(result) {
  const mark = document.createElement('span');
  mark.setAttribute('aria-hidden', 'true');
  if (result.kind === 'room' && result.room) {
    mark.className = 'search-mark lantern';
    mark.textContent = '#';
    const hue = identityHueIndex(result.room.id);
    if (hue !== null) mark.dataset.hue = String(hue);
  } else if (result.kind === 'direct' && result.peer) {
    mark.className = 'search-mark pebble';
    mark.textContent = initials(result.peer.username);
    const hue = identityHueIndex(result.peer.id);
    if (hue !== null) mark.dataset.hue = String(hue);
  } else {
    mark.className = 'search-mark';
  }
  return mark;
}

let resultSequence = 0;

/**
 * A result row (S4d): the lantern or pebble, the place as the link (it opens
 * the exact message), the time; then the excerpt with the matching words in
 * <mark>, and who wrote it. The excerpt and the sender line describe the link.
 */
function buildResult(result, terms) {
  const item = document.createElement('li');
  const article = document.createElement('article');
  article.className = 'message-search-result';
  const id = ++resultSequence;

  const header = document.createElement('header');
  const heading = document.createElement('h3');
  const link = document.createElement('a');
  link.className = 'message-search-result-link';

  if (result.kind === 'room' && result.room) {
    const parameters = new URLSearchParams({
      room_id: String(result.room.id),
      message_id: String(result.message_id),
    });
    link.href = `/?${parameters.toString()}`;
    link.textContent = `# ${result.room.name}`;
  } else if (result.kind === 'direct' && result.peer) {
    const parameters = new URLSearchParams({
      user_id: String(result.peer.id),
      peer_name: result.peer.username,
      message_id: String(result.message_id),
    });
    link.href = `/messages.php?${parameters.toString()}`;
    link.textContent = `Conversation with ${result.peer.username}`;
  } else {
    link.href = '/';
    link.textContent = `Open ${applicationName()}`;
  }
  heading.append(link);

  const time = document.createElement('time');
  time.className = 'message-search-time';
  time.dateTime = typeof result.created_at === 'string' ? result.created_at : '';
  time.textContent = listTime(result.created_at);
  time.title = formatDateTime(result.created_at);
  header.append(heading, time);

  const excerpt = document.createElement('p');
  excerpt.className = 'message-search-excerpt';
  excerpt.id = `search-excerpt-${id}`;
  // Text nodes and <mark> elements only: the excerpt is never parsed as HTML.
  // The server's own match ranges when it sends them (#318); the query's words otherwise.
  renderHighlighted(excerpt, typeof result.excerpt === 'string' ? result.excerpt : '', terms, result.excerpt_matches);

  const meta = document.createElement('p');
  meta.className = 'message-search-meta';
  meta.id = `search-meta-${id}`;
  const edited = result.edited_at ? ' · edited' : '';
  if (result.kind === 'direct' && result.outgoing) {
    meta.append('You');
  } else if (result.sender) {
    meta.append(nameButton(result.sender));
  } else {
    meta.append('System');
  }
  meta.append(` · ${result.kind === 'direct' ? 'Direct message' : 'Room message'}${edited}`);
  link.setAttribute('aria-describedby', `${excerpt.id} ${meta.id}`);

  const body = document.createElement('div');
  body.className = 'message-search-body';
  body.append(header, excerpt, meta);
  article.append(resultMark(result), body);
  item.append(article);
  return item;
}

/** The address keeps the room or the scope, never the words (they stay out of history and logs). */
function updateAddress() {
  const parameters = new URLSearchParams();
  if (state.room) parameters.set('room_id', String(state.room.id));
  else if (selectedScope() !== 'all') parameters.set('scope', selectedScope());
  const suffix = parameters.size > 0 ? `?${parameters.toString()}` : '';
  window.history.replaceState(null, '', `/search.php${suffix}`);
}

function setBusy(busy) {
  elements['message-search-query'].disabled = busy;
  elements['message-search-scope'].disabled = busy;
  elements['message-search-submit'].disabled = busy;
  elements['message-search-more'].disabled = busy;
  elements['message-search-room-clear'].disabled = busy;
}

function clearError() {
  elements['message-search-error'].textContent = '';
}

function showError(error) {
  // A request the page aborted on leaving shows nothing (#270).
  if (isPageLeft(error)) return;
  elements['message-search-error'].textContent = errorMessage(error);
}

function errorMessage(error) {
  if (error instanceof ApiError || error instanceof Error) return error.message;
  return 'The message search failed.';
}


function handleFatal(error) {
  elements['message-search-loading'].textContent = errorMessage(error);
}
