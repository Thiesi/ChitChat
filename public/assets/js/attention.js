// Gets attention for pings, mentions, and direct messages: a short sound
// (synthesized with Web Audio, no files and no third party) and, while the
// tab is in the background, a count in the page title. Each sound has its
// own per-device switch; all default to on. Sounds only play once the page
// has had a user interaction, as browsers require, so they supplement the
// visible notice and never replace it.

const STORAGE_KEY = 'chitchat.sounds';
export const SOUND_KINDS = ['ping', 'mention', 'dm'];

let context = null;
let unread = 0;
let baseTitle = document.title;

export function soundEnabled(kind) {
  const stored = readSettings();
  return stored[kind] !== false;
}

export function setSoundEnabled(kind, enabled) {
  const stored = readSettings();
  stored[kind] = enabled;
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(stored));
  } catch {
    // Storage may be unavailable (private windows, blocked site data); sounds stay on.
  }
  if (enabled) void play(kind);
}

/**
 * Something needs the user: play its sound and, in a background tab, show
 * it in the title ("(2) Ping from Alex · ChitChat") until the tab is seen.
 */
export function alertUser(kind, label) {
  if (soundEnabled(kind)) void play(kind);
  if (document.visibilityState === 'hidden') {
    unread += 1;
    document.title = `(${unread}) ${label} · ${baseTitle}`;
  }
}

function readSettings() {
  try {
    const parsed = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}');
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

// Each sound is a few short notes: [frequency in Hz, start offset in seconds, duration].
const TUNES = {
  ping: { wave: 'sine', volume: 0.22, notes: [[880, 0, 0.18], [1318.5, 0.14, 0.32]] },
  mention: { wave: 'triangle', volume: 0.2, notes: [[659.3, 0, 0.16], [987.8, 0.1, 0.2]] },
  dm: { wave: 'sine', volume: 0.18, notes: [[523.3, 0, 0.09], [784, 0.07, 0.14]] },
};

async function play(kind) {
  const tune = TUNES[kind];
  const audio = audioContext();
  if (!tune || !audio) return;
  if (audio.state === 'suspended') {
    try {
      await audio.resume();
    } catch {
      return;
    }
  }
  if (audio.state !== 'running') return;

  const start = audio.currentTime + 0.01;
  for (const [frequency, offset, duration] of tune.notes) {
    const oscillator = audio.createOscillator();
    const gain = audio.createGain();
    oscillator.type = tune.wave;
    oscillator.frequency.value = frequency;
    // A quick attack and an exponential fade read as a chime rather than a beep.
    gain.gain.setValueAtTime(0.0001, start + offset);
    gain.gain.exponentialRampToValueAtTime(tune.volume, start + offset + 0.015);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + duration);
    oscillator.connect(gain).connect(audio.destination);
    oscillator.start(start + offset);
    oscillator.stop(start + offset + duration + 0.02);
  }
}

function audioContext() {
  if (context) return context;
  const AudioContextClass = window.AudioContext ?? window.webkitAudioContext;
  if (!AudioContextClass) return null;
  try {
    context = new AudioContextClass();
  } catch {
    return null;
  }
  return context;
}

// Browsers only allow audio after a user gesture; the first one unlocks it.
for (const type of ['pointerdown', 'keydown']) {
  window.addEventListener(type, () => {
    const audio = audioContext();
    if (audio?.state === 'suspended') void audio.resume().catch(() => {});
  }, { once: true, capture: true });
}

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && unread > 0) {
    unread = 0;
    document.title = baseTitle;
  }
});

// Pages that rename themselves after load (the application name) update the base title.
window.addEventListener('DOMContentLoaded', () => {
  baseTitle = document.title;
});
