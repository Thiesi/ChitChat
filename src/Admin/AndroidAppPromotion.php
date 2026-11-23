<?php

declare(strict_types=1);
namespace ChitChat\Admin;

use ChitChat\Config;

/**
 * Pointing members to ChitChat for Android (#417): the generic app on Google Play, which works
 * with any installation. Whether it's shown is an Operational setting (system_settings.
 * android_app_promotion), but it only takes effect once STORE_URL is set, which a later release
 * does when the Play listing is public. Until then nothing is exposed anywhere, and the
 * setting can't be switched on.
 */
final class AndroidAppPromotion
{
    /**
     * The Play listing, a plain URL without tracking or personal parameters. Null until the
     * listing is public; then 'https://play.google.com/store/apps/details?id=de.networkxxiii.chitchat'.
     *
     * @var ?string
     */
    public const STORE_URL = null;

    /** Whether the setting can take effect at all: the store URL is known. */
    public static function available(?string $storeUrl = self::STORE_URL): bool
    {
        return $storeUrl !== null;
    }

    /**
     * session.php's android_app: null unless the setting is on and the store URL is known.
     * server_address is the bare host members type into the app, or null when APP_ORIGIN is unset.
     *
     * @return array{store_url:string, server_address:?string}|null
     */
    public static function toPublic(bool $enabled, Config $config, ?string $storeUrl = self::STORE_URL): ?array
    {
        if (!$enabled || $storeUrl === null) {
            return null;
        }
        return ['store_url' => $storeUrl, 'server_address' => self::serverAddress($config)];
    }

    /**
     * The server's address as members type it into the app: APP_ORIGIN's host, with its port only
     * when that isn't the scheme's default. No scheme, path or trailing slash. Only ever from
     * APP_ORIGIN, never from the request's Host header, which the client controls.
     */
    public static function serverAddress(Config $config): ?string
    {
        if ($config->applicationOrigin === '') {
            return null;
        }
        $parts = parse_url($config->applicationOrigin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $default = strtolower($parts['scheme']) === 'http' ? 80 : 443;
        return $port === null || $port === $default ? $host : $host . ':' . $port;
    }
}
