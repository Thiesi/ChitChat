<?php

declare(strict_types=1);

namespace ChitChat\Admin;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Which seasonal decoration, if any, the signed-out sign-in screen shows (#242).
 * Set by hand by a Super-Administrator; there are no date windows.
 */
final class SignInDecorationService
{
    /** In the order the settings select offers them. */
    public const OPTIONS = ['off', 'winter', 'spring', 'celebration'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** The public form for session.php: null when off. */
    public static function toPublic(string $stored): ?string
    {
        return $stored === 'off' ? null : $stored;
    }

    /** @return array{value:string, options:list<string>} */
    public function get(AuthenticatedUser $actor): array
    {
        self::requireSuperAdministrator($actor);

        return $this->describe($this->current());
    }

    /** @return array{value:string, options:list<string>} */
    public function update(AuthenticatedUser $actor, string $value, string $ipAddress): array
    {
        self::requireSuperAdministrator($actor);
        $value = self::validate($value);

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->query('SELECT sign_in_decoration FROM system_settings WHERE id = 1 FOR UPDATE');
            $old = $lock === false ? false : $lock->fetchColumn();
            if (!is_string($old)) {
                throw new RuntimeException('Unable to lock system settings.');
            }
            if ($old !== $value) {
                $statement = $this->pdo->prepare('UPDATE system_settings SET sign_in_decoration = :value, updated_at = NOW() WHERE id = 1');
                if ($statement === false) {
                    throw new RuntimeException('Unable to prepare sign-in decoration update.');
                }
                $statement->execute(['value' => $value]);
                (new AuditLogger($this->pdo))->log(
                    actorUserId: $actor->id,
                    action: 'system.sign_in_decoration_updated',
                    subjectType: 'system_settings',
                    subjectId: '1',
                    metadata: ['old' => $old, 'new' => $value],
                    ipAddress: $ipAddress,
                );
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $this->describe($value);
    }

    public static function validate(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::OPTIONS, true)) {
            throw new ApiException(400, 'validation_error', 'sign_in_decoration must be one of: ' . implode(', ', self::OPTIONS) . '.');
        }

        return $value;
    }

    private function current(): string
    {
        $statement = $this->pdo->query('SELECT sign_in_decoration FROM system_settings WHERE id = 1');
        $value = $statement === false ? false : $statement->fetchColumn();
        if (!is_string($value)) {
            throw new RuntimeException('System settings are missing.');
        }

        return $value;
    }

    /** @return array{value:string, options:list<string>} */
    private function describe(string $value): array
    {
        return ['value' => $value, 'options' => self::OPTIONS];
    }

    private static function requireSuperAdministrator(AuthenticatedUser $actor): void
    {
        if (!$actor->hasRole('super_admin')) {
            throw new ApiException(403, 'forbidden', 'The sign-in decoration requires Super-Administrator access.');
        }
    }
}
