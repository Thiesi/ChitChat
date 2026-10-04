// The Account page's Password card: change the password, or set a first one
// for an account created through Google or Twitch (which needs step-up).
import { apiGet, apiPost, setCsrfToken } from './api.js';

window.addEventListener('DOMContentLoaded', async () => {
  const form = document.getElementById('password-form');
  const intro = document.getElementById('password-intro');
  const currentField = document.getElementById('password-current-field');
  const current = document.getElementById('password-current');
  const next = document.getElementById('password-new');
  const submit = document.getElementById('password-submit');
  const status = document.getElementById('password-status');
  if (!form || !intro || !currentField || !current || !next || !submit || !status) return;

  let hasPassword = true;
  const render = () => {
    currentField.classList.toggle('hidden', !hasPassword);
    current.required = hasPassword;
    submit.textContent = hasPassword ? 'Change password' : 'Set a password';
    intro.textContent = hasPassword
      ? 'Changing your password signs you out everywhere else.'
      : 'You sign in with Google or Twitch. Set a password to also sign in with your username.';
  };

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    status.textContent = '';
    submit.disabled = true;
    try {
      const response = await apiPost('/api/v1/password.php', {
        current_password: hasPassword ? current.value : '',
        new_password: next.value,
      });
      if (response.csrf_token) setCsrfToken(response.csrf_token);
      status.textContent = hasPassword ? 'Your password is changed.' : 'Your password is set. You can now sign in with it too.';
      hasPassword = true;
      form.reset();
      render();
      window.dispatchEvent(new CustomEvent('chitchat:password-set'));
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'That did not work.';
    } finally {
      submit.disabled = false;
    }
  });

  try {
    await apiGet('/api/v1/session.php');
    const methods = await apiGet('/api/v1/account/identities/list.php');
    hasPassword = methods.has_password !== false;
  } catch {
    // Keep the ordinary form; the server decides anyway.
  }
  render();
});
