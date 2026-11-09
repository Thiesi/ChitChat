// A one-time note after the Lantern recolour (S0, #167): "ChitChat has a new
// look. Prefer the previous one?" Lounge was the default before and choosing
// it stored nothing, so people who chose it can't be told apart from people
// who never chose; this note tells them how to get it back. Members only
// (a guest's appearance lasts just the visit), only without a stored scheme,
// and gone for good once dismissed or once any scheme is chosen.
const SEEN_KEY = 'chitchat-new-look-seen';

function seen() {
  try {
    return window.localStorage.getItem(SEEN_KEY) === '1';
  } catch {
    return false;
  }
}

function markSeen() {
  try {
    window.localStorage.setItem(SEEN_KEY, '1');
  } catch {
    // Without storage it may return on the next page; harmless.
  }
}

function note() {
  return document.getElementById('new-look-note');
}

export function renderNewLook(user) {
  const strip = note();
  if (!strip) return;
  const show = Boolean(user) && user.guest !== true && window.chitchatTheme?.hasStoredScheme?.() === false && !seen();
  strip.classList.toggle('hidden', !show);
}

function retire() {
  markSeen();
  note()?.classList.add('hidden');
}

window.addEventListener('DOMContentLoaded', () => {
  document.getElementById('new-look-dismiss')?.addEventListener('click', retire);
});
window.addEventListener('chitchat:scheme-chosen', retire);
