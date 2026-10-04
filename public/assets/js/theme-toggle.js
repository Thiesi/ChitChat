// Wires every theme selector on the page to the theme applied by theme.js.
window.addEventListener('DOMContentLoaded', () => {
  const theme = window.chitchatTheme;
  if (!theme) return;

  const selects = [...document.querySelectorAll('select[data-theme-select]')];
  for (const select of selects) {
    select.value = theme.preference();
    select.addEventListener('change', () => {
      theme.set(select.value);
      for (const other of selects) other.value = select.value;
    });
  }
});
