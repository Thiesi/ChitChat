<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;

/** Private PHP-session bindings. Neither value is a PHP SID or a push binding. */
final class NativeAuthSession
{
    public static function nativeHash(bool $create = false): ?string
    {
        $token = $_SESSION['native_auth_binding'] ?? null;
        if (!is_string($token) || !NativeAuthConfiguration::validToken($token)) {
            if (!$create) return null;
            $token = NativeAuthConfiguration::token();
            $_SESSION['native_auth_binding'] = $token;
        }
        return hash('sha256', $token);
    }

    public static function invalidate(): void
    {
        unset($_SESSION['native_auth_binding'], $_SESSION['native_auth_browser'],
            $_SESSION['native_auth_continuation']);
        $pending = $_SESSION['pending_mfa'] ?? null;
        if (is_array($pending) && isset($pending['native_transaction'])) {
            unset($_SESSION['pending_mfa'], $_SESSION['webauthn_ceremony']);
        }
        $signup = $_SESSION['oidc_sign_up'] ?? null;
        if (is_array($signup) && isset($signup['native_transaction'])) unset($_SESSION['oidc_sign_up']);
    }

    /** @return array{transaction_id:string,binding_hash:string,identity:string}|null */
    public static function browser(?AuthenticatedUser $actor): ?array
    {
        $state = $_SESSION['native_auth_browser'] ?? null;
        if (!is_array($state) || !is_string($state['transaction_id'] ?? null)
            || !is_string($state['token'] ?? null) || !is_string($state['identity'] ?? null)
            || !NativeAuthConfiguration::validToken($state['token'])) return null;
        if (!hash_equals($state['identity'], self::identity($actor))) {
            unset($_SESSION['native_auth_browser']);
            return null;
        }
        return ['transaction_id' => $state['transaction_id'], 'binding_hash' => hash('sha256', $state['token']),
            'identity' => $state['identity']];
    }

    public static function bindBrowser(string $transactionId, ?AuthenticatedUser $actor): string
    {
        $token = NativeAuthConfiguration::token();
        $_SESSION['native_auth_browser'] = ['transaction_id' => $transactionId, 'token' => $token,
            'identity' => self::identity($actor)];
        return hash('sha256', $token);
    }

    private static function identity(?AuthenticatedUser $actor): string
    {
        return $actor === null ? 'anonymous' : $actor->id . ':' . $actor->sessionVersion;
    }

    /** @return array{transaction_id:string,subject:string}|null */
    public static function continuation(): ?array
    {
        $context = $_SESSION['native_auth_continuation'] ?? null;
        if (!is_array($context) || !is_string($context['transaction_id'] ?? null)
            || !is_string($context['subject'] ?? null)) return null;
        return ['transaction_id' => $context['transaction_id'], 'subject' => $context['subject']];
    }

    public static function setContinuation(string $transactionId, string $subject): void
    {
        if (self::nativeHash() === null) {
            throw new ApiException(401, 'native_session_changed', 'Start again from the app.');
        }
        $_SESSION['native_auth_continuation'] = ['transaction_id' => $transactionId, 'subject' => $subject];
    }

    public static function clearContinuation(): void
    {
        unset($_SESSION['native_auth_continuation']);
    }
}
