// The Account page's "Date and time" card: a format region and a clock,
// each with Automatic, previewed live and saved to the account.
import { ApiError, apiGet, apiPost } from './api.js';
import { DATE_LOCALES, dateFormatPreference, formatDateTime, setDateFormatPreference } from './datetime.js';

window.addEventListener('DOMContentLoaded', async () => {
  const form = document.getElementById('date-time-form');
  const locale = document.getElementById('date-locale');
  const clock = document.getElementById('hour-cycle');
  const status = document.getElementById('date-time-status');
  const previewShort = document.getElementById('date-preview-short');
  const previewMedium = document.getElementById('date-preview-medium');
  if (!form || !locale || !clock || !status || !previewShort || !previewMedium) return;

  const browserLocale = new Intl.DateTimeFormat().resolvedOptions().locale;
  locale.options[0].textContent = `Automatic (${browserLocale})`;
  for (const [code, label] of DATE_LOCALES) {
    locale.append(new Option(label, code));
  }

  const chosen = () => ({ date_locale: locale.value || null, hour_cycle: clock.value || null });
  const preview = () => {
    const now = new Date();
    previewShort.textContent = formatDateTime(now, { dateStyle: 'short', timeStyle: 'short' }, chosen());
    previewMedium.textContent = formatDateTime(now, { dateStyle: 'medium', timeStyle: 'short' }, chosen());
  };
  const show = (preference) => {
    locale.value = preference.date_locale ?? '';
    clock.value = preference.hour_cycle ?? '';
    preview();
  };

  // The session (which api.js applies) holds the saved choice.
  try {
    await apiGet('/api/v1/session.php');
  } catch {
    // Fall back to this device's cached choice.
  }
  show(dateFormatPreference());

  locale.addEventListener('change', () => { status.textContent = ''; preview(); });
  clock.addEventListener('change', () => { status.textContent = ''; preview(); });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    status.textContent = '';
    try {
      const response = await apiPost('/api/v1/account/display-preferences.php', chosen());
      setDateFormatPreference(response.preferences);
      show(dateFormatPreference());
      status.textContent = 'Saved. Dates and times now use this format wherever you sign in.';
    } catch (error) {
      status.textContent = error instanceof ApiError || error instanceof Error ? error.message : 'Saving failed.';
    } finally {
      button.disabled = false;
    }
  });
});
