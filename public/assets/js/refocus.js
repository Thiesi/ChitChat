/**
 * After a send or an upload settles, focus goes back to the composer only if
 * it is still there (on the message box or the form's own button), or was lost
 * to nothing, as when the disabled send button drops it. A slow request must
 * not pull focus from wherever the person has gone since, such as the emoji
 * picker or a message (#322).
 *
 * @param {HTMLElement} input the message box
 * @param {...(HTMLElement|null|undefined)} ownControls the form's buttons that may hold focus while it sends
 */
export function refocusComposer(input, ...ownControls) {
  const active = document.activeElement;
  if (active === null || active === document.body || active === input || ownControls.includes(active)) {
    input.focus();
  }
}
