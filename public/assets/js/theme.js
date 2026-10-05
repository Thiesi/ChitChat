// Applies the light or dark mode and the colour scheme before the page
// paints. Loaded as a classic, blocking script in <head> so there is no flash
// of the wrong colours. Both choices are per device: the mode "system" (the
// default) follows the operating system, "light" or "dark" are stored in
// localStorage; the scheme defaults to Lounge. A guest's choices last only
// for the visit: they go to sessionStorage and leave the device's own alone.
(() => {
  const storageKey = 'chitchat-theme';
  const schemeKey = 'chitchat-scheme';
  const guestKey = 'chitchat-guest';
  const schemes = ['lounge', 'dusk', 'ember', 'rose', 'midnight', 'contrast'];
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
      return schemes.includes(value) ? value : 'lounge';
    } catch {
      return 'lounge';
    }
  }

  function applyScheme(value) {
    if (value === 'lounge') {
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

  window.chitchatTheme = {
    preference,
    scheme,
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
      const chosen = schemes.includes(value) ? value : 'lounge';
      try {
        if (chosen === 'lounge' && !guest()) {
          window.localStorage.removeItem(schemeKey);
        } else {
          store().setItem(schemeKey, chosen);
        }
      } catch {
        // Storage can be unavailable; the scheme then lasts for this page only.
      }
      applyScheme(chosen);
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
