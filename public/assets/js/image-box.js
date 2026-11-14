import { fileGone } from './file-availability.js';
import { FILE_UNAVAILABLE } from './unavailable.js';

// One image box for room and direct-message attachments (#158, built in S1c).
// The box is reserved from the original's oriented width and height before
// anything loads, so the transcript doesn't jump; without dimensions it is a
// 4:3 box and the image is contained in it. The small rendition
// (`preview.url`, used exactly as the server sends it) is shown when there is
// one; if it fails to load, the original's inline link is tried once, then
// "Preview unavailable", or, when the server says the file is gone (#334), "This
// file isn't available any more." and no link to it. A GIF's rendition is its static first frame, so it
// carries a "GIF" label. The box opens the original in a new tab.

/**
 * @param {{ name: string, mime_type?: string, width?: number|null, height?: number|null,
 *           preview?: { url: string, width?: number, height?: number } | null }} attachment
 * @param {string} originalUrl the original's inline download URL
 */
export function imageBox(attachment, originalUrl) {
  const link = document.createElement('a');
  link.className = 'image-box';
  link.href = originalUrl;
  link.target = '_blank';
  link.rel = 'noopener';

  const width = attachment.width;
  const height = attachment.height;
  if (Number.isInteger(width) && Number.isInteger(height) && width > 0 && height > 0) {
    // CSSOM, not a style attribute, so the CSP's style-src stays 'self'.
    link.style.setProperty('--image-ratio', String(width / height));
    link.dataset.sized = 'true';
  }

  const image = document.createElement('img');
  image.alt = attachment.name;
  image.loading = 'lazy';
  image.decoding = 'async';
  const previewUrl = isSameOriginPath(attachment.preview?.url) ? attachment.preview.url : null;
  let fellBack = previewUrl === null;
  image.src = previewUrl ?? originalUrl;
  image.addEventListener('error', () => {
    if (!fellBack) {
      fellBack = true;
      image.src = originalUrl;
      return;
    }
    const unavailable = document.createElement('span');
    unavailable.className = 'image-box-unavailable';
    unavailable.textContent = 'Preview unavailable';
    image.replaceWith(unavailable);
    link.dataset.unavailable = 'true';
    // Gone, or just no preview? Ask once; a gone file isn't offered as a link any more.
    void fileGone(originalUrl).then((gone) => {
      if (!gone) return;
      unavailable.textContent = FILE_UNAVAILABLE;
      link.dataset.gone = 'true';
      link.removeAttribute('href');
      link.removeAttribute('target');
    });
  });
  link.append(image);

  if (attachment.mime_type === 'image/gif' && previewUrl) {
    const label = document.createElement('span');
    label.className = 'image-box-label';
    label.textContent = 'GIF';
    label.setAttribute('aria-hidden', 'true');
    link.append(label);
    link.setAttribute('aria-label', `${attachment.name}, animated GIF, opens in a new tab`);
  }
  return link;
}

/** A path on this server: starts with one "/", not "//host" or "/\host" (both mean another host). */
export function isSameOriginPath(value) {
  return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') && !value.startsWith('/\\');
}
