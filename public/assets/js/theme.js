// Applies the light or dark mode and the colour scheme before the page
// paints. Loaded as a classic, blocking script in <head> so there is no flash
// of the wrong colours. Both choices are per device: the mode "system" (the
// default) follows the operating system, "light" or "dark" are stored in
// localStorage; the scheme defaults to Lounge.
(() => {
  const storageKey = 'chitchat-theme';
  const schemeKey = 'chitchat-scheme';
  const schemes = ['lounge', 'dusk', 'ember', 'rose', 'midnight', 'contrast'];
  const lightQuery = window.matchMedia('(prefers-color-scheme: light)');

  function preference() {
    try {
      const stored = window.localStorage.getItem(storageKey);
      return stored === 'light' || stored === 'dark' ? stored : 'system';
    } catch {
      return 'system';
    }
  }

  function scheme() {
    try {
      const stored = window.localStorage.getItem(schemeKey);
      return schemes.includes(stored) ? stored : 'lounge';
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
        if (value === 'light' || value === 'dark') {
          window.localStorage.setItem(storageKey, value);
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
        if (chosen === 'lounge') {
          window.localStorage.removeItem(schemeKey);
        } else {
          window.localStorage.setItem(schemeKey, chosen);
        }
      } catch {
        // Storage can be unavailable; the scheme then lasts for this page only.
      }
      applyScheme(chosen);
    },
  };
})();
