// The one report dialog (markup: src/View/ReportDialog.php). It reports a
// message (room or direct) or a person (#159). A person report tells the
// reporter exactly what moderators receive, and afterwards offers to ignore
// or block the person, which is usually what someone wants next.
import { ApiError, apiPost } from './api.js';
import { miniAvatar } from './avatar.js';
import { isGuestName, markGuestAvatar } from './guest.js';
import { ALREADY_REPORTED, PERSON_CATEGORIES, REPORT_NOT_ALLOWED, reportPerson } from './person-reports.js';

const MESSAGE_TITLES = { room: 'Report message', direct: 'Report direct message' };
const MESSAGE_EXPLANATION = 'Moderators see a copy of this message only, not the rest of the conversation.';
const DETAILS_LIMIT = 1000;

let elements = null;
let target = null;
let opener = null;

window.addEventListener('DOMContentLoaded', () => {
  const ids = {
    dialog: 'message-report-dialog',
    form: 'message-report-form',
    title: 'message-report-title',
    explanation: 'message-report-explanation',
    person: 'report-person',
    personAvatar: 'report-person-avatar',
    personName: 'report-person-name',
    personExplanation: 'report-person-explanation',
    context: 'report-context',
    category: 'message-report-category',
    details: 'message-report-details',
    count: 'message-report-count',
    error: 'message-report-error',
    cancel: 'message-report-cancel',
    submit: 'message-report-submit',
    done: 'report-done',
    doneTitle: 'report-done-title',
    doneText: 'report-done-text',
    followUp: 'report-follow-up',
    followUpQuestion: 'report-follow-up-question',
    ignore: 'report-ignore',
    block: 'report-block',
    followUpStatus: 'report-follow-up-status',
    doneClose: 'report-done-close',
    toastRegion: 'toast-region',
  };
  const found = {};
  for (const [key, id] of Object.entries(ids)) {
    found[key] = document.getElementById(id);
    if (!found[key]) return;
  }
  elements = found;
  elements.form.addEventListener('submit', submitReport);
  elements.cancel.addEventListener('click', closeDialog);
  elements.doneClose.addEventListener('click', closeDialog);
  elements.details.addEventListener('input', renderCount);
  elements.ignore.addEventListener('click', ignorePerson);
  elements.block.addEventListener('click', blockPerson);
  elements.dialog.addEventListener('close', resetDialog);
});

/** Whether this page has the report dialog (the profile card offers "Report" only then). */
export function reportDialogAvailable() {
  return document.getElementById('message-report-dialog') !== null;
}

export function openMessageReportDialog(messageKind, messageId) {
  if (!elements || !['room', 'direct'].includes(messageKind) || !Number.isInteger(messageId) || messageId < 1) return;
  target = { kind: 'message', messageKind, messageId };
  prepare();
  elements.title.textContent = MESSAGE_TITLES[messageKind];
  elements.explanation.textContent = MESSAGE_EXPLANATION;
  elements.explanation.classList.remove('hidden');
  elements.person.classList.add('hidden');
  elements.context.classList.add('hidden');
  setPersonCategories(false);
  show();
}

/**
 * Opens the dialog for a person. `roomId` is the room the profile card was
 * opened in (its moderators can see the report too); null means global.
 * `ignored`/`blocked` hide follow-up offers that would do nothing.
 */
export function openPersonReportDialog({ userId, username, hasAvatar = false, roomId = null, roomName = null, ignored = false, blocked = false, reporterIsGuest = false }) {
  if (!elements || !Number.isInteger(userId) || userId < 1) return;
  target = { kind: 'person', userId, username, roomId: Number.isInteger(roomId) ? roomId : null, ignored, blocked, reporterIsGuest };
  prepare();
  elements.title.textContent = `Report ${username}`;
  elements.explanation.classList.add('hidden');
  elements.person.classList.remove('hidden');
  elements.personName.textContent = username;
  const avatar = miniAvatar(username, userId);
  if (isGuestName(username)) markGuestAvatar(avatar);
  elements.personAvatar.replaceChildren(avatar);
  elements.personExplanation.textContent = hasAvatar
    ? `Moderators see ${username}'s name and current profile picture. Nothing from your conversations is shared.`
    : `Moderators see ${username}'s name. Nothing from your conversations is shared.`;
  elements.context.classList.remove('hidden');
  elements.context.textContent = target.roomId !== null
    ? `Reported from #${roomName ?? 'this room'}. Moderators of this room can see it too.`
    : 'Global moderators review this report.';
  setPersonCategories(true, hasAvatar);
  show();
}

function prepare() {
  opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  elements.form.classList.remove('hidden');
  elements.done.classList.add('hidden');
  elements.error.textContent = '';
  elements.details.value = '';
  elements.submit.textContent = 'Report';
  renderCount();
}

function show() {
  elements.category.value = 'harassment';
  elements.dialog.showModal();
  elements.category.focus();
}

function setPersonCategories(person, hasAvatar = false) {
  for (const option of elements.category.querySelectorAll('option[data-person-only]')) option.remove();
  if (!person) return;
  const first = elements.category.firstElementChild;
  for (const [value, label] of PERSON_CATEGORIES) {
    if (value === 'offensive_profile_picture' && !hasAvatar) continue;
    const option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    option.dataset.personOnly = 'true';
    elements.category.insertBefore(option, first);
  }
}

function renderCount() {
  const length = elements.details.value.length;
  elements.count.textContent = `${length} / ${DETAILS_LIMIT}`;
  // Spoken only near the limit; otherwise the counter stays quiet.
  elements.count.setAttribute('aria-live', length >= 900 ? 'polite' : 'off');
}

async function submitReport(event) {
  event.preventDefault();
  if (!elements || !target) return;
  const reported = { ...target };
  setBusy(true);
  elements.error.textContent = '';
  try {
    if (reported.kind === 'person') {
      await reportPerson({
        userId: reported.userId,
        category: elements.category.value,
        details: elements.details.value,
        roomId: reported.roomId,
      });
      showDone(reported, false);
      window.dispatchEvent(new CustomEvent('chitchat:person-reported', { detail: { userId: reported.userId } }));
      return;
    }
    await apiPost('/api/v1/reports/message.php', {
      message_kind: reported.messageKind,
      message_id: reported.messageId,
      category: elements.category.value,
      details: elements.details.value || null,
    });
    elements.dialog.close();
    toast('Report submitted. Moderators see only the reported message.');
    window.dispatchEvent(new CustomEvent('chitchat:message-reported', { detail: { messageKind: reported.messageKind, messageId: reported.messageId } }));
  } catch (error) {
    if (reported.kind === 'person' && error instanceof ApiError && error.code === ALREADY_REPORTED) {
      showDone(reported, true);
      return;
    }
    elements.error.textContent = errorMessage(error, reported);
  } finally {
    setBusy(false);
  }
}

function showDone(reported, already) {
  elements.form.classList.add('hidden');
  elements.done.classList.remove('hidden');
  elements.doneTitle.textContent = already ? `You've already reported ${reported.username}` : 'Thanks, your report is in';
  elements.doneText.textContent = already
    ? 'Moderators have it.'
    : 'Moderators will take a look. You won’t get a message about the outcome.';
  const offerIgnore = !reported.ignored && !isGuestName(reported.username);
  const offerBlock = !reported.blocked && !reported.reporterIsGuest && !isGuestName(reported.username);
  elements.ignore.classList.toggle('hidden', !offerIgnore);
  elements.block.classList.toggle('hidden', !offerBlock);
  elements.followUp.classList.toggle('hidden', !offerIgnore && !offerBlock);
  elements.followUpQuestion.textContent = `Want to stop seeing ${reported.username}?`;
  elements.followUpStatus.textContent = '';
  elements.dialog.setAttribute('aria-labelledby', 'report-done-title');
  elements.doneTitle.focus();
}

async function ignorePerson() {
  if (!target) return;
  const { userId, username } = target;
  await followUp(elements.ignore, () => apiPost('/api/v1/users/ignore.php', { user_id: userId, ignored: true }), `${username} is ignored in rooms.`, () => {
    window.dispatchEvent(new CustomEvent('chitchat:ignore-changed', { detail: { userId, ignored: true } }));
  });
}

async function blockPerson() {
  if (!target) return;
  const { userId, username } = target;
  await followUp(elements.block, () => apiPost('/api/v1/direct-messages/block.php', { user_id: userId }), `${username} can no longer send you direct messages.`, () => {
    window.dispatchEvent(new CustomEvent('chitchat:block-changed', { detail: { userId, blocked: true } }));
  });
}

async function followUp(button, request, confirmation, after) {
  button.disabled = true;
  try {
    await request();
    button.classList.add('hidden');
    elements.followUpStatus.textContent = confirmation;
    after();
    if (elements.ignore.classList.contains('hidden') && elements.block.classList.contains('hidden')) elements.doneClose.focus();
  } catch (error) {
    elements.followUpStatus.textContent = error instanceof Error ? error.message : 'That didn’t work. Try again.';
  } finally {
    button.disabled = false;
  }
}

function closeDialog() {
  elements?.dialog.close();
}

function resetDialog() {
  target = null;
  if (!elements) return;
  elements.form.reset();
  elements.error.textContent = '';
  elements.dialog.setAttribute('aria-labelledby', 'message-report-title');
  setBusy(false);
  setPersonCategories(false);
  if (opener?.isConnected) opener.focus();
  opener = null;
}

function setBusy(busy) {
  if (!elements) return;
  elements.category.disabled = busy;
  elements.details.disabled = busy;
  elements.cancel.disabled = busy;
  elements.submit.disabled = busy;
  elements.submit.textContent = busy ? 'Reporting…' : 'Report';
}

function toast(message) {
  if (!elements) return;
  const node = document.createElement('div');
  node.className = 'toast info';
  node.textContent = message;
  elements.toastRegion.append(node);
  window.setTimeout(() => node.remove(), 5000);
}

function errorMessage(error, reported) {
  if (error instanceof ApiError) {
    if (error.code === REPORT_NOT_ALLOWED) return 'You can’t report this person from here.';
    if (error.status === 404 && reported.kind === 'person') return 'This account is no longer available.';
    if (error.status === 429) return 'You’ve sent several reports quickly. Try again in a few minutes.';
    return error.message;
  }
  if (error instanceof TypeError) return 'The report couldn’t be sent. Check your connection and try again.';
  return error instanceof Error ? error.message : 'The report could not be submitted.';
}
