import { ApiError, apiGet, apiPost, isPageLeft, setCsrfToken } from './api.js';
import { applicationName } from './application-name.js';
import { nameButton } from './name-menu.js';
import { formatDateTime } from './datetime.js';
import { identityHueIndex } from './identity-hue.js';
import { initials } from './avatar.js';
import { listTime } from './room-list.js';
import { queryTerms, renderHighlighted } from './search-highlight.js';

const SCOPES = ['all', 'rooms', 'direct'];

const state = {
  query: '',
  scope: 'all',
  nextOffset: null,
  resultCount: 0,
  loading: false,
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
    else updateAddress(selectedScope());
  });
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

  const scope = new URLSearchParams(window.location.search).get('scope') ?? 'all';
  const chosen = SCOPES.includes(scope) ? scope : 'all';
  const chip = elements['message-search-scope'].querySelector(`input[value="${chosen}"]`);
  if (chip) chip.checked = true;
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
    const response = await apiPost('/api/v1/search/messages.php', {
      query,
      scope,
      limit: 25,
      offset,
    });
    const results = Array.isArray(response.results) ? response.results : [];

    if (replace) {
      elements['message-search-results'].replaceChildren();
      state.resultCount = 0;
      state.query = query;
      state.scope = scope;
      updateAddress(scope);
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
      const elsewhere = state.scope === 'all' ? 'Try fewer or different words.' : 'Try fewer words, or search All.';
      elements['message-search-status'].textContent = `Nothing matches “${state.query}”. ${elsewhere}`;
    } else if (response.has_more) {
      elements['message-search-status'].textContent = `${state.resultCount} results shown. More results are available.`;
    } else {
      elements['message-search-status'].textContent = `${state.resultCount} ${state.resultCount === 1 ? 'result' : 'results'} shown.`;
    }
  } catch (error) {
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
  renderHighlighted(excerpt, typeof result.excerpt === 'string' ? result.excerpt : '', terms);

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

function updateAddress(scope) {
  const suffix = scope === 'all' ? '' : `?scope=${encodeURIComponent(scope)}`;
  window.history.replaceState(null, '', `/search.php${suffix}`);
}

function setBusy(busy) {
  elements['message-search-query'].disabled = busy;
  elements['message-search-scope'].disabled = busy;
  elements['message-search-submit'].disabled = busy;
  elements['message-search-more'].disabled = busy;
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
