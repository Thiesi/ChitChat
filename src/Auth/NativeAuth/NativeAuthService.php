<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Auth\Oidc\CurlOidcHttpClient;
use ChitChat\Auth\Oidc\OidcHttpClient;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use PDO;

/**
 * @phpstan-type ConfirmationPhase 'entry'|'verifying'|'consent'|'account_mismatch'|'identity_mismatch'|'staged'|'cancelled'|'expired'|'used'|'disabled'
 * @phpstan-type ConfirmationOutcome 'completed'|'pending_mfa'|'pending_sign_up'|'unknown'
 * @phpstan-type ConfirmationView array{
 *   installation_name:string,app_name:string,purpose:'login'|'link'|'picture'|'restore'|'step_up'|'sign_up',
 *   provider:array{label:string},expires_at:string,bound_account:array{id:int,username:string}|null,
 *   browser_account:array{username:string}|null,phase:ConfirmationPhase,outcome:ConfirmationOutcome|null,
 *   csrf_token:string,actions:array{continue:?string,approve:?string,cancel:?string,restart:?string},
 *   return_url:?string,cancel_return_url:?string,status_url:?string
 * }
 */
final readonly class NativeAuthService
{
    public function __construct(
        private PDO $pdo,
        private Config $config,
        private OidcHttpClient $http = new CurlOidcHttpClient(),
    ) {}

    /** @param array<string,mixed> $payload
     * @return array{transaction_id:string,browser_url:string,expires_at:string}
     */
    public function start(#[\SensitiveParameter] array $payload, string $ip): array
    {
        return (new NativeAuthTransactions($this->pdo, $this->config))->start($payload,
            SessionManager::currentUser(new UserRepository($this->pdo)), $ip);
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function exchange(#[\SensitiveParameter] array $payload, string $ip): array
    {
        return (new NativeAuthExchange($this->pdo, $this->config))->exchange($payload, $ip);
    }

    /** @return array{transaction_id:string,phase:string,outcome:?string,error_code:?string} */
    public function status(string $id, string $ip): array
    {
        return (new NativeAuthTransactions($this->pdo, $this->config))->status($id, $ip);
    }

    /** @return array{status:string} */
    public function cancel(string $id, string $ip): array
    {
        return (new NativeAuthTransactions($this->pdo, $this->config))->cancel($id, $ip);
    }

    public function browser(): NativeAuthBrowser
    {
        return new NativeAuthBrowser($this->pdo, $this->config, $this->http);
    }

    public function providerFlow(): NativeAuthProviderFlow
    {
        return new NativeAuthProviderFlow($this->pdo, $this->config, $this->http);
    }
    /** @return ConfirmationView */
    public function browserView(): array
    {
        return $this->browser()->view();
    }
}
