<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\Oidc\OidcProvider;
use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Auth\SessionManager;
use ChitChat\Config;

/** Server-only completion plan. Construct inside the DB transaction; install PHP state only after commit. */
final readonly class NativeAuthOutcome
{
    public function __construct(
        public string $transactionId,
        public string $purpose,
        public string $provider,
        public string $outcome,
        public ?AuthenticatedUser $user,
        #[\SensitiveParameter] public ?string $subject,
        public int $remainingSeconds,
        public ?string $factorMethod = null,
        public ?\ChitChat\Auth\IssuedLogin $login = null,
    ) {}

    public function withLogin(\ChitChat\Auth\IssuedLogin $login): self
    {
        return new self($this->transactionId, $this->purpose, $this->provider, $this->outcome, $this->user,
            $this->subject, $this->remainingSeconds, $this->factorMethod, $login);
    }

    /** @return array<string,mixed> */
    public function install(Config $config, string $ip, OidcService $oidc): array
    {
        if ($this->outcome === 'pending_sign_up') {
            SessionManager::rotateForNative();
            unset($_SESSION['pending_mfa'], $_SESSION['pending_password_reset'], $_SESSION['password_reset_challenge'],
                $_SESSION['privileged_step_up'], $_SESSION['webauthn_ceremony']);
            $_SESSION['oidc_sign_up'] = ['provider' => $this->provider, 'subject' => $this->subject,
                'created_at' => time(), 'native_transaction' => $this->transactionId];
            NativeAuthSession::setContinuation($this->transactionId, (string) $this->subject);
            $provider = OidcProvider::require($config, $this->provider);
            return ['outcome' => $this->outcome, 'purpose' => $this->purpose, 'user' => null,
                'csrf_token' => SessionManager::csrfToken(), 'pending_sign_up' => ['id' => $provider->name, 'label' => $provider->label]];
        }
        if ($this->user === null) throw new \RuntimeException('Native account completion is missing its account.');
        if ($this->outcome === 'pending_mfa') {
            SessionManager::beginMfaLogin($this->user, $ip,
                min($config->mfaPendingLoginTtlSeconds, max(1, $this->remainingSeconds)),
                $this->purpose === 'restore' ? 'restore' : 'login', $this->transactionId);
            NativeAuthSession::setContinuation($this->transactionId, (string) $this->subject);
            return ['outcome' => $this->outcome, 'purpose' => $this->purpose, 'user' => null,
                'csrf_token' => SessionManager::csrfToken(), 'mfa_required' => true,
                'methods' => ['passkey', 'recovery_code'], 'restored' => false,
                'restoration_pending' => $this->purpose === 'restore'];
        }
        if (in_array($this->purpose, ['login', 'restore', 'sign_up'], true)) {
            SessionManager::login($this->user, $this->login ?? throw new \RuntimeException('Native login issuance is missing.'), true);
            NativeAuthSession::clearContinuation();
            unset($_SESSION['oidc_sign_up']);
        } else {
            SessionManager::rotateForNative();
        }
        if ($this->purpose === 'step_up') SessionManager::establishPrivilegedStepUp($this->user, $this->provider);
        if ($this->factorMethod !== null) SessionManager::establishPrivilegedStepUp($this->user, $this->factorMethod);
        $payload = ['outcome' => 'completed', 'purpose' => $this->purpose, 'user' => $this->user->toSessionArray(),
            'csrf_token' => SessionManager::csrfToken(), 'restored' => $this->purpose === 'restore'];
        if ($this->factorMethod !== null) $payload['mfa_method'] = $this->factorMethod;
        if ($this->purpose === 'link') $payload['identities'] = $oidc->identities($this->user->id);
        if ($this->purpose === 'picture') {
            $payload['picture_ready'] = true;
            $payload['picture_url'] = '/api/v1/account/avatar/imported.php';
        }
        if ($this->purpose === 'step_up' || $this->factorMethod !== null) {
            $payload['security'] = ['privileged_step_up' => SessionManager::privilegedStepUpStatus($this->user, $config)];
        }
        return $payload;
    }
}
