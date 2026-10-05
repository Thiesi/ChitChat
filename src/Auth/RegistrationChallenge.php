<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use ChitChat\Http\ApiException;

/**
 * Unobtrusive bot resistance for public registration.
 *
 * The browser fetches a one-time challenge when the registration form is
 * shown and solves a small proof-of-work puzzle while the person fills in
 * the form. On submission the server checks a hidden decoy field, that the
 * form was not submitted faster than a person could fill it, and the puzzle
 * solution. The challenge lives in the session and is consumed by every
 * submission, successful or not.
 */
final class RegistrationChallenge
{
    private const TTL_SECONDS = 1800;

    public function __construct(
        private readonly int $minimumFillSeconds,
        private readonly int $proofOfWorkBits,
        // Guest starts keep their own challenge, so they never disturb an open registration form.
        private readonly string $sessionKey = 'registration_challenge',
    ) {
    }

    /** Whether submissions must carry a solved challenge. The decoy field is always checked. */
    public function required(): bool
    {
        return $this->minimumFillSeconds > 0 || $this->proofOfWorkBits > 0;
    }

    /**
     * @param array<mixed> $session
     * @return array{nonce: string, bits: int}|null
     */
    public function issue(array &$session, int $now): ?array
    {
        if (!$this->required()) {
            unset($session[$this->sessionKey]);
            return null;
        }

        $nonce = bin2hex(random_bytes(16));
        $session[$this->sessionKey] = ['nonce' => $nonce, 'issued_at' => $now];

        return ['nonce' => $nonce, 'bits' => $this->proofOfWorkBits];
    }

    /** @param array<mixed> $session */
    public function verify(array &$session, mixed $decoy, mixed $nonce, mixed $solution, int $now): void
    {
        $state = $session[$this->sessionKey] ?? null;
        unset($session[$this->sessionKey]);

        if ($decoy !== null && $decoy !== '') {
            throw new ApiException(400, 'registration_rejected', 'Registration could not be completed.');
        }
        if (!$this->required()) {
            return;
        }

        $storedNonce = is_array($state) ? ($state['nonce'] ?? null) : null;
        $issuedAt = is_array($state) ? ($state['issued_at'] ?? null) : null;
        if (!is_string($storedNonce) || !is_int($issuedAt) || !is_string($nonce) || !hash_equals($storedNonce, $nonce)) {
            throw new ApiException(400, 'registration_challenge_missing', 'Reload the page and try registering again.');
        }
        if ($now - $issuedAt > self::TTL_SECONDS) {
            throw new ApiException(400, 'registration_challenge_expired', 'The registration form expired. Please try again.');
        }
        if ($now - $issuedAt < $this->minimumFillSeconds) {
            throw new ApiException(400, 'registration_too_fast', 'Please take a moment and try registering again.');
        }
        if (
            !is_string($solution)
            || preg_match('/\A[0-9]{1,15}\z/D', $solution) !== 1
            || !self::meetsDifficulty(hash('sha256', $nonce . ':' . $solution, true), $this->proofOfWorkBits)
        ) {
            throw new ApiException(400, 'registration_challenge_failed', 'Registration could not be verified. Please try again.');
        }
    }

    /** Whether the binary hash starts with at least $bits zero bits. */
    public static function meetsDifficulty(string $hash, int $bits): bool
    {
        $fullBytes = intdiv($bits, 8);
        for ($index = 0; $index < $fullBytes; $index += 1) {
            if (ord($hash[$index]) !== 0) {
                return false;
            }
        }
        $remainingBits = $bits % 8;
        return $remainingBits === 0 || (ord($hash[$fullBytes]) >> (8 - $remainingBits)) === 0;
    }
}
