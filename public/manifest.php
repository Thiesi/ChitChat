<?php

declare(strict_types=1);

/*
 * The web app manifest (S0, #167): makes ChitChat installable on phones and
 * desktops. It is PHP rather than a static file because the installation's
 * name is set by administrators. Pages link it once S0 is wired.
 */

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__) . '/bootstrap/app.php';
$name = \ChitChat\Admin\ApplicationNameService::resolve($config);
$shortName = mb_strlen($name) <= 12 ? $name : rtrim(mb_substr($name, 0, 12));

$repository = new \ChitChat\InstallationIcon\IconRepository(\ChitChat\Database::connect($config));
$revision = $repository->currentRevision();
$generation = $revision === null ? null : ($repository->find($revision)['generation'] ?? null);
$customIcons = null;
if ($generation !== null) {
    $renditions = $generation->renditions();
    $customIcons = [];
    foreach (['icon_192', 'icon_512', 'maskable'] as $variant) {
        $icon = $renditions[$variant];
        $customIcons[] = ['src' => $icon['url'], 'sizes' => $icon['width'] . 'x' . $icon['height'],
            'type' => $icon['mime_type'], 'purpose' => $variant === 'maskable' ? 'maskable' : 'any'];
    }
}

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo json_encode([
    'name' => $name,
    'short_name' => $shortName,
    'description' => $name . ' — a self-hosted chat for your people.',
    'id' => '/',
    'start_url' => '/',
    'scope' => '/',
    'display' => 'standalone',
    // Night ink, as behind the mark, so the splash screen matches the icon.
    'background_color' => '#14111C',
    'theme_color' => '#14111C',
    'icons' => $customIcons ?? [
        ['src' => '/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
        ['src' => '/assets/app-icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/app-icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/app-icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
