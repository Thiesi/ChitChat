window.addEventListener('DOMContentLoaded', () => {
  const tabs = [
    document.querySelector('#login-tab'),
    document.querySelector('#register-tab'),
  ].filter((tab) => tab instanceof HTMLButtonElement);

  if (tabs.length !== 2) return;

  const panels = new Map([
    [tabs[0], document.querySelector('#login-form')],
    [tabs[1], document.querySelector('#register-form')],
  ]);

  const sync = () => {
    for (const tab of tabs) {
      const selected = tab.getAttribute('aria-selected') === 'true';
      tab.tabIndex = selected ? 0 : -1;

      const panel = panels.get(tab);
      if (panel instanceof HTMLFormElement) {
        panel.hidden = !selected;
        panel.classList.toggle('hidden', !selected);
      }
    }
  };

  const availableTabs = () => tabs.filter((tab) => !tab.hidden && !tab.classList.contains('hidden'));

  for (const tab of tabs) {
    tab.addEventListener('click', () => queueMicrotask(sync));
    tab.addEventListener('keydown', (event) => {
      const available = availableTabs();
      const current = available.indexOf(tab);
      if (current === -1) return;

      let next = null;
      if (event.key === 'ArrowRight') next = available[(current + 1) % available.length];
      if (event.key === 'ArrowLeft') next = available[(current - 1 + available.length) % available.length];
      if (event.key === 'Home') next = available[0];
      if (event.key === 'End') next = available[available.length - 1];
      if (next === null) return;

      event.preventDefault();
      next.click();
      next.focus();
    });

    new MutationObserver(sync).observe(tab, {
      attributes: true,
      attributeFilter: ['aria-selected', 'class', 'hidden'],
    });
  }

  sync();
});
