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
 *
 * The links prefill the app's "Where do you chat?" with this server (contract agreed with
 * ChitChat Mobile on #417): the Play link carries it as an install referrer, and the intent
 * link opens an installed app with it. The app only prefills; the person still confirms. The
 * referrer key, the scheme and its host are fixed with the app.
 */
final class AndroidAppPromotion
{
    /**
     * The Play listing, without tracking or personal parameters. Null until the listing is
     * public; then 'https://play.google.com/store/apps/details?id=de.networkxxiii.chitchat'.
     *
     * @var ?string
     */
    public const STORE_URL = null;

    public const PACKAGE = 'de.networkxxiii.chitchat';
    private const REFERRER_KEY = 'chitchat_server';
    private const SCHEME = 'chitchat';
    private const SCHEME_HOST = 'connect';

    /** Whether the setting can take effect at all: the store URL is known. */
    public static function available(?string $storeUrl = self::STORE_URL): bool
    {
        return $storeUrl !== null;
    }

    /**
     * session.php's android_app: null unless the setting is on and the store URL is known.
     * Without an address (APP_ORIGIN unset or not https), store_url is the plain listing and
     * there's no open_url.
     *
     * @return array{store_url:string, open_url:?string, server_address:?string}|null
     */
    public static function toPublic(bool $enabled, Config $config, ?string $storeUrl = self::STORE_URL): ?array
    {
        if (!$enabled || $storeUrl === null) {
            return null;
        }
        $address = self::serverAddress($config);
        if ($address === null) {
            return ['store_url' => $storeUrl, 'open_url' => null, 'server_address' => null];
        }
        $store = $storeUrl . '&referrer=' . rawurlencode(self::REFERRER_KEY . '=' . $address);
        $open = 'intent://' . self::SCHEME_HOST . '?server=' . rawurlencode($address)
            . '#Intent;scheme=' . self::SCHEME . ';package=' . self::PACKAGE
            . ';S.browser_fallback_url=' . rawurlencode($store) . ';end';
        return ['store_url' => $store, 'open_url' => $open, 'server_address' => $address];
    }

    /**
     * The server's address as members type it into the app: APP_ORIGIN's host, lowercase, with
     * ":port" only when a port is given and isn't 443. No scheme, path or trailing slash. Null
     * when APP_ORIGIN is unset or isn't https (only local development). Only ever from
     * APP_ORIGIN, never from the request's Host header, which the client controls.
     */
    public static function serverAddress(Config $config): ?string
    {
        if ($config->applicationOrigin === '') {
            return null;
        }
        $parts = parse_url($config->applicationOrigin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === ''
            || strtolower($parts['scheme']) !== 'https') {
            return null;
        }
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        return $port === null || $port === 443 ? $host : $host . ':' . $port;
    }
}
