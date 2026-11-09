<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Admin\ApplicationNameService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Config;
use PDO;

/** @phpstan-import-type ConfirmationView from NativeAuthService */
final readonly class NativeAuthViewModel
{
    public function __construct(private PDO $pdo, private Config $config) {}

    /** @param array<string,mixed>|null $row
     * @return ConfirmationView
     */
    public function model(?array $row, string $phase, ?string $outcome, string $csrf,
        ?AuthenticatedUser $browserAccount = null, ?string $returnCode = null, ?string $bootstrapKind = null,
        bool $authorizedStatus = false): array
    {
        if (!in_array($phase, ['entry', 'verifying', 'consent', 'account_mismatch', 'identity_mismatch',
            'staged', 'cancelled', 'expired', 'used', 'disabled'], true)) $phase = 'disabled';
        if ($outcome !== null && !in_array($outcome, ['completed', 'pending_mfa', 'pending_sign_up', 'unknown'], true)) $outcome = 'unknown';
        $purpose = (string) ($row['purpose'] ?? 'login');
        if (!in_array($purpose, ['login', 'link', 'picture', 'restore', 'step_up', 'sign_up'], true)) $purpose = 'login';
        if ($purpose === 'login' && $row !== null && $row['user_id'] === null
            && ($row['proof'] !== null || $row['outcome'] === 'pending_sign_up')) $purpose = 'sign_up';
        // A DB sign-in/step-up record cannot prove delivery of an authenticated/elevated PHP cookie.
        if ($phase === 'used' && $outcome === 'completed' && in_array($purpose, ['login', 'step_up'], true)) $outcome = 'unknown';
        $actions = ['continue' => null, 'approve' => null, 'cancel' => null, 'restart' => null];
        $base = '/api/v1/native-auth/browser/';
        if ($bootstrapKind !== null && in_array($phase, ['entry', 'verifying'], true)) {
            $actions['continue'] = $base . ($bootstrapKind === 'callback' ? 'resume.php' : 'continue.php');
            $actions['cancel'] = $base . 'cancel.php';
        } elseif ($phase === 'entry') {
            $actions['continue'] = $base . 'continue.php';
            $actions['cancel'] = $base . 'cancel.php';
        } elseif ($phase === 'consent') {
            $actions['approve'] = $base . 'approve.php';
            $actions['cancel'] = $base . 'cancel.php';
        } elseif (in_array($phase, ['verifying', 'account_mismatch', 'identity_mismatch'], true)) {
            $actions['restart'] = $base . 'restart.php';
            $actions['cancel'] = $base . 'cancel.php';
        }
        $bound = null;
        if ($row !== null && $row['user_id'] !== null) {
            $statement = $this->pdo->prepare("SELECT id, username FROM users WHERE id = :id AND account_state <> 'closed'");
            if ($statement === false) throw new \RuntimeException('Unable to prepare native account display.');
            $statement->execute(['id' => (int) $row['user_id']]);
            $user = $statement->fetch();
            if (is_array($user)) $bound = ['id' => (int) $user['id'], 'username' => (string) $user['username']];
        }
        $client = (string) ($row['client_id'] ?? 'de.networkxxiii.chitchat');
        $app = match ($client) {
            'de.networkxxiii.chitchat' => 'ChitChat for Android',
            'de.networkxxiii.chitchat.debug' => 'ChitChat for Android (debug)',
            default => $client,
        };
        return [
            'installation_name' => (new ApplicationNameService($this->pdo, $this->config))->effective(),
            'app_name' => $app, 'purpose' => $purpose,
            'provider' => ['label' => match ($row['provider'] ?? null) { 'google' => 'Google', 'twitch' => 'Twitch', default => '' }],
            'expires_at' => (string) ($row['expires_at'] ?? gmdate(DATE_ATOM)), 'bound_account' => $bound,
            'browser_account' => $browserAccount === null ? null : ['username' => $browserAccount->username],
            'phase' => $phase, 'outcome' => $outcome, 'csrf_token' => $csrf, 'actions' => $actions,
            'return_url' => $phase === 'staged' && $row !== null ? $this->returnUrl($row, $returnCode) : null,
            'cancel_return_url' => null, 'status_url' => $authorizedStatus ? '/native-auth.php' : null,
        ];
    }

    /** @param array<string,mixed> $row */
    private function returnUrl(array $row, #[\SensitiveParameter] ?string $code): ?string
    {
        if ($code === null || !NativeAuthConfiguration::validToken($code) || !is_string($row['code_hash'])
            || !hash_equals($row['code_hash'], hash('sha256', $code)) || !is_string($row['return_state'])
            || !hash_equals((string) $row['state_hash'], hash('sha256', $row['return_state']))) return null;
        $redirect = NativeAuthConfiguration::redirect($this->config, (string) $row['client_id']);
        if ($row['redirect_uri'] !== $redirect || (new NativeAuthGuard($this->pdo, $this->config))->errorCode($row) !== null) return null;
        return $redirect . '?' . http_build_query(['code' => $code, 'transaction_id' => (string) $row['id'],
            'state' => $row['return_state']], '', '&', PHP_QUERY_RFC3986);
    }
}
