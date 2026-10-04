// The Account page's "Sign-in methods" card: connect or disconnect Google and
// Twitch. Connecting leaves for the provider and comes back here.
import { apiGet, apiPost } from './api.js';
import { formatDateTime } from './datetime.js';

window.addEventListener('DOMContentLoaded', async () => {
  const card = document.getElementById('sign-in-methods');
  const list = document.getElementById('sign-in-method-list');
  const status = document.getElementById('sign-in-methods-status');
  if (!card || !list || !status) return;

  // Coming back from the provider: say how it went, then tidy the address.
  const parameters = new URLSearchParams(window.location.search);
  const connected = parameters.get('connected');
  // A failed picture fetch is reported by the Profile picture card instead.
  const failure = parameters.has('picture') ? null : parameters.get('sign_in_error');
  if (connected || failure) {
    parameters.delete('connected');
    parameters.delete('sign_in_error');
    const query = parameters.toString();
    window.history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
  }

  const render = async () => {
    let response;
    try {
      response = await apiGet('/api/v1/account/identities/list.php');
    } catch {
      return;
    }
    const providers = Array.isArray(response.providers) ? response.providers : [];
    card.classList.toggle('hidden', providers.length === 0);
    const identities = new Map((response.identities ?? []).map((identity) => [identity.provider, identity]));
    list.replaceChildren();
    for (const provider of providers) {
      const identity = identities.get(provider.id);
      const item = document.createElement('li');
      item.className = 'sign-in-method';
      const text = document.createElement('div');
      const name = document.createElement('strong');
      name.textContent = provider.label;
      const detail = document.createElement('span');
      detail.textContent = identity
        ? `Connected ${formatDateTime(identity.linked_at, { dateStyle: 'medium', timeStyle: null })}${identity.last_used_at ? ` · last used ${formatDateTime(identity.last_used_at)}` : ''}`
        : 'Not connected';
      text.append(name, detail);

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'secondary-button';
      button.textContent = identity ? 'Disconnect' : 'Connect';
      button.setAttribute('aria-label', `${identity ? 'Disconnect' : 'Connect'} ${provider.label}`);
      button.addEventListener('click', async () => {
        button.disabled = true;
        status.textContent = '';
        try {
          if (identity) {
            await apiPost('/api/v1/account/identities/unlink.php', { provider: provider.id });
            status.textContent = `${provider.label} is disconnected.`;
            await render();
          } else {
            const result = await apiPost('/api/v1/oidc/link.php', { provider: provider.id });
            window.location.assign(result.url);
          }
        } catch (error) {
          status.textContent = error instanceof Error ? error.message : 'That did not work.';
          button.disabled = false;
        }
      });
      item.append(text, button);
      list.append(item);
    }
  };

  // The session sets the CSRF token that connecting and disconnecting need.
  await apiGet('/api/v1/session.php').catch(() => null);
  await render();
  window.addEventListener('chitchat:password-set', () => { void render(); });
  if (failure) {
    status.textContent = failure;
  } else if (connected) {
    const label = { google: 'Google', twitch: 'Twitch' }[connected] ?? connected;
    status.textContent = `${label} is now connected. You can use it to sign in.`;
  }
});
