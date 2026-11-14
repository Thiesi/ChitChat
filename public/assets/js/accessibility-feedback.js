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

/** The link itself: "Report an accessibility problem (opens GitHub)". */
function feedbackLink(destination) {
  const link = document.createElement('a');
  link.href = destination.href;
  const where = document.createElement('span');
  where.className = 'feedback-where';
  if (destination.newTab) {
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    const tab = document.createElement('span');
    tab.className = 'visually-hidden';
    tab.textContent = ', in a new tab';
    // The new tab is said last, so the visible words stay one piece: "(opens GitHub)".
    where.append(`(opens ${destination.where})`, tab);
  } else {
    where.append(`(${destination.where})`);
  }
  link.append('Report an accessibility problem ', where);
  return link;
}

/**
 * Fills every slot under `root` from session.php's accessibility_feedback ({url} or null).
 * A slot is empty and hidden until then; one marked data-feedback-separator also gets the
 * footer's " · " before the attribution.
 */
export function fillFeedbackLinks(feedback, root = document) {
  const destination = feedbackDestination(feedback?.url);
  for (const slot of root.querySelectorAll('[data-accessibility-feedback]')) {
    if (!destination) {
      slot.replaceChildren();
      slot.hidden = true;
      continue;
    }
    const parts = [feedbackLink(destination)];
    if (slot.hasAttribute('data-feedback-separator')) {
      const separator = document.createElement('span');
      separator.className = 'footer-separator';
      separator.setAttribute('aria-hidden', 'true');
      separator.textContent = ' · ';
      parts.push(separator);
    }
    slot.replaceChildren(...parts);
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
