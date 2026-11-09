<?php

declare(strict_types=1);

// Presentation fixture for #146 (not served; outside public/). Reads
// {"view": ConfirmationView, "now": ISO} as JSON on stdin and prints the
// confirmation page exactly as NativeAuthPage renders it.
require dirname(__DIR__, 3) . '/vendor/autoload.php';

$input = json_decode((string) stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
if (!is_array($input) || !is_array($input['view'] ?? null)) {
    fwrite(STDERR, "Expected {\"view\": {...}, \"now\": \"...\"} on stdin.\n");
    exit(1);
}
$now = is_string($input['now'] ?? null) ? new DateTimeImmutable($input['now']) : null;
echo ChitChat\View\NativeAuthPage::render($input['view'], $now);
