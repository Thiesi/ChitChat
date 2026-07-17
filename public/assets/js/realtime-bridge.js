const bridgedTypes = new Set(['room_message', 'message_deleted', 'direct_message']);
const marker = Symbol.for('chitchat.realtimeBridge');

if (!EventSource.prototype[marker]) {
  const nativeAddEventListener = EventSource.prototype.addEventListener;

  Object.defineProperty(EventSource.prototype, marker, {
    value: true,
    configurable: false,
    enumerable: false,
    writable: false,
  });

  EventSource.prototype.addEventListener = function addBridgedEventListener(type, listener, options) {
    if (!bridgedTypes.has(type) || typeof listener !== 'function') {
      return nativeAddEventListener.call(this, type, listener, options);
    }

    const wrapped = function bridgedListener(event) {
      try {
        return listener.call(this, event);
      } finally {
        window.dispatchEvent(new CustomEvent('chitchat:realtime', {
          detail: { type, event },
        }));
      }
    };

    return nativeAddEventListener.call(this, type, wrapped, options);
  };
}
