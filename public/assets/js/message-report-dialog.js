import { ApiError, apiPost } from './api.js';

let elements = null;
let target = null;

window.addEventListener('DOMContentLoaded', () => {
  const dialog = document.getElementById('message-report-dialog');
  const form = document.getElementById('message-report-form');
  const category = document.getElementById('message-report-category');
  const details = document.getElementById('message-report-details');
  const error = document.getElementById('message-report-error');
  const cancel = document.getElementById('message-report-cancel');
  const submit = document.getElementById('message-report-submit');
  const toastRegion = document.getElementById('toast-region');
  if (!dialog || !form || !category || !details || !error || !cancel || !submit || !toastRegion) return;

  elements = { dialog, form, category, details, error, cancel, submit, toastRegion };
  form.addEventListener('submit', submitReport);
  cancel.addEventListener('click', closeDialog);
  dialog.addEventListener('close', resetDialog);
});

export function openMessageReportDialog(messageKind, messageId) {
  if (!elements || !['room', 'direct'].includes(messageKind) || !Number.isInteger(messageId) || messageId < 1) return;
  target = { messageKind, messageId };
  elements.error.textContent = '';
  elements.category.value = 'harassment';
  elements.details.value = '';
  elements.dialog.showModal();
  elements.category.focus();
}

async function submitReport(event) {
  event.preventDefault();
  if (!elements || !target) return;
  const reportedTarget = { ...target };
  setBusy(true);
  elements.error.textContent = '';
  try {
    await apiPost('/api/v1/reports/message.php', {
      message_kind: reportedTarget.messageKind,
      message_id: reportedTarget.messageId,
      category: elements.category.value,
      details: elements.details.value || null,
    });
    elements.dialog.close();
    toast('Report submitted. Moderators can review only the reported evidence.');
    window.dispatchEvent(new CustomEvent('chitchat:message-reported', { detail: reportedTarget }));
  } catch (error) {
    elements.error.textContent = errorMessage(error);
  } finally {
    setBusy(false);
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
  setBusy(false);
}

function setBusy(busy) {
  if (!elements) return;
  elements.category.disabled = busy;
  elements.details.disabled = busy;
  elements.cancel.disabled = busy;
  elements.submit.disabled = busy;
}

function toast(message) {
  if (!elements) return;
  const node = document.createElement('div');
  node.className = 'toast info';
  node.textContent = message;
  elements.toastRegion.append(node);
  window.setTimeout(() => node.remove(), 5000);
}

function errorMessage(error) {
  if (error instanceof ApiError || error instanceof Error) return error.message;
  return 'The report could not be submitted.';
}
