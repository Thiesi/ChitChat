// Applies the light or dark theme before the page paints. Loaded as a classic,
// blocking script in <head> so there is no flash of the wrong theme. The choice
// is per device: "system" (the default) follows the operating system, and
// "light" or "dark" are stored in localStorage.
(() => {
  const storageKey = 'chitchat-theme';
  const lightQuery = window.matchMedia('(prefers-color-scheme: light)');

  function preference() {
    try {
      const stored = window.localStorage.getItem(storageKey);
      return stored === 'light' || stored === 'dark' ? stored : 'system';
    } catch {
      return 'system';
    }
  }

  function apply() {
    const chosen = preference();
    document.documentElement.dataset.theme = chosen === 'system'
      ? (lightQuery.matches ? 'light' : 'dark')
      : chosen;
  }

  apply();
  lightQuery.addEventListener('change', apply);
  // Keep other open tabs in step with a choice made in this one.
  window.addEventListener('storage', (event) => {
    if (event.key === storageKey) apply();
  });

  window.chitchatTheme = {
    preference,
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
  };
})();
