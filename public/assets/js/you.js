// The You page (account.php) as the shell's You tab (S2b, #168). What the
// old account menu held lives here now: the picture and name, Sounds next
// to the alert settings, and Sign out at the end. Guests get a short You:
// their name, Appearance, an invitation to create an account, and Leave.
import { attachPhoto, avatarTone, initials } from './avatar.js';
import { announcePreference, setAnnouncePreference } from './announce-preference.js';
import { setSoundEnabled, soundEnabled } from './attention.js';
import { markGuestAvatar } from './guest.js';
import { signOut } from './sign-out.js';
import { pageSession } from './page-session.js';
import { startSections } from './you-sections.js';

const signOutButton = document.getElementById('logout-button');
const avatar = document.getElementById('you-avatar');
let guest = false;

for (const toggle of document.querySelectorAll('input[data-sound]')) {
  toggle.checked = soundEnabled(toggle.dataset.sound);
  toggle.addEventListener('change', () => setSoundEnabled(toggle.dataset.sound, toggle.checked));
}

// Accessibility: "Announce new messages", saved on this device.
const announceChoice = announcePreference();
for (const radio of document.querySelectorAll('input[name="announce"]')) {
  radio.checked = radio.value === announceChoice;
  radio.addEventListener('change', () => { if (radio.checked) setAnnouncePreference(radio.value); });
}

signOutButton?.addEventListener('click', async () => {
  signOutButton.disabled = true;
  await signOut({ guest });
});

void start();

async function start() {
  let session;
  try {
    // The same answer that shows the page (account.js), so the sections never stay unstarted
    // under a list that appeared; a failure is shown there, with Try again.
    session = await pageSession();
  } catch {
    return;
  }
  const user = session?.user;
  if (!user) return;
  guest = user.guest === true;
  document.body.dataset.audience = guest ? 'guest' : 'member';
  for (const node of document.querySelectorAll('[data-guest-only]')) node.hidden = !guest;
  for (const node of document.querySelectorAll('#you-session [data-member-only]')) node.hidden = guest;

  // Profile (S5a): the username, and a moderator's request for a new one (#164).
  const username = document.getElementById('you-username');
  if (username) username.textContent = user.username;
  const change = document.getElementById('you-username-change');
  if (change) change.hidden = !user.username_change;

  startSections({ guest });

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
