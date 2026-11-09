// Applies the light or dark mode and the colour scheme before the page
// paints. Loaded as a classic, blocking script in <head> so there is no flash
// of the wrong colours. Both choices are per device: the mode "system" (the
// default) follows the operating system, "light" or "dark" are stored in
// localStorage; the scheme defaults to Lantern, shared with ChitChat Mobile
// (S0, #167). Explicit choices of the other schemes are kept. Lounge was the
// default before and choosing it stored nothing, so earlier Lounge picks can't
// be told apart from "never chose" and move to Lantern; from now on every
// explicit choice is stored, Lantern included. A guest's choices last
// only for the visit: they go to sessionStorage and leave the device's own alone.
(() => {
  const storageKey = 'chitchat-theme';
  const schemeKey = 'chitchat-scheme';
  const guestKey = 'chitchat-guest';
  const schemes = ['lantern', 'lounge', 'dusk', 'ember', 'rose', 'midnight', 'contrast'];
  const fallback = 'lantern';
  const lightQuery = window.matchMedia('(prefers-color-scheme: light)');

  function guest() {
    try {
      return window.sessionStorage.getItem(guestKey) === '1';
    } catch {
      return false;
    }
  }

  // A guest's own choice first, else the device's.
  function stored(key) {
    if (guest()) {
      const own = window.sessionStorage.getItem(key);
      if (own !== null) return own;
    }
    return window.localStorage.getItem(key);
  }

  function store() {
    return guest() ? window.sessionStorage : window.localStorage;
  }

  function preference() {
    try {
      const value = stored(storageKey);
      return value === 'light' || value === 'dark' ? value : 'system';
    } catch {
      return 'system';
    }
  }

  function scheme() {
    try {
      const value = stored(schemeKey);
      return schemes.includes(value) ? value : fallback;
    } catch {
      return fallback;
    }
  }

  function applyScheme(value) {
    if (value === fallback) {
      delete document.documentElement.dataset.scheme;
    } else {
      document.documentElement.dataset.scheme = value;
    }
  }

  function apply() {
    const chosen = preference();
    document.documentElement.dataset.theme = chosen === 'system'
      ? (lightQuery.matches ? 'light' : 'dark')
      : chosen;
    applyScheme(scheme());
  }

  apply();
  lightQuery.addEventListener('change', apply);
  // Keep other open tabs in step with a choice made in this one.
  window.addEventListener('storage', (event) => {
    if (event.key === storageKey || event.key === schemeKey) {
      apply();
      window.dispatchEvent(new CustomEvent('chitchat:appearance-changed'));
    }
  });

  /** Whether this device (or guest visit) stores an explicit scheme choice. */
  function hasStoredScheme() {
    try {
      return schemes.includes(stored(schemeKey));
    } catch {
      return false;
    }
  }

  window.chitchatTheme = {
    preference,
    scheme,
    hasStoredScheme,
    schemes: [...schemes],
    set(value) {
      try {
        // A guest's "System" is stored too, so it is not overruled by the device's choice.
        if (value === 'light' || value === 'dark' || guest()) {
          store().setItem(storageKey, value === 'light' || value === 'dark' ? value : 'system');
        } else {
          window.localStorage.removeItem(storageKey);
        }
      } catch {
        // Storage can be unavailable (private modes); the choice then lasts for this page only.
        document.documentElement.dataset.theme = value === 'system'
          ? (lightQuery.matches ? 'light' : 'dark')
          : value;
        return;
      }
      apply();
    },
    setScheme(value) {
      const chosen = schemes.includes(value) ? value : fallback;
      try {
        // Every explicit choice is stored, the default included, so "nothing
        // stored" keeps meaning only "never chose" if the default changes again.
        store().setItem(schemeKey, chosen);
      } catch {
        // Storage can be unavailable; the scheme then lasts for this page only.
      }
      applyScheme(chosen);
      window.dispatchEvent(new CustomEvent('chitchat:scheme-chosen', { detail: { scheme: chosen } }));
    },
    /** Starts or ends a guest visit; ending it forgets the guest's choices. */
    setGuest(on) {
      try {
        if (on) {
          window.sessionStorage.setItem(guestKey, '1');
        } else if (guest()) {
          for (const key of [guestKey, storageKey, schemeKey]) window.sessionStorage.removeItem(key);
        }
      } catch {
        // Without storage a guest's choices last for this page only anyway.
      }
      apply();
      window.dispatchEvent(new CustomEvent('chitchat:appearance-changed'));
    },
  };
})();
