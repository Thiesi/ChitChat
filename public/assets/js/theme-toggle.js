// Wires every theme control on the page (selects and radio groups) to the
// theme applied by theme.js, and keeps them in agreement with each other.
window.addEventListener('DOMContentLoaded', () => {
  const theme = window.chitchatTheme;
  if (!theme) return;

  const selects = [...document.querySelectorAll('select[data-theme-select]')];
  const radios = [...document.querySelectorAll('input[type="radio"][data-theme-radio]')];

  const show = (value) => {
    for (const select of selects) select.value = value;
    for (const radio of radios) radio.checked = radio.value === value;
  };

  for (const select of selects) {
    select.addEventListener('change', () => {
      theme.set(select.value);
      show(select.value);
    });
  }
  for (const radio of radios) {
    radio.addEventListener('change', () => {
      if (!radio.checked) return;
      theme.set(radio.value);
      show(radio.value);
    });
  }
  show(theme.preference());
});
