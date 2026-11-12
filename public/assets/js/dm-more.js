// Phones: the Direct conversation header stays one row, with Report and
// Block behind "More actions" (the S2c header at 360px stacked them below the
// name). A disclosure: Escape or a click elsewhere closes it and returns focus,
// and choosing an action closes it. Wider screens show the actions in the row
// and never show this button (panes.css).

const toggle = document.getElementById('dm-more-toggle');
const actions = document.getElementById('dm-actions');

if (toggle && actions) {
  const items = () => [...actions.querySelectorAll('button')];
  const available = () => items().some((button) => !button.classList.contains('hidden'));
  const setOpen = (open, { restoreFocus = false } = {}) => {
    actions.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', String(open));
    if (open) items().find((button) => !button.classList.contains('hidden'))?.focus();
    else if (restoreFocus) toggle.focus();
  };
  // Offered only while there's something to do (the actions show per conversation).
  const sync = () => {
    toggle.hidden = !available();
    if (toggle.hidden) setOpen(false);
  };
  new MutationObserver(sync).observe(actions, { subtree: true, attributes: true, attributeFilter: ['class'] });
  sync();

  toggle.addEventListener('click', () => setOpen(!actions.classList.contains('open')));
  actions.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('button')) setOpen(false);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && actions.classList.contains('open')) {
      event.preventDefault();
      setOpen(false, { restoreFocus: true });
    }
  });
  document.addEventListener('click', (event) => {
    if (!actions.classList.contains('open') || !(event.target instanceof Node)) return;
    if (!actions.contains(event.target) && !toggle.contains(event.target)) setOpen(false);
  });
}
