<?php

declare(strict_types=1);
namespace ChitChat\Admin;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Config;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * The name the installation presents to people: page titles and headings,
 * passkey prompts, push notifications, exports and status output. It is an
 * optional Super-Administrator override; without one, APP_NAME applies.
 */
final class ApplicationNameService
{
    public const MAXIMUM_LENGTH = 64;

    private readonly AuditLogger $audit;

    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
        $this->audit = new AuditLogger($pdo);
    }

    /**
     * The effective name for rendering a page. Pages must still render when the
     * database is unavailable, so a lookup failure falls back to APP_NAME.
     */
    public static function resolve(Config $config): string
    {
        try {
            return (new self(Database::connect($config), $config))->effective();
        } catch (Throwable) {
            return $config->applicationName;
        }
    }

    /** Trims the name and rejects empty, overlong, or control-character names. */
    public static function normalize(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '' || mb_strlen($trimmed) > self::MAXIMUM_LENGTH) {
            throw new ApiException(
                400,
                'validation_error',
                sprintf('application_name must contain 1 to %d characters.', self::MAXIMUM_LENGTH),
            );
        }
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $trimmed) !== 0) {
            throw new ApiException(400, 'validation_error', 'application_name must not contain control characters.');
        }
        return $trimmed;
    }

    public function effective(): string
    {
        return $this->override() ?? $this->config->applicationName;
    }

    /** @return array{override: string|null, default: string, effective: string} */
    public function get(AuthenticatedUser $actor): array
    {
        $this->requireSuperAdministrator($actor);
        return $this->describe();
    }

    /**
     * @param string|null $name null restores the APP_NAME server default
     * @return array{override: string|null, default: string, effective: string}
     */
    public function update(AuthenticatedUser $actor, ?string $name, string $ipAddress): array
    {
        $this->requireSuperAdministrator($actor);
        $name = $name === null ? null : self::normalize($name);

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->query('SELECT id FROM system_settings WHERE id = 1 FOR UPDATE');
            if ($lock === false || $lock->fetchColumn() === false) {
                throw new RuntimeException('Unable to lock system settings.');
            }
            $old = $this->override();
            $statement = $this->pdo->prepare('UPDATE system_settings SET application_name = :name WHERE id = 1');
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare application-name update.');
            }
            $statement->bindValue(':name', $name, $name === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $statement->execute();
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'system.application_name_updated',
                subjectType: 'system_settings',
                subjectId: '1',
                metadata: ['old' => $old, 'new' => $name],
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

    /** @return array{override: string|null, default: string, effective: string} */
    private function describe(): array
    {
        $override = $this->override();
        return [
            'override' => $override,
            'default' => $this->config->applicationName,
            'effective' => $override ?? $this->config->applicationName,
        ];
    }

    private function override(): ?string
    {
        $statement = $this->pdo->query('SELECT application_name FROM system_settings WHERE id = 1');
        if ($statement === false) {
            throw new RuntimeException('Unable to query the application name.');
        }
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : null;
    }

    private function requireSuperAdministrator(AuthenticatedUser $actor): void
    {
        if (!$actor->hasRole('super_admin')) {
            throw new ApiException(403, 'forbidden', 'The application name requires Super-Administrator access.');
        }
    }
}
