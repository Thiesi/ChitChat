// Shared by pages and the classic root service worker. No account credentials live here.
globalThis.ChitChatPushState = (() => {
  function open() {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open('chitchat-push', 1);
      request.onupgradeneeded = () => request.result.createObjectStore('state');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  }

  async function read() {
    const db = await open();
    try {
      return await new Promise((resolve, reject) => {
        const tx = db.transaction('state', 'readonly');
        const request = tx.objectStore('state').get('current');
        request.onsuccess = () => resolve(request.result ?? null);
        request.onerror = () => reject(request.error);
      });
    } finally {
      db.close();
    }
  }

  async function change(transform) {
    const db = await open();
    try {
      return await new Promise((resolve, reject) => {
        const tx = db.transaction('state', 'readwrite');
        const store = tx.objectStore('state');
        const request = store.get('current');
        let result;
        request.onsuccess = () => {
          result = transform(request.result ?? null);
          if (result !== undefined) store.put(result, 'current');
        };
        tx.oncomplete = () => resolve(result);
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error ?? new Error('Push state update failed.'));
      });
    } finally {
      db.close();
    }
  }

  const identifier = (value) => typeof value === 'string' && /^[\x21-\x7e]{1,64}$/.test(value);

  async function clear() {
    return change(() => ({ epoch: crypto.randomUUID(), user_id: null, binding: null, seen: [] }));
  }

  async function save(registration, session, epoch, endpoint) {
    if (!identifier(registration.binding) || !Number.isInteger(registration.id) || registration.id < 1) {
      throw new Error('The server did not return a push device binding.');
    }
    return change((current) => current?.epoch === epoch ? {
      epoch, user_id: session.user.id, session_version: session.user.session_version,
      binding: registration.binding, id: registration.id, endpoint, seen: current.seen ?? [],
    } : undefined);
  }

  async function claim(eventId, binding) {
    let accepted = false;
    await change((current) => {
      if (!identifier(eventId) || !identifier(binding) || current?.binding !== binding || !current.user_id) return undefined;
      const now = Date.now();
      const seen = (current.seen ?? []).filter((item) => item.at > now - 6 * 60 * 60 * 1000);
      if (seen.some((item) => item.id === eventId)) return undefined;
      accepted = true;
      return { ...current, seen: [...seen, { id: eventId, at: now }].slice(-256) };
    });
    return accepted;
  }

  return { read, change, clear, save, claim, identifier };
})();