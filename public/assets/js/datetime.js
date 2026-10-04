// One formatter for every date and time ChitChat shows. It follows the
// account's date-and-time preference (a format region and a 12/24-hour
// clock), or the browser's own settings when the account leaves them on
// Automatic. The time zone is always the device's.
//
// The preference arrives with the session (see api.js) and is cached on
// this device, so pages format correctly before the session has loaded.

const STORAGE_KEY = 'chitchat.dateFormat';

/** Format regions offered on the Account page; the server accepts exactly these. */
export const DATE_LOCALES = [
  ['en-US', 'English (United States)'],
  ['en-GB', 'English (United Kingdom)'],
  ['en-AU', 'English (Australia)'],
  ['de-DE', 'Deutsch (Deutschland)'],
  ['de-AT', 'Deutsch (Österreich)'],
  ['de-CH', 'Deutsch (Schweiz)'],
  ['fr-FR', 'Français (France)'],
  ['es-ES', 'Español (España)'],
  ['it-IT', 'Italiano (Italia)'],
  ['nl-NL', 'Nederlands (Nederland)'],
  ['pl-PL', 'Polski (Polska)'],
  ['pt-BR', 'Português (Brasil)'],
  ['sv-SE', 'Svenska (Sverige)'],
  ['ja-JP', '日本語 (日本)'],
];

let preference = readCachedPreference();

/** @returns {{ date_locale: string | null, hour_cycle: 'h12' | 'h23' | null }} */
export function dateFormatPreference() {
  return { ...preference };
}

/** Called with the session's preferences (null when signed out). */
export function setDateFormatPreference(value) {
  preference = normalize(value);
  try {
    if (preference.date_locale === null && preference.hour_cycle === null) {
      window.localStorage.removeItem(STORAGE_KEY);
    } else {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(preference));
    }
  } catch {
    // Storage may be unavailable; the preference still applies for this page.
  }
}

/**
 * @param {string | number | Date | null | undefined} value
 * @param {{ dateStyle?: 'short' | 'medium' | 'long' | null, timeStyle?: 'short' | 'medium' | null }} [style]
 * @param {{ date_locale: string | null, hour_cycle: string | null }} [override] for previews
 */
export function formatDateTime(value, { dateStyle = 'medium', timeStyle = 'short' } = {}, override = null) {
  const date = value instanceof Date ? value : new Date(value ?? '');
  if (Number.isNaN(date.getTime())) return String(value ?? '');

  const chosen = override ? normalize(override) : preference;
  const options = {};
  if (dateStyle) options.dateStyle = dateStyle;
  if (timeStyle) {
    options.timeStyle = timeStyle;
    if (chosen.hour_cycle) options.hourCycle = chosen.hour_cycle;
  }
  try {
    return new Intl.DateTimeFormat(chosen.date_locale ?? undefined, options).format(date);
  } catch {
    return date.toLocaleString();
  }
}

function normalize(value) {
  const locale = typeof value?.date_locale === 'string'
    && DATE_LOCALES.some(([code]) => code === value.date_locale)
    ? value.date_locale
    : null;
  const hourCycle = value?.hour_cycle === 'h12' || value?.hour_cycle === 'h23' ? value.hour_cycle : null;
  return { date_locale: locale, hour_cycle: hourCycle };
}

function readCachedPreference() {
  try {
    return normalize(JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? 'null'));
  } catch {
    return normalize(null);
  }
}
