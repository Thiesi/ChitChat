// The window that confirming a sensitive action with Google or Twitch ends
// in. It tells the ChitChat tab how it went, then closes itself. Provider
// pages can sever window.opener, so the message goes over a BroadcastChannel.
const parameters = new URLSearchParams(window.location.search);
const error = parameters.get('sign_in_error');
const confirmed = parameters.get('confirmed') === '1';

document.getElementById('step-up-complete-status').textContent = confirmed
  ? 'Confirmed.'
  : 'The confirmation did not complete.';
if (error) document.getElementById('step-up-complete-error').textContent = error;

if ('BroadcastChannel' in window) {
  const channel = new BroadcastChannel('chitchat-step-up');
  channel.postMessage(confirmed ? { confirmed: true } : { error: error ?? 'The confirmation did not complete.' });
  channel.close();
}
window.setTimeout(() => window.close(), confirmed ? 300 : 4000);
