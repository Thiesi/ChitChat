// The You page (account.php) as the shell's You tab (S2b, #168). What the
// old account menu held lives here now: the picture and name, Sounds next
// to the alert settings, and Sign out at the end. Guests get a short You:
// their name, Appearance, an invitation to create an account, and Leave.
import { apiGet } from './api.js';
import { attachPhoto, avatarTone, initials } from './avatar.js';
import { setSoundEnabled, soundEnabled } from './attention.js';
import { markGuestAvatar } from './guest.js';
import { signOut } from './sign-out.js';

const signOutButton = document.getElementById('logout-button');
const avatar = document.getElementById('you-avatar');
let guest = false;

for (const toggle of document.querySelectorAll('input[data-sound]')) {
  toggle.checked = soundEnabled(toggle.dataset.sound);
  toggle.addEventListener('change', () => setSoundEnabled(toggle.dataset.sound, toggle.checked));
}

signOutButton?.addEventListener('click', async () => {
  signOutButton.disabled = true;
  await signOut({ guest });
});

void start();

async function start() {
  let session;
  try {
    session = await apiGet('/api/v1/session.php');
  } catch {
    return;
  }
  const user = session?.user;
  if (!user) return;
  guest = user.guest === true;
  document.body.dataset.audience = guest ? 'guest' : 'member';
  for (const node of document.querySelectorAll('[data-guest-only]')) node.hidden = !guest;
  for (const node of document.querySelectorAll('#you-session [data-member-only]')) node.hidden = guest;

  if (avatar) {
    avatar.classList.add('mini-avatar');
    avatar.textContent = initials(user.username);
    avatar.dataset.tone = String(avatarTone(user.id));
    if (guest) {
      markGuestAvatar(avatar);
    } else {
      avatar.dataset.avatarUser = String(user.id);
      attachPhoto(avatar, user.id);
    }
  }
}
