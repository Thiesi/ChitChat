// The Google and Twitch logos on sign-in provider buttons, drawn inline so
// nothing is fetched from either company. Decorative: the button text names
// the provider.
const SVG = 'http://www.w3.org/2000/svg';

const LOGOS = {
  google: {
    viewBox: '0 0 48 48',
    paths: [
      ['#EA4335', 'M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z'],
      ['#4285F4', 'M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z'],
      ['#FBBC05', 'M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z'],
      ['#34A853', 'M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z'],
    ],
  },
  twitch: {
    viewBox: '0 0 24 24',
    paths: [
      ['#9146FF', 'M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714z'],
    ],
  },
};

/** The provider's logo as an inline SVG element, or null for an unknown provider. */
export function providerIcon(providerId) {
  const logo = LOGOS[providerId];
  if (!logo) return null;
  const svg = document.createElementNS(SVG, 'svg');
  svg.setAttribute('viewBox', logo.viewBox);
  svg.setAttribute('class', 'provider-icon');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  for (const [fill, d] of logo.paths) {
    const path = document.createElementNS(SVG, 'path');
    path.setAttribute('fill', fill);
    path.setAttribute('d', d);
    svg.append(path);
  }
  return svg;
}

/** Fills `element` with the provider's logo followed by `text`. */
export function withProviderIcon(element, providerId, text) {
  const icon = providerIcon(providerId);
  const label = document.createElement('span');
  label.textContent = text;
  element.replaceChildren(...(icon ? [icon] : []), label);
  element.classList.add('has-provider-icon');
}
