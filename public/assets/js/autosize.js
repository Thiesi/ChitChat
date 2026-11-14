// A message field that grows with its text (#256): one row when empty, taller as the
// text wraps, up to the field's own max-height (then it scrolls). There's no resize
// grip; the height follows the words. Every programmatic change counts too: clearing
// after a send, an inserted emoji or completed name, a restored draft. The field's
// own `value` setter is wrapped, so no caller has to remember to refit it.

const VALUE = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value');

/** Makes the textarea fit its content; returns the refit function. */
export function autosize(textarea) {
  if (!(textarea instanceof HTMLTextAreaElement) || textarea.dataset.autosize === 'true') return () => {};
  textarea.dataset.autosize = 'true';
  const fit = () => {
    // CSSOM, not a style attribute, so the CSP's style-src stays 'self'.
    textarea.style.height = 'auto';
    const style = getComputedStyle(textarea);
    const borders = style.boxSizing === 'border-box'
      ? parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth)
      : -(parseFloat(style.paddingTop) + parseFloat(style.paddingBottom));
    textarea.style.height = `${textarea.scrollHeight + borders}px`;
  };
  Object.defineProperty(textarea, 'value', {
    configurable: true,
    get() { return VALUE.get.call(this); },
    set(next) {
      VALUE.set.call(this, next);
      fit();
    },
  });
  textarea.addEventListener('input', fit);
  // Fitted while hidden (no layout yet), it would have measured nothing: fit again when used.
  textarea.addEventListener('focus', fit);
  window.addEventListener('resize', fit);
  fit();
  return fit;
}
