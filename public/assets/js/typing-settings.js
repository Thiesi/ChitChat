// The Account page's "Typing indicator" card: one switch, both directions.
import { apiGet, apiPost } from './api.js';

window.addEventListener('DOMContentLoaded', async () => {
  const toggle = document.getElementById('share-typing');
  const status = document.getElementById('share-typing-status');
  if (!toggle || !status) return;

  toggle.disabled = true;
  try {
    const session = await apiGet('/api/v1/session.php');
    toggle.checked = session.share_typing !== false;
  } catch {
    return;
  }
  toggle.disabled = false;

  toggle.addEventListener('change', async () => {
    const wanted = toggle.checked;
    toggle.disabled = true;
    status.textContent = '';
    try {
      const response = await apiPost('/api/v1/account/typing.php', { share_typing: wanted });
      toggle.checked = response.share_typing;
      status.textContent = response.share_typing
        ? 'The typing indicator is on.'
        : 'The typing indicator is off: nobody sees you typing, and you see nobody typing.';
    } catch (error) {
      toggle.checked = !wanted;
      status.textContent = error instanceof Error ? error.message : 'That did not work.';
    } finally {
      toggle.disabled = false;
    }
  });
});
