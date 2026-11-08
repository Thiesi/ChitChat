<?php

declare(strict_types=1);
namespace ChitChat\View;

/**
 * The one report dialog, shared by the chat and direct-message pages. It
 * reports a message or a person (#159); message-report-dialog.js fills in
 * the mode, the person's name and picture, and the room context.
 */
final class ReportDialog
{
    public static function html(): string
    {
        return <<<'HTML'
  <dialog id="message-report-dialog" class="room-dialog message-report-dialog" aria-labelledby="message-report-title">
    <form id="message-report-form" class="form-stack">
      <header class="dialog-header">
        <div>
          <h2 id="message-report-title">Report message</h2>
          <p id="message-report-explanation">Moderators see a copy of this message only, not the rest of the conversation.</p>
        </div>
      </header>
      <div id="report-person" class="report-person hidden">
        <span id="report-person-avatar" class="report-person-avatar" aria-hidden="true"></span>
        <div>
          <strong id="report-person-name"></strong>
          <p id="report-person-explanation"></p>
        </div>
      </div>
      <p id="report-context" class="report-context hidden"></p>
      <label>What's wrong?
        <select id="message-report-category" required>
          <option value="spam">Spam</option>
          <option value="harassment">Harassment</option>
          <option value="hate">Hate speech</option>
          <option value="threats">Threats or violence</option>
          <option value="sexual_content">Sexual content</option>
          <option value="privacy">Privacy violation</option>
          <option value="impersonation">Impersonation</option>
          <option value="other">Other</option>
        </select>
      </label>
      <label>Anything moderators should know? <span class="optional-label">optional</span>
        <textarea id="message-report-details" maxlength="1000" rows="5" aria-describedby="message-report-count"></textarea>
      </label>
      <p id="message-report-count" class="report-count" aria-live="polite">0 / 1000</p>
      <p id="message-report-error" class="error-text" role="alert"></p>
      <div class="action-row">
        <button id="message-report-cancel" class="secondary-button" type="button">Cancel</button>
        <button id="message-report-submit" class="danger-button" type="submit">Report</button>
      </div>
    </form>
    <div id="report-done" class="report-done form-stack hidden">
      <h2 id="report-done-title" tabindex="-1">Thanks, your report is in</h2>
      <p id="report-done-text">Moderators will take a look. You won't get a message about the outcome.</p>
      <div id="report-follow-up" class="report-follow-up">
        <p id="report-follow-up-question"></p>
        <div class="report-follow-up-actions">
          <button id="report-ignore" class="secondary-button" type="button">Ignore in rooms</button>
          <button id="report-block" class="secondary-button" type="button">Block direct messages</button>
        </div>
        <p id="report-follow-up-status" class="report-follow-up-status" role="status"></p>
      </div>
      <div class="action-row">
        <button id="report-done-close" class="primary-button" type="button">Done</button>
      </div>
    </div>
  </dialog>
HTML;
    }
}
