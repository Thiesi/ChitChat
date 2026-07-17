const bridgedTypes = new Set(['room_message', 'message_deleted', 'direct_message']);
const forcedLogoutType = 'forced_logout';
const marker = Symbol.for('chitchat.realtimeBridge');
const sessionVersionMarker = Symbol.for('chitchat.sessionVersion');

if (!EventSource.prototype[marker]) {
  const nativeAddEventListener = EventSource.prototype.addEventListener;

  Object.defineProperty(EventSource.prototype, marker, {
    value: true,
    configurable: false,
    enumerable: false,
    writable: false,
  });

  EventSource.prototype.addEventListener = function addBridgedEventListener(type, listener, options) {
    if (
      (!bridgedTypes.has(type) && type !== forcedLogoutType)
      || typeof listener !== 'function'
    ) {
      return nativeAddEventListener.call(this, type, listener, options);
    }

    const wrapped = function bridgedListener(event) {
      if (type === forcedLogoutType && isStaleForcedLogout(event)) {
        return undefined;
      }

      try {
        return listener.call(this, event);
      } finally {
        if (bridgedTypes.has(type)) {
          window.dispatchEvent(new CustomEvent('chitchat:realtime', {
            detail: { type, event },
          }));
        }
      }
    };

    return nativeAddEventListener.call(this, type, wrapped, options);
  };
}

function isStaleForcedLogout(event) {
  const currentVersion = window[sessionVersionMarker];
  if (!Number.isInteger(currentVersion)) {
    return false;
  }

  try {
    const envelope = JSON.parse(event.data);
    const eventVersion = envelope?.payload?.session_version;
    return Number.isInteger(eventVersion) && eventVersion <= currentVersion;
  } catch {
    return false;
  }
}
