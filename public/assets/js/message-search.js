import { ApiError, apiGet, apiPost, setCsrfToken } from './api.js';

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
  elements['message-search-scope'].value = ['all', 'rooms', 'direct'].includes(scope) ? scope : 'all';
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
  const scope = elements['message-search-scope'].value;
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

    for (const result of results) {
      elements['message-search-results'].append(buildResult(result));
    }
    state.resultCount += results.length;
    state.nextOffset = Number.isInteger(response.next_offset) ? response.next_offset : null;
    elements['message-search-more'].classList.toggle('hidden', !response.has_more);

    if (state.resultCount === 0) {
      elements['message-search-status'].textContent = 'No visible messages matched your search.';
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

function buildResult(result) {
  const item = document.createElement('li');
  const article = document.createElement('article');
  article.className = 'message-search-result';

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
    link.textContent = 'Open ChitChat';
  }
  heading.append(link);

  const context = document.createElement('span');
  context.className = 'message-search-context';
  context.textContent = result.kind === 'direct' ? 'Direct message' : 'Room message';
  header.append(heading, context);

  const excerpt = document.createElement('p');
  excerpt.className = 'message-search-excerpt';
  excerpt.textContent = typeof result.excerpt === 'string' ? result.excerpt : '';

  const meta = document.createElement('p');
  meta.className = 'message-search-meta';
  const sender = result.kind === 'direct' && result.outgoing
    ? 'You'
    : result.sender?.username ?? 'System';
  const edited = result.edited_at ? ' · edited' : '';
  meta.textContent = `${sender} · ${formatDateTime(result.created_at)}${edited}`;

  article.append(header, excerpt, meta);
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
  elements['message-search-error'].textContent = errorMessage(error);
}

function errorMessage(error) {
  if (error instanceof ApiError || error instanceof Error) return error.message;
  return 'The message search failed.';
}

function formatDateTime(value) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? String(value ?? '') : date.toLocaleString();
}

function handleFatal(error) {
  elements['message-search-loading'].textContent = errorMessage(error);
}
