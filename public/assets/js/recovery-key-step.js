// "Save your recovery key" (#155): shown once, right after registration,
// after a password reset, and when a key is created in Account. The key
// can't be shown again, so continuing needs one deliberate tick; it is not
// gated on Copy or Download, because people who write it down use neither.
import { formatKey } from './recovery.js';

let sequence = 0;

/**
 * Builds the step. `onContinue` runs after the tick and the button.
 * Returns { root, focus } so callers can place it in a card or a dialog.
 */
export function buildKeyStep({ eyebrow, title, intro, notice = null, key, username, appName, continueLabel, onContinue }) {
  const id = `recovery-key-${++sequence}`;
  const formatted = formatKey(key);
  const root = document.createElement('section');
  root.className = 'recovery-key-step form-stack';
  root.setAttribute('aria-labelledby', `${id}-title`);

  const eyebrowNode = document.createElement('p');
  eyebrowNode.className = 'recovery-eyebrow';
  eyebrowNode.textContent = eyebrow;
  const heading = document.createElement('h2');
  heading.id = `${id}-title`;
  heading.tabIndex = -1;
  heading.textContent = title;
  const introNode = document.createElement('p');
  introNode.textContent = intro;
  root.append(eyebrowNode, heading, introNode);
  if (notice) {
    const noticeNode = document.createElement('p');
    noticeNode.className = 'recovery-notice';
    noticeNode.textContent = notice;
    root.append(noticeNode);
  }

  // The groups read letter by letter ("K 7 Q F"), so a screen reader user can copy it down.
  const output = document.createElement('output');
  output.className = 'recovery-key';
  output.setAttribute('aria-label', 'Recovery key');
  for (const group of formatted.split('-')) {
    const span = document.createElement('span');
    span.textContent = group;
    span.setAttribute('aria-label', group.split('').join(' '));
    output.append(span);
  }

  const actions = document.createElement('div');
  actions.className = 'recovery-key-actions';
  const copy = button('Copy', 'secondary-button');
  const download = button('Download as text file', 'secondary-button');
  actions.append(copy, download);
  const status = document.createElement('p');
  status.className = 'recovery-key-status';
  status.setAttribute('role', 'status');

  const advice = document.createElement('p');
  advice.className = 'recovery-advice';
  advice.textContent = 'Keep it somewhere safe that isn’t this browser: a password manager, or on paper.';

  const tick = document.createElement('label');
  tick.className = 'recovery-confirm';
  const checkbox = document.createElement('input');
  checkbox.type = 'checkbox';
  checkbox.id = `${id}-saved`;
  tick.append(checkbox, ' I’ve saved my recovery key');

  const proceed = button(continueLabel, 'primary-button');
  proceed.disabled = true;
  checkbox.addEventListener('change', () => { proceed.disabled = !checkbox.checked; });

  copy.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(formatted);
      status.textContent = 'Copied.';
    } catch {
      status.textContent = 'Copy didn’t work. Select the key and copy it yourself.';
    }
  });
  download.addEventListener('click', () => {
    const text = [
      `Recovery key for ${username} at ${appName}`,
      formatted,
      `Created ${new Date().toLocaleDateString(undefined, { dateStyle: 'long' })}`,
      `If you forget your password, go to ${window.location.origin}/?reset=1 and use this key.`,
      'Each key works once. You’ll get a new one after using it.',
      '',
    ].join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
    link.download = `${appName.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'chitchat'}-recovery-key.txt`;
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    status.textContent = 'Downloaded.';
  });
  proceed.addEventListener('click', () => {
    if (checkbox.checked) onContinue();
  });

  root.append(output, actions, status, advice, tick, proceed);
  return { root, focus: () => heading.focus() };
}

function button(text, className) {
  const node = document.createElement('button');
  node.type = 'button';
  node.className = className;
  node.textContent = text;
  return node;
}
