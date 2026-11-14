// "Report an accessibility problem" (#219): where it leads comes from session.php's
// accessibility_feedback, which is the resolved link (the project's issue form unless the
// operator saved their own address) or null when the operator switched it off. Each page that
// offers the link has hidden slots marked data-accessibility-feedback; they're filled and shown
// once the session says where to, and stay hidden otherwise. The link says where it goes:
// "(opens GitHub)", "(opens example.org)" or "(email)".

/** Where a feedback URL leads, or null when there's nothing safe to link to. */
export function feedbackDestination(url) {
  if (typeof url !== 'string' || url === '') return null;
  if (url.startsWith('mailto:')) {
    return url.length > 'mailto:'.length ? { href: url, where: 'email', newTab: false } : null;
  }
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return null;
  }
  if (parsed.protocol !== 'https:' || parsed.hostname === '') return null;
  const host = parsed.hostname.replace(/^www\./, '');
  return { href: url, where: host === 'github.com' ? 'GitHub' : host, newTab: true };
}

/** Fills every slot under `root` from session.php's accessibility_feedback ({url} or null). */
export function fillFeedbackLinks(feedback, root = document) {
  const destination = feedbackDestination(feedback?.url);
  for (const slot of root.querySelectorAll('[data-accessibility-feedback]')) {
    const link = slot.querySelector('a');
    const where = slot.querySelector('[data-feedback-where]');
    if (!destination || !link || !where) {
      slot.hidden = true;
      link?.removeAttribute('href');
      continue;
    }
    link.href = destination.href;
    if (destination.newTab) {
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      const tab = document.createElement('span');
      tab.className = 'visually-hidden';
      tab.textContent = ', in a new tab';
      // The new tab is said last, so the visible words stay one piece: "(opens GitHub)".
      where.replaceChildren(`(opens ${destination.where})`, tab);
    } else {
      link.removeAttribute('target');
      link.removeAttribute('rel');
      where.replaceChildren(`(${destination.where})`);
    }
    slot.hidden = false;
  }
}

if (typeof document !== 'undefined') {
  const start = async () => {
    if (!document.querySelector('[data-accessibility-feedback]')) return;
    try {
      const { apiGet } = await import('./api.js');
      // Shared with the page's own session request while that one is still on its way.
      const session = await apiGet('/api/v1/session.php');
      fillFeedbackLinks(session?.accessibility_feedback ?? null);
    } catch {
      // No session answer: the link simply stays hidden.
    }
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    void start();
  }
}
