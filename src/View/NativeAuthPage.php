<?php

declare(strict_types=1);

namespace ChitChat\View;

use DateTimeImmutable;
use Throwable;

/**
 * The browser confirmation page for the native app handoff (#146; spec in
 * issue comment 6732535902, amended for CCM's review). A pure renderer: one
 * view model in, one HTML document out. No headers, no I/O, no session, no
 * script apart from the theme. The controller sets the headers and status.
 *
 * Ground rules the copy keeps:
 * - Opening the page is never consent: every action is a CSRF-protected POST.
 * - It never claims the app succeeded. After approval it says "Return
 *   requested"; success appears only for a narrow, server-recorded outcome.
 * - The return to the app is a link the person follows, labelled "Return to
 *   the ChitChat app", with code-free fallback text. The one-use code exists
 *   only inside that link's href: never in text, titles or labels.
 * - An existing browser account is shown only to explain a mismatch.
 *
 * @phpstan-import-type ConfirmationView from \ChitChat\Auth\NativeAuth\NativeAuthService
 */
final class NativeAuthPage
{
    public const INTEGRATED = true;

    private const ACTIVE = ['entry', 'verifying', 'consent', 'account_mismatch', 'identity_mismatch', 'staged'];

    private const CLOSE_TAB = '<p class="native-auth-small">You can close this tab and go back to the app.</p>';

    public static function integrated(): bool
    {
        return self::INTEGRATED;
    }

    /** @param array<string,mixed> $view */
    public static function render(array $view, ?DateTimeImmutable $now = null): string
    {
        $installation = self::text($view['installation_name'] ?? '');
        $installation = $installation !== '' ? $installation : 'ChitChat';
        $phase = self::text($view['phase'] ?? '');
        $card = self::card($view, $phase, $now ?? new DateTimeImmutable());
        $e = self::escape(...);
        // #211: the installation's own icon when it has one; null or missing means Lantern.
        $icon = is_array($view['installation_icon'] ?? null) ? $view['installation_icon'] : [];
        $faviconUrl = self::isUrl($icon['favicon_url'] ?? null) ? (string) $icon['favicon_url'] : null;
        $markUrl = self::isUrl($icon['mark_url'] ?? null) ? (string) $icon['mark_url'] : null;
        $favicon = $faviconUrl !== null
            ? '<link rel="icon" href="' . $e($faviconUrl) . '" type="image/png" sizes="32x32">'
            : '<link rel="icon" href="/favicon.svg" type="image/svg+xml">';
        $mark = $markUrl !== null
            ? '<img class="native-auth-mark native-auth-mark-custom" src="' . $e($markUrl) . '" alt="" width="40" height="40">'
            : '<img class="native-auth-mark" src="/favicon.svg" alt="" width="40" height="40">';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <script src="/assets/js/theme.js"></script>
  <title>{$e($card['title'])} · {$e($installation)}</title>
  {$favicon}
  <meta name="theme-color" content="#FBF7F1" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#14111C" media="(prefers-color-scheme: dark)">
  <link rel="stylesheet" href="/assets/css/tokens.css">
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/native-auth.css">
</head>
<body>
  <main class="native-auth" data-phase="{$e($phase)}">
    <section class="native-auth-card" aria-labelledby="native-auth-heading">
      <header class="native-auth-header">
        {$mark}
        <h1>{$e($installation)}</h1>
      </header>
      <h2 id="native-auth-heading">{$e($card['heading'])}</h2>
{$card['body']}
    </section>
  </main>
</body>
</html>
HTML;
    }

    /**
     * Headings and titles come back as plain text (render() escapes them);
     * bodies come back as HTML with every value already escaped.
     *
     * @param array<string,mixed> $view
     * @return array{title:string,heading:string,body:string}
     */
    private static function card(array $view, string $phase, DateTimeImmutable $now): array
    {
        $app = self::text($view['app_name'] ?? '');
        $app = $app !== '' ? $app : 'The ChitChat app';
        $provider = self::text(is_array($view['provider'] ?? null) ? ($view['provider']['label'] ?? '') : '');
        $providerName = $provider !== '' ? $provider : 'your sign-in provider';
        $bound = is_array($view['bound_account'] ?? null) ? self::text($view['bound_account']['username'] ?? '') : '';
        $browser = is_array($view['browser_account'] ?? null) ? self::text($view['browser_account']['username'] ?? '') : '';
        $purpose = self::text($view['purpose'] ?? '');
        $outcome = is_string($view['outcome'] ?? null) ? $view['outcome'] : null;
        $actions = is_array($view['actions'] ?? null) ? $view['actions'] : [];
        $csrf = self::text($view['csrf_token'] ?? '');
        $sentence = self::purposeSentence($purpose, $providerName, $bound);
        $e = self::escape(...);

        $details = in_array($phase, self::ACTIVE, true) ? self::details($bound, $provider, $view['expires_at'] ?? null, $now) : '';
        $cancel = self::form($actions['cancel'] ?? null, $csrf, 'Cancel', 'secondary-button');
        $origin = '<p class="native-auth-small">You started this in the ChitChat app. If you didn’t, cancel.</p>';
        $startAgain = '<p>Start again from the ChitChat app.</p>';

        return match ($phase) {
            'entry' => [
                'title' => 'Confirm',
                'heading' => "{$app} wants to {$sentence}.",
                'body' => self::join($details, self::actions(
                    self::form($actions['continue'] ?? null, $csrf, "Continue with {$providerName}", 'primary-button'), $cancel), $origin),
            ],
            'verifying' => self::isUrl($actions['continue'] ?? null)
                // A provider callback parked until this browser confirms it (resume.php).
                ? ['title' => "Back from {$providerName}", 'heading' => "Back from {$providerName}.",
                    'body' => self::join('<p>Continue to finish.</p>', $details, self::actions(
                        self::form($actions['continue'], $csrf, 'Continue', 'primary-button'), $cancel), $origin)]
                : ['title' => "Waiting for {$providerName}", 'heading' => "Waiting for {$providerName}…",
                    'body' => self::join('<p>Finish on ' . $e($providerName) . '’s page. If you closed it, try again.</p>', $details,
                        self::actions(self::form($actions['restart'] ?? null, $csrf, 'Try again', 'secondary-button'), $cancel))],
            'consent' => [
                'title' => 'Allow?',
                'heading' => "Allow {$app} to {$sentence}?",
                'body' => self::join($provider !== '' ? '<p>Verified with ' . $e($provider) . '.</p>' : '', $details, self::actions(
                    self::form($actions['approve'] ?? null, $csrf, 'Approve', 'primary-button'), $cancel), $origin),
            ],
            'account_mismatch' => [
                'title' => 'Different account',
                'heading' => $browser !== '' && $bound !== ''
                    ? "This browser is signed in as {$browser}, but the app asked for {$bound}."
                    : 'This browser is signed in to a different account than the app asked for.',
                'body' => self::join('<p>That changes nothing about what the app gets.</p>', $details, self::actions(
                    self::form($actions['restart'] ?? null, $csrf, $bound !== '' ? "Continue as {$bound}" : 'Continue', 'primary-button'), $cancel)),
            ],
            'identity_mismatch' => [
                'title' => 'Different account',
                'heading' => $bound !== ''
                    ? "That {$providerName} account isn’t connected to {$bound}."
                    : "That {$providerName} account can’t be used for this.",
                'body' => self::join($details, self::actions(
                    self::form($actions['restart'] ?? null, $csrf, "Try another {$providerName} account", 'primary-button'), $cancel)),
            ],
            'staged' => [
                'title' => 'Return to the app',
                'heading' => 'Return to the app to finish.',
                'body' => self::join(
                    in_array($purpose, ['login', 'sign_up'], true)
                        ? '<p><strong>Return requested, not signed in yet.</strong> The app finishes signing you in.</p>' : '',
                    $details,
                    self::returnLink($view['return_url'] ?? null)
                        . '<p class="native-auth-small">Didn’t open? You can close this tab and go back to the app.</p>',
                    self::isUrl($view['status_url'] ?? null)
                        ? '<p class="native-auth-small"><a href="' . $e((string) $view['status_url']) . '">Check again</a></p>' : ''),
            ],
            'used' => self::outcome($outcome, $purpose, $providerName),
            'cancelled' => [
                'title' => 'Cancelled',
                'heading' => 'Cancelled.',
                'body' => self::join('<p>Nothing was applied by this request.</p>',
                    self::isUrl($view['cancel_return_url'] ?? null) ? self::returnLink($view['cancel_return_url']) : self::CLOSE_TAB),
            ],
            'expired' => ['title' => 'Link expired', 'heading' => 'This link has expired.', 'body' => self::join($startAgain)],
            // "disabled" and anything unexpected: the controller also lands here for errors.
            default => [
                'title' => 'Can’t continue',
                'heading' => 'This request can’t continue here.',
                'body' => self::join($startAgain, '<p class="native-auth-small">If it keeps happening, ask an administrator of '
                    . $e(self::text($view['installation_name'] ?? '') !== '' ? self::text($view['installation_name']) : 'this ChitChat')
                    . '.</p>'),
            ],
        };
    }

    /** @return array{title:string,heading:string,body:string} */
    private static function outcome(?string $outcome, string $purpose, string $provider): array
    {
        // Only a narrow, server-recorded outcome may claim completion. Sign-in and
        // step-up arrive as "unknown": a database record can't prove the app got its session.
        if ($outcome === 'completed' && in_array($purpose, ['link', 'picture', 'restore'], true)) {
            return match ($purpose) {
                'link' => ['title' => 'Connected', 'heading' => "Your {$provider} account is connected.", 'body' => self::join(self::CLOSE_TAB)],
                'picture' => ['title' => 'Picture ready', 'heading' => 'Picture ready in the app.',
                    'body' => self::join('<p>Crop it there before it’s used.</p>', self::CLOSE_TAB)],
                default => ['title' => 'Restored', 'heading' => 'Your account is restored.', 'body' => self::join(self::CLOSE_TAB)],
            };
        }

        return match ($outcome) {
            'pending_mfa' => ['title' => 'Almost there', 'heading' => 'Almost there: confirm it’s you in the app.', 'body' => self::join(self::CLOSE_TAB)],
            'pending_sign_up' => ['title' => 'Finish in the app', 'heading' => 'Finish creating your account in the app.', 'body' => self::join(self::CLOSE_TAB)],
            'unknown', 'completed' => ['title' => 'Return requested', 'heading' => 'Return requested.',
                'body' => self::join('<p>Check the app to see whether ' . (in_array($purpose, ['login', 'sign_up', 'step_up'], true)
                    ? 'you’re signed in' : 'it finished') . '.</p>', self::CLOSE_TAB)],
            default => ['title' => 'Already used', 'heading' => 'This link was already used.',
                'body' => self::join('<p>Start again from the ChitChat app if you need to.</p>')],
        };
    }

    private static function purposeSentence(string $purpose, string $provider, string $account): string
    {
        $target = $account !== '' ? $account : 'your account';

        return match ($purpose) {
            'link' => "connect your {$provider} account to {$target}",
            'picture' => "use your {$provider} profile picture for {$target}",
            'restore' => "restore {$target}",
            'step_up' => $account !== '' ? "confirm it’s you for {$account}" : 'confirm it’s you',
            'sign_up' => "create an account with your {$provider} account",
            default => 'sign you in',
        };
    }

    private static function details(string $bound, string $provider, mixed $expiresAt, DateTimeImmutable $now): string
    {
        $e = self::escape(...);
        $rows = '';
        if ($bound !== '') $rows .= '<div><dt>For</dt><dd>' . $e($bound) . '</dd></div>';
        if ($provider !== '') $rows .= '<div><dt>With</dt><dd>' . $e($provider) . '</dd></div>';
        $minutes = self::minutesLeft($expiresAt, $now);
        if ($minutes !== null && is_string($expiresAt)) {
            $rows .= '<div><dt>Expires</dt><dd><time datetime="' . $e($expiresAt) . '">'
                . ($minutes <= 1 ? 'in less than a minute' : "in {$minutes} min") . '</time></dd></div>';
        }

        return $rows === '' ? '' : '<dl class="native-auth-details">' . $rows . '</dl>';
    }

    private static function minutesLeft(mixed $expiresAt, DateTimeImmutable $now): ?int
    {
        if (!is_string($expiresAt) || $expiresAt === '') return null;
        try {
            $seconds = (new DateTimeImmutable($expiresAt))->getTimestamp() - $now->getTimestamp();
        } catch (Throwable) {
            return null;
        }

        return $seconds <= 0 ? null : (int) ceil($seconds / 60);
    }

    /** The only place the return URL (and its one-use code) is written: one href. */
    private static function returnLink(mixed $url): string
    {
        if (!self::isUrl($url)) return '';

        return '<div class="native-auth-actions"><a class="primary-button native-auth-return" href="'
            . self::escape((string) $url) . '">Return to the ChitChat app</a></div>';
    }

    private static function form(mixed $url, string $csrf, string $label, string $class): string
    {
        if (!self::isUrl($url)) return '';
        $e = self::escape(...);

        return '<form method="post" action="' . $e((string) $url) . '"><input type="hidden" name="csrf_token" value="' . $e($csrf)
            . '"><button class="' . $e($class) . '" type="submit">' . $e($label) . '</button></form>';
    }

    private static function actions(string ...$forms): string
    {
        $forms = array_filter($forms, static fn (string $form): bool => $form !== '');

        return $forms === [] ? '' : '<div class="native-auth-actions">' . implode('', $forms) . '</div>';
    }

    private static function join(string ...$parts): string
    {
        return '      ' . implode("\n      ", array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /** @phpstan-assert-if-true non-empty-string $value */
    private static function isUrl(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
