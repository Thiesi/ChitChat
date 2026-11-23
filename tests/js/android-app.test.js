import assert from 'node:assert/strict';
import { test } from 'node:test';
import { androidApp, onAndroid } from '../../public/assets/js/android-app.js';

// #417: the pages point to ChitChat for Android only with a store link from session.php.

const STORE = 'https://play.google.com/store/apps/details?id=de.networkxxiii.chitchat&referrer=chitchat_server%3Dchat.example.org';
const OPEN = 'intent://connect?server=chat.example.org#Intent;scheme=chitchat;package=de.networkxxiii.chitchat;S.browser_fallback_url=https%3A%2F%2Fplay.google.com%2Fstore%2Fapps%2Fdetails%3Fid%3Dde.networkxxiii.chitchat%26referrer%3Dchitchat_server%253Dchat.example.org;end';

test('the store link, the open link and the bare server address are used as given', () => {
  assert.deepEqual(androidApp({ store_url: STORE, open_url: OPEN, server_address: 'chat.example.org' }), { storeUrl: STORE, openUrl: OPEN, serverAddress: 'chat.example.org' });
  assert.equal(androidApp({ store_url: STORE, server_address: 'chat.example.org:8443' }).serverAddress, 'chat.example.org:8443');
});

test('without a server address, the badge alone', () => {
  for (const address of [null, undefined, '', 'https://chat.example.org', 'chat.example.org/', 'chat.example.org/path', 'user@chat.example.org', ' chat.example.org']) {
    assert.equal(androidApp({ store_url: STORE, server_address: address }).serverAddress, null, String(address));
  }
});

test("an open link is only ever the app's intent", () => {
  for (const open of [null, undefined, '', 'https://example.org', 'chitchat://connect?server=x', 'javascript:alert(1)']) {
    assert.equal(androidApp({ store_url: STORE, open_url: open }).openUrl, null, String(open));
  }
});

test('nothing, or a link that is not the Play store, shows nothing', () => {
  for (const value of [null, undefined, {}, 'yes', { store_url: null }, { store_url: 'https://example.org/app' }, { store_url: 'http://play.google.com/store/apps/details?id=x' }, { store_url: 'javascript:alert(1)' }]) {
    assert.equal(androidApp(value), null, JSON.stringify(value));
  }
});

test('Android is told by the client hint, else the user agent; anything else is not Android', () => {
  assert.equal(onAndroid({ userAgentData: { platform: 'Android' }, userAgent: 'Mozilla/5.0 (X11; Linux x86_64)' }), true);
  assert.equal(onAndroid({ userAgentData: { platform: 'Windows' }, userAgent: 'Android' }), false);
  assert.equal(onAndroid({ userAgent: 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36' }), true);
  assert.equal(onAndroid({ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)' }), false);
  assert.equal(onAndroid({}), false);
  assert.equal(onAndroid(undefined), false);
});
