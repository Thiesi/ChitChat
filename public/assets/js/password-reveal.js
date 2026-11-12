// Show/hide on password fields (S3, #169). A toggle button names its field
// with data-reveal; pressing it shows the password as text and back again.
// It's a toggle (aria-pressed) whose name says what it does next. A field
// goes back to hidden when its form is submitted or reset, so a shown
// password never stays on screen after use.

const SHOW = 'Show password';
const HIDE = 'Hide password';

/** Wires one toggle button to its password field. Safe to call twice. */
export function attachPasswordReveal(button, input = document.getElementById(button.dataset.reveal ?? '')) {
  if (!button || !input || button.dataset.revealReady === 'true') return;
  button.dataset.revealReady = 'true';
  button.addEventListener('click', () => setRevealed(button, input, input.type === 'password'));
  const form = input.form;
  if (form) {
    // Capture, so the field is hidden before any submit handler reads or resets it.
    form.addEventListener('submit', () => setRevealed(button, input, false), true);
    form.addEventListener('reset', () => setRevealed(button, input, false));
  }
}

export function setRevealed(button, input, revealed) {
  input.type = revealed ? 'text' : 'password';
  button.setAttribute('aria-pressed', String(revealed));
  button.setAttribute('aria-label', revealed ? HIDE : SHOW);
}

/** The toggle's markup for fields built in script (the step-up dialog). */
export function revealButton(inputId) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'password-toggle';
  button.dataset.reveal = inputId;
  button.setAttribute('aria-controls', inputId);
  button.setAttribute('aria-pressed', 'false');
  button.setAttribute('aria-label', SHOW);
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  for (const [name, value] of Object.entries({
    'aria-hidden': 'true', focusable: 'false', viewBox: '0 0 24 24', width: '20', height: '20', fill: 'none',
    stroke: 'currentColor', 'stroke-width': '1.8', 'stroke-linecap': 'round', 'stroke-linejoin': 'round',
  })) svg.setAttribute(name, value);
  const eye = document.createElementNS('http://www.w3.org/2000/svg', 'path');
  eye.setAttribute('d', 'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z');
  const pupil = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
  pupil.setAttribute('cx', '12');
  pupil.setAttribute('cy', '12');
  pupil.setAttribute('r', '3');
  svg.append(eye, pupil);
  button.append(svg);
  return button;
}

for (const button of document.querySelectorAll('button.password-toggle[data-reveal]')) attachPasswordReveal(button);
