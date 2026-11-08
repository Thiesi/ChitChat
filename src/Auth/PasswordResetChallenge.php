<?php

declare(strict_types=1);

namespace ChitChat\Auth;

/** Reset always requires a one-use nonce, including at puzzle difficulty zero. */
final readonly class PasswordResetChallenge
{
    public const SESSION_KEY = 'password_reset_challenge';
    public const TTL_SECONDS = 300;

    public function __construct(private int $bits)
    {
    }

    /**
     * @param array<mixed> $session
     * @return array{nonce:string,bits:int}
     */
    public function issue(array &$session, int $now): array
    {
        $nonce = bin2hex(random_bytes(16));
        $session[self::SESSION_KEY] = ['nonce' => $nonce, 'bits' => $this->bits, 'issued_at' => $now];
        return ['nonce' => $nonce, 'bits' => $this->bits];
    }

    /** @param array<mixed> $session */
    public function consume(array &$session, mixed $nonce, mixed $solution, int $now): bool
    {
        $state = $session[self::SESSION_KEY] ?? null;
        unset($session[self::SESSION_KEY]);
        if (!is_array($state)) {
            return false;
        }
        $stored = $state['nonce'] ?? null;
        $issuedAt = $state['issued_at'] ?? null;
        $bits = $state['bits'] ?? null;
        return is_string($stored) && is_string($nonce) && hash_equals($stored, $nonce)
            && is_int($issuedAt) && $now >= $issuedAt && $now - $issuedAt < self::TTL_SECONDS
            && is_int($bits) && $bits >= 0 && $bits <= 22
            && is_string($solution) && preg_match('/\A[0-9]{1,15}\z/D', $solution) === 1
            && RegistrationChallenge::meetsDifficulty(hash('sha256', $stored . ':' . $solution, true), $bits);
    }
}
