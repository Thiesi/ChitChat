// The Account page's "Profile picture" card: choose an image, crop it in the
// browser, and upload the crop. The server re-encodes whatever arrives, so
// the crop is a convenience, not a security boundary.
import { ApiError, apiGet, apiPost, apiUpload } from './api.js';
import { attachPhoto, avatarTone, initials, refreshPhoto } from './avatar.js';

const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_BYTES = 5 * 1024 * 1024;
const OUTPUT = 512;

window.addEventListener('DOMContentLoaded', async () => {
  const preview = document.getElementById('avatar-preview');
  const fileInput = document.getElementById('avatar-file');
  const removeButton = document.getElementById('avatar-remove');
  const status = document.getElementById('avatar-status');
  const dialog = document.getElementById('avatar-crop-dialog');
  const canvas = document.getElementById('avatar-crop-canvas');
  const zoom = document.getElementById('avatar-crop-zoom');
  const save = document.getElementById('avatar-crop-save');
  const cancel = document.getElementById('avatar-crop-cancel');
  const providerButtons = document.getElementById('avatar-providers');
  if (!preview || !fileInput || !providerButtons || !removeButton || !status || !dialog || !canvas || !zoom || !save || !cancel) return;

  // Wired before any network request, so a picture chosen right away is not
  // missed. The session (and with it the CSRF token) is asked for at once,
  // and saving waits for it, so a quick save cannot go out without a token.
  const session = apiGet('/api/v1/session.php');
  session.catch(() => {}); // Failures surface where it is awaited.
  let user = null;
  const showState = async () => {
    if (!user) return;
    try {
      const response = await apiGet(`/api/v1/users/profile.php?user_id=${encodeURIComponent(user.id)}`);
      removeButton.classList.toggle('hidden', !response.profile?.has_avatar);
      if (response.avatars_available === false) {
        fileInput.disabled = true;
        fileInput.labels?.[0]?.classList.add('is-disabled');
        status.textContent = 'Profile pictures are not available on this server yet (it needs the PHP GD extension with WebP support).';
      }
    } catch {
      // The card still works; the remove button simply stays hidden.
    }
  };

  // ---- Cropping ----
  const context = canvas.getContext('2d');
  const image = new Image();
  let scale = 1;
  let baseScale = 1;
  let offsetX = 0;
  let offsetY = 0;

  const clamp = () => {
    const width = image.naturalWidth * scale;
    const height = image.naturalHeight * scale;
    offsetX = Math.min(0, Math.max(OUTPUT - width, offsetX));
    offsetY = Math.min(0, Math.max(OUTPUT - height, offsetY));
  };
  const draw = () => {
    clamp();
    context.clearRect(0, 0, OUTPUT, OUTPUT);
    context.drawImage(image, offsetX, offsetY, image.naturalWidth * scale, image.naturalHeight * scale);
  };
  const setZoom = (value) => {
    const next = Math.min(4, Math.max(1, value));
    // Zoom around the centre of the frame.
    const centreX = (OUTPUT / 2 - offsetX) / scale;
    const centreY = (OUTPUT / 2 - offsetY) / scale;
    scale = baseScale * next;
    offsetX = OUTPUT / 2 - centreX * scale;
    offsetY = OUTPUT / 2 - centreY * scale;
    zoom.value = String(next);
    draw();
  };
  const move = (dx, dy) => {
    offsetX += dx;
    offsetY += dy;
    draw();
  };

  image.addEventListener('load', () => {
    baseScale = Math.max(OUTPUT / image.naturalWidth, OUTPUT / image.naturalHeight);
    scale = baseScale;
    offsetX = (OUTPUT - image.naturalWidth * scale) / 2;
    offsetY = (OUTPUT - image.naturalHeight * scale) / 2;
    zoom.value = '1';
    draw();
    dialog.showModal();
    canvas.focus();
  });
  image.addEventListener('error', () => {
    status.textContent = 'That image could not be read. Choose a JPEG, PNG, or WebP image.';
  });

  // A chosen file or a fetched provider picture both open the crop step.
  const openCrop = (file) => {
    if (!ACCEPTED.includes(file.type)) {
      status.textContent = 'Choose a JPEG, PNG, or WebP image.';
      return;
    }
    if (file.size > MAX_BYTES) {
      status.textContent = 'Choose an image of at most 5 MB.';
      return;
    }
    status.textContent = '';
    const reader = new FileReader();
    reader.addEventListener('load', () => { image.src = String(reader.result); });
    reader.readAsDataURL(file);
  };
  fileInput.addEventListener('change', () => {
    const file = fileInput.files?.[0];
    fileInput.value = '';
    if (file) openCrop(file);
  });

  let drag = null;
  canvas.addEventListener('pointerdown', (event) => {
    // The canvas is drawn at 512 px but shown smaller; convert screen pixels.
    const ratio = OUTPUT / canvas.getBoundingClientRect().width;
    drag = { x: event.clientX, y: event.clientY, ratio };
    canvas.setPointerCapture(event.pointerId);
  });
  canvas.addEventListener('pointermove', (event) => {
    if (!drag) return;
    move((event.clientX - drag.x) * drag.ratio, (event.clientY - drag.y) * drag.ratio);
    drag.x = event.clientX;
    drag.y = event.clientY;
  });
  canvas.addEventListener('pointerup', () => { drag = null; });
  canvas.addEventListener('pointercancel', () => { drag = null; });
  canvas.addEventListener('wheel', (event) => {
    event.preventDefault();
    setZoom(Number(zoom.value) - event.deltaY * 0.002);
  }, { passive: false });
  canvas.addEventListener('keydown', (event) => {
    const step = event.shiftKey ? 40 : 12;
    const moves = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
    if (moves[event.key]) {
      event.preventDefault();
      move(...moves[event.key]);
    } else if (event.key === '+' || event.key === '=') {
      event.preventDefault();
      setZoom(Number(zoom.value) + 0.1);
    } else if (event.key === '-') {
      event.preventDefault();
      setZoom(Number(zoom.value) - 0.1);
    }
  });
  zoom.addEventListener('input', () => setZoom(Number(zoom.value)));
  cancel.addEventListener('click', () => dialog.close());

  save.addEventListener('click', () => {
    save.disabled = true;
    canvas.toBlob(async (blob) => {
      try {
        if (!blob) throw new Error('The picture could not be prepared.');
        await session;
        const form = new FormData();
        form.append('avatar', blob, 'avatar.png');
        await apiUpload('/api/v1/account/avatar/upload.php', form);
        dialog.close();
        status.textContent = 'Picture saved.';
        if (user) refreshPhoto(user.id);
        await showState();
      } catch (error) {
        status.textContent = error instanceof ApiError || error instanceof Error ? error.message : 'Uploading failed.';
        dialog.close();
      } finally {
        save.disabled = false;
      }
    }, 'image/png');
  });

  removeButton.addEventListener('click', async () => {
    removeButton.disabled = true;
    try {
      await apiPost('/api/v1/account/avatar/remove.php', {});
      status.textContent = 'Picture removed; your initials are shown instead.';
      if (user) refreshPhoto(user.id);
      await showState();
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'Removing failed.';
    } finally {
      removeButton.disabled = false;
    }
  });

  try {
    user = (await session).user ?? null;
  } catch {
    user = null;
  }
  if (!user) return;
  preview.textContent = initials(user.username);
  preview.dataset.tone = String(avatarTone(user.id));
  preview.dataset.avatarUser = String(user.id);
  attachPhoto(preview, user.id);
  await showState();
  if (!fileInput.disabled) await offerProviderPictures();
  await continueFromProvider();

  // "Use my Google picture": only for connected providers, and only when
  // asked. That one round trip requests the picture; sign-in never does.
  async function offerProviderPictures() {
    let methods;
    try {
      methods = await apiGet('/api/v1/account/identities/list.php');
    } catch {
      return;
    }
    const configured = new Map((methods.providers ?? []).map((provider) => [provider.id, provider.label]));
    for (const identity of methods.identities ?? []) {
      const label = configured.get(identity.provider);
      if (!label) continue;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'secondary-button';
      button.textContent = `Use my ${label} picture`;
      button.addEventListener('click', async () => {
        button.disabled = true;
        status.textContent = '';
        try {
          const { url } = await apiPost('/api/v1/oidc/begin.php', { provider: identity.provider, purpose: 'picture' });
          window.location.assign(url);
        } catch (error) {
          status.textContent = error instanceof Error ? error.message : 'That did not work.';
          button.disabled = false;
        }
      });
      providerButtons.append(button);
    }
  }

  // Back from the provider: crop the fetched picture, or say why there is none.
  async function continueFromProvider() {
    const parameters = new URLSearchParams(window.location.search);
    const outcome = parameters.get('picture');
    if (!outcome) return;
    const failure = parameters.get('sign_in_error');
    parameters.delete('picture');
    parameters.delete('sign_in_error');
    const query = parameters.toString();
    window.history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
    if (outcome !== 'ready') {
      status.textContent = failure ?? 'The picture could not be fetched.';
      return;
    }
    try {
      const response = await fetch('/api/v1/account/avatar/imported.php', { credentials: 'same-origin' });
      if (!response.ok) throw new Error('The fetched picture has expired. Please fetch it again.');
      const blob = await response.blob();
      openCrop(new File([blob], 'picture', { type: blob.type }));
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'The picture could not be loaded.';
    }
  }
});
