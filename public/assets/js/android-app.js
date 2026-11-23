// ChitChat for Android (#417): where the app is, from session.php's android_app, which is
// {store_url, open_url, server_address} or null (setting off, or no store listing yet). Each page that
// points to the app has an empty, hidden slot marked data-android-app: "sign-in" under the
// sign-in card, "account" in the You list (members only: the app has no guest entry). A slot
// stays hidden unless the session says where the app is. Never a prompt or a dialog.
//
// The badge is Google's own art, unmodified and served from here. Its link is store_url as
// the server built it: the listing, carrying only this installation's public host for the
// app to prefill. On Android, and only there, a second line opens an installed app with this
// server (open_url); the app prefills its "Where do you chat?" step and the person confirms.

const BADGE = '/assets/badges/google-play-en.png';
const STORE_PREFIX = 'https://play.google.com/store/apps/details?';
const OPEN_PREFIX = 'intent://connect?';

/** What the slots show, or null when there's nothing safe to show. */
export function androidApp(value) {
  if (!value || typeof value !== 'object') return null;
  const { store_url: storeUrl, open_url: open, server_address: address } = value;
  if (typeof storeUrl !== 'string' || !storeUrl.startsWith(STORE_PREFIX)) return null;
  // The address is the bare host (with :port when it isn't the default), shown and copied as is.
  const serverAddress = typeof address === 'string' && /^[^\s/?#@]+$/u.test(address) ? address : null;
  const openUrl = typeof open === 'string' && open.startsWith(OPEN_PREFIX) ? open : null;
  return { storeUrl, openUrl, serverAddress };
}

/** Whether this browser is on Android; anything uncertain counts as not. */
export function onAndroid(nav = globalThis.navigator) {
  if (!nav) return false;
  if (typeof nav.userAgentData?.platform === 'string') return nav.userAgentData.platform === 'Android';
  return /Android/.test(nav.userAgent ?? '');
}

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function badge(storeUrl) {
  const link = element('a', 'play-badge');
  link.href = storeUrl;
  const image = element('img');
  image.src = BADGE;
  // The badge is the link's only content, so its words are the link's name.
  image.alt = 'Get it on Google Play';
  image.width = 154;
  image.height = 60;
  link.append(image);
  return link;
}

const trademark = () => element('span', 'play-trademark', 'Google Play and the Google Play logo are trademarks of Google LLC.');

/** "Already have the app? Open it with this server", on Android only. */
function openLine(app, android) {
  if (!app.openUrl || !android) return [];
  const line = element('span', 'android-app-open');
  const link = element('a', null, 'Open it with this server');
  link.href = app.openUrl;
  line.append('Already have the app? ', link);
  return [line];
}

function fillSignIn(slot, app, android) {
  slot.replaceChildren(element('span', 'android-app-lead', 'Also on Android'), badge(app.storeUrl), ...openLine(app, android), trademark());
}

function fillAccount(slot, app, android) {
  const parts = [element('h2', 'android-app-title', 'ChitChat for Android'), badge(app.storeUrl), ...openLine(app, android)];
  if (app.serverAddress) {
    const row = element('div', 'android-app-server');
    const line = element('p', 'android-app-address');
    // The words the app's own field uses, so the two match.
    line.append('Server address: ', element('span', 'android-app-host', app.serverAddress));
    const copy = element('button', 'secondary-button android-app-copy');
    copy.type = 'button';
    copy.append('Copy', element('span', 'visually-hidden', ' server address'));
    const status = element('p', 'android-app-status');
    status.setAttribute('role', 'status');
    let clear = 0;
    copy.addEventListener('click', async () => {
      window.clearTimeout(clear);
      try {
        await navigator.clipboard.writeText(app.serverAddress);
        status.textContent = 'Copied.';
      } catch {
        status.textContent = 'Couldn’t copy. Select the address to copy it.';
      }
      // Said once; the line clears later so the next copy is said again.
      clear = window.setTimeout(() => { status.textContent = ''; }, 4000);
    });
    row.append(line, copy, status);
    parts.push(row);
  }
  parts.push(trademark());
  slot.replaceChildren(...parts);
}

/** Fills every slot under `root` from session.php's android_app; `member` is a signed-in, non-guest user. */
export function fillAndroidApp(value, { member = false, android = onAndroid(), root = document } = {}) {
  const app = androidApp(value);
  for (const slot of root.querySelectorAll('[data-android-app]')) {
    const where = slot.dataset.androidApp;
    const show = app !== null && (where === 'sign-in' || (where === 'account' && member));
    if (!show) {
      slot.replaceChildren();
      slot.hidden = true;
      continue;
    }
    if (where === 'sign-in') fillSignIn(slot, app, android);
    else fillAccount(slot, app, android);
    slot.hidden = false;
  }
}

if (typeof document !== 'undefined') {
  const start = async () => {
    if (!document.querySelector('[data-android-app]')) return;
    try {
      const { apiGet } = await import('./api.js');
      // Shared with the page's own session request while that one is still on its way.
      const session = await apiGet('/api/v1/session.php');
      const user = session?.user ?? null;
      fillAndroidApp(session?.android_app ?? null, { member: user !== null && user.guest !== true && !(user.roles ?? []).includes('guest') });
    } catch {
      // Nothing to point to; the slots stay hidden.
    }
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
}
