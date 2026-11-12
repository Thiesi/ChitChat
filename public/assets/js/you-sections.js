// The You page's sections (S5a, #171; addendum comment 6826886672). The list
// pane names the sections; the main pane shows the chosen one. Each section
// has an address (#profile, #alerts, #appearance, #security, #privacy,
// #account). Old addresses still land in the right place: a card's heading id
// (#appearance-heading, #account-closure-heading) opens the section holding
// it, and the deep links other pages use (?picture=, ?connected=, ?recovery=)
// open the section they belong to. On phones panes.js stacks the list and the
// section; here only the choice is made.
//
// The pure part (which section an address means) has no DOM access at import
// time, so it can be unit-tested.

export const SECTIONS = ['profile', 'alerts', 'appearance', 'security', 'privacy', 'account'];

// The address as the page was opened: the cards that answer a deep link tidy
// it away on DOMContentLoaded, before the session (and so the sections) is known.
const OPENED = typeof window === 'undefined' ? { hash: '', search: '' } : { hash: window.location.hash, search: window.location.search };

/**
 * Which section an address asks for: its hash, the section holding the element
 * a legacy hash names (findSection), or the section a deep-link parameter
 * belongs to. Null when the address names none.
 */
export function sectionForAddress({ hash = '', search = '' }, findSection = () => null) {
  const key = decodeURIComponent(String(hash).replace(/^#/, ''));
  if (SECTIONS.includes(key)) return key;
  if (key) {
    const holder = findSection(key);
    if (holder) return holder;
  }
  const query = new URLSearchParams(search);
  if (query.has('picture')) return 'profile';
  if (query.has('connected') || query.has('sign_in_error') || query.has('recovery')) return 'security';
  return null;
}

/** An unmodified primary click (or Enter) stays in the page. */
function plain(event) {
  return event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey;
}

/**
 * Starts the sections once the session is known.
 * @param {{ guest: boolean }} options
 */
export function startSections({ guest }) {
  const title = document.getElementById('you-section-title');
  const links = [...document.querySelectorAll('.you-section-link[data-section]')];
  const panels = new Map([...document.querySelectorAll('.you-section[data-section]')].map((panel) => [panel.dataset.section, panel]));
  if (!title || panels.size === 0) return;
  const phone = window.matchMedia('(max-width: 47.99rem)');

  // Guests see only what a visit has: Appearance (and Leave).
  const available = SECTIONS.filter((key) => panels.has(key) && (!guest || panels.get(key).hasAttribute('data-guest-visible')));
  for (const link of links) link.closest('li').hidden = !available.includes(link.dataset.section);

  const findSection = (id) => {
    const element = document.getElementById(id);
    return element?.closest('.you-section[data-section]')?.dataset.section ?? null;
  };

  function choose(key, { focus = false, address = true } = {}) {
    if (!available.includes(key)) key = available[0];
    for (const [name, panel] of panels) panel.hidden = name !== key;
    // On a phone's list screen nothing is open yet, so no row is current until one is chosen.
    const marked = address || !phone.matches;
    for (const link of links) {
      if (marked && link.dataset.section === key) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    }
    title.textContent = links.find((link) => link.dataset.section === key)?.textContent ?? 'You';
    document.title = `${title.textContent} · ${document.title.split(' · ').at(-1)}`;
    // The address names the section, without a history entry of its own (panes.js keeps the phone's).
    if (address) {
      const { pathname, search } = window.location;
      window.history.replaceState(window.history.state, '', `${pathname}${search}#${key}`);
    }
    if (focus) title.focus();
    return key;
  }

  for (const link of links) {
    link.addEventListener('click', (event) => {
      if (!plain(event)) return;
      event.preventDefault();
      choose(link.dataset.section, { focus: event.isTrusted && !phone.matches });
      // panes.js: on phones, push the section over the list (focus goes to its title).
      window.dispatchEvent(new CustomEvent('chitchat:section-chosen', { detail: { opener: link, user: event.isTrusted } }));
    });
  }

  // A hash typed or followed while here (an old anchor, say) moves to its section.
  window.addEventListener('hashchange', () => {
    const key = sectionForAddress(window.location, findSection);
    if (!key) return;
    const legacy = window.location.hash.slice(1);
    choose(key);
    window.dispatchEvent(new CustomEvent('chitchat:section-chosen', { detail: { opener: null, user: false } }));
    if (legacy !== key) document.getElementById(legacy)?.scrollIntoView({ block: 'start' });
  });

  const legacy = decodeURIComponent(OPENED.hash.slice(1));
  const asked = sectionForAddress(OPENED, findSection);
  // With no address, the first section shows on wide screens (phones show the list) and the address stays clean.
  choose(asked ?? available[0], { address: asked !== null });
  // An old heading anchor keeps pointing at its card.
  if (legacy && !SECTIONS.includes(legacy)) document.getElementById(legacy)?.scrollIntoView({ block: 'start' });
}
