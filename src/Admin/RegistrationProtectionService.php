<?php

declare(strict_types=1);
namespace ChitChat\Admin;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\RegistrationChallenge;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimitPolicySet;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Registration bot-resistance settings. Each setting is an optional
 * Super-Administrator override stored in system_settings; without one, the
 * server default from the environment applies.
 *
 * Changes are audited as system.registration_protection_updated rather than
 * system.settings_updated, because tuning bot resistance is not an
 * installation policy that every account is notified about.
 */
final class RegistrationProtectionService
{
    /** @var array<string, array{column: string, minimum: int, maximum: int}> */
    private const SETTINGS = [
        'rate_limit_max_attempts' => ['column' => 'registration_rate_limit_max_attempts', 'minimum' => 1, 'maximum' => 100],
        'rate_limit_window_seconds' => ['column' => 'registration_rate_limit_window_seconds', 'minimum' => 60, 'maximum' => 86_400],
        'min_fill_seconds' => ['column' => 'registration_min_fill_seconds', 'minimum' => 0, 'maximum' => 60],
        'proof_of_work_bits' => ['column' => 'registration_proof_of_work_bits', 'minimum' => 0, 'maximum' => 22],
    ];

    private readonly AuditLogger $audit;

    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
        $this->audit = new AuditLogger($pdo);
    }

    public function challenge(): RegistrationChallenge
    {
        $effective = $this->effective();
        return new RegistrationChallenge($effective['min_fill_seconds'], $effective['proof_of_work_bits']);
    }

    /** Starting a guest session solves the same puzzle; there is no form to fill, so no minimum time. */
    public function guestChallenge(): RegistrationChallenge
    {
        return new RegistrationChallenge(0, $this->effective()['proof_of_work_bits'], 'guest_challenge');
    }

    public function rateLimits(): RateLimitPolicySet
    {
        $effective = $this->effective();
        return $this->config->rateLimits->with(
            'registration',
            $effective['rate_limit_max_attempts'],
            $effective['rate_limit_window_seconds'],
        );
    }

    /** @return array{rate_limit_max_attempts: int, rate_limit_window_seconds: int, min_fill_seconds: int, proof_of_work_bits: int} */
    public function effective(): array
    {
        $overrides = $this->overrides();
        $defaults = $this->defaults();
        return [
            'rate_limit_max_attempts' => $overrides['rate_limit_max_attempts'] ?? $defaults['rate_limit_max_attempts'],
            'rate_limit_window_seconds' => $overrides['rate_limit_window_seconds'] ?? $defaults['rate_limit_window_seconds'],
            'min_fill_seconds' => $overrides['min_fill_seconds'] ?? $defaults['min_fill_seconds'],
            'proof_of_work_bits' => $overrides['proof_of_work_bits'] ?? $defaults['proof_of_work_bits'],
        ];
    }

    /** @return array{overrides: array<string, int|null>, defaults: array<string, int>, effective: array<string, int>} */
    public function get(AuthenticatedUser $actor): array
    {
        $this->requireSuperAdministrator($actor);
        return $this->describe();
    }

    /**
     * @param array<string, int|null> $overrides keyed like SETTINGS; null restores the server default
     * @return array{overrides: array<string, int|null>, defaults: array<string, int>, effective: array<string, int>}
     */
    public function update(AuthenticatedUser $actor, array $overrides, string $ipAddress): array
    {
        $this->requireSuperAdministrator($actor);
        foreach (self::SETTINGS as $name => $setting) {
            if (!array_key_exists($name, $overrides)) {
                throw new ApiException(400, 'validation_error', sprintf('%s is required (null uses the server default).', $name));
            }
            $value = $overrides[$name];
            if ($value !== null && ($value < $setting['minimum'] || $value > $setting['maximum'])) {
                throw new ApiException(
                    400,
                    'validation_error',
                    sprintf('%s must be between %d and %d.', $name, $setting['minimum'], $setting['maximum']),
                );
            }
        }

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->query('SELECT id FROM system_settings WHERE id = 1 FOR UPDATE');
            if ($lock === false || $lock->fetchColumn() === false) {
                throw new RuntimeException('Unable to lock system settings.');
            }
            $old = $this->overrides();
            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE system_settings
SET registration_rate_limit_max_attempts = :rate_limit_max_attempts,
    registration_rate_limit_window_seconds = :rate_limit_window_seconds,
    registration_min_fill_seconds = :min_fill_seconds,
    registration_proof_of_work_bits = :proof_of_work_bits
WHERE id = 1
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare registration-protection update.');
            }
            foreach (array_keys(self::SETTINGS) as $name) {
                $value = $overrides[$name];
                $statement->bindValue(':' . $name, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            }
            $statement->execute();
            $new = $this->overrides();
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'system.registration_protection_updated',
                subjectType: 'system_settings',
                subjectId: '1',
                metadata: ['old' => $old, 'new' => $new],
                ipAddress: $ipAddress,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $this->describe();
    }

    /** @return array{overrides: array<string, int|null>, defaults: array<string, int>, effective: array<string, int>} */
    private function describe(): array
    {
        return [
            'overrides' => $this->overrides(),
            'defaults' => $this->defaults(),
            'effective' => $this->effective(),
        ];
    }

    /** @return array{rate_limit_max_attempts: int, rate_limit_window_seconds: int, min_fill_seconds: int, proof_of_work_bits: int} */
    private function defaults(): array
    {
        $policy = $this->config->rateLimitPolicy('registration');
        return [
            'rate_limit_max_attempts' => $policy->maximumAttempts,
            'rate_limit_window_seconds' => $policy->windowSeconds,
            'min_fill_seconds' => $this->config->registrationMinimumFillSeconds,
            'proof_of_work_bits' => $this->config->registrationProofOfWorkBits,
        ];
    }

    /** @return array{rate_limit_max_attempts: int|null, rate_limit_window_seconds: int|null, min_fill_seconds: int|null, proof_of_work_bits: int|null} */
    private function overrides(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT registration_rate_limit_max_attempts,
       registration_rate_limit_window_seconds,
       registration_min_fill_seconds,
       registration_proof_of_work_bits
FROM system_settings
WHERE id = 1
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to query registration-protection settings.');
        }
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('System settings are missing.');
        }
        $value = static fn (mixed $stored): ?int => $stored === null ? null : (int) $stored;
        return [
            'rate_limit_max_attempts' => $value($row['registration_rate_limit_max_attempts']),
            'rate_limit_window_seconds' => $value($row['registration_rate_limit_window_seconds']),
            'min_fill_seconds' => $value($row['registration_min_fill_seconds']),
            'proof_of_work_bits' => $value($row['registration_proof_of_work_bits']),
        ];
    }

    private function requireSuperAdministrator(AuthenticatedUser $actor): void
    {
        if (!$actor->hasRole('super_admin')) {
            throw new ApiException(403, 'forbidden', 'Registration protection requires Super-Administrator access.');
        }
    }
}
