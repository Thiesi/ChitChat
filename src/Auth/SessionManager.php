<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use ChitChat\Config;
use ChitChat\Http\ApiException;

final class SessionManager
{
    public static function start(Config $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name($config->sessionName);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $config->sessionCookieSecure ? '1' : '0');
        ini_set('session.cookie_samesite', $config->sessionCookieSameSite);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $config->sessionCookieSecure,
            'httponly' => true,
            'samesite' => $config->sessionCookieSameSite,
        ]);

        if (!session_start()) {
            throw new ApiException(500, 'session_unavailable', 'Unable to start the session.');
        }
    }

    public static function csrfToken(): string
    {
        $token = $_SESSION['csrf_token'] ?? null;
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $token;
        }

        return $token;
    }

    public static function requireCsrf(string $providedToken): void
    {
        if ($providedToken === '' || !hash_equals(self::csrfToken(), $providedToken)) {
            throw new ApiException(403, 'csrf_failed', 'The CSRF token is missing or invalid.');
        }
    }

    public static function login(AuthenticatedUser $user): void
    {
        if (!session_regenerate_id(true)) {
            throw new ApiException(500, 'session_unavailable', 'Unable to rotate the session identifier.');
        }

        $_SESSION['auth'] = [
            'user_id' => $user->id,
            'session_version' => $user->sessionVersion,
            'authenticated_at' => time(),
        ];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public static function currentUser(UserRepository $users): ?AuthenticatedUser
    {
        $auth = $_SESSION['auth'] ?? null;
        if (!is_array($auth)) {
            return null;
        }

        $userId = $auth['user_id'] ?? null;
        $sessionVersion = $auth['session_version'] ?? null;
        if (!is_int($userId) || !is_int($sessionVersion)) {
            self::clearAuthentication();
            return null;
        }

        $user = $users->findAuthenticatedById($userId);
        if (
            $user === null
            || $user->sessionVersion !== $sessionVersion
            || $users->activeBan($userId) !== null
        ) {
            self::clearAuthentication();
            return null;
        }

        return $user;
    }

    public static function requireUser(UserRepository $users): AuthenticatedUser
    {
        $user = self::currentUser($users);
        if ($user === null) {
            throw new ApiException(401, 'authentication_required', 'Authentication is required.');
        }

        return $user;
    }

    private static function clearAuthentication(): void
    {
        unset($_SESSION['auth']);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            if (ini_get('session.use_cookies')) {
                $sessionName = session_name();
                if (!is_string($sessionName) || $sessionName === '') {
                    throw new ApiException(500, 'session_unavailable', 'Unable to resolve the session cookie name.');
                }

                setcookie($sessionName, '', [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'],
                ]);
            }
            session_destroy();
        }
    }
}
