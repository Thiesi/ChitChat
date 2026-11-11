// The phone list screen (S2c, panes.js): shared by the Rooms and Direct pages.

/**
 * True while a phone shows the list screen. Nothing is opened behind it then:
 * an unseen conversation must not be marked read, announced or counted as present.
 */
export function phoneListShowing() {
  const frame = document.querySelector('[data-panes]');
  return frame?.dataset.stack === 'list' && window.matchMedia('(max-width: 47.99rem)').matches;
}
