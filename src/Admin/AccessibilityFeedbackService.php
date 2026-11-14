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
 * Where "Report an accessibility problem" leads (#219): the project's issue form by default,
 * the operator's own https: page or mailto: address, or nowhere. Super-Administrator only.
 */
final class AccessibilityFeedbackService
{
    public const DEFAULT_URL = 'https://github.com/Thiesi/ChitChat/issues/new?template=accessibility.yml';
    public const MAX_LENGTH = 2048;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The link the apps show: the saved address, the project's form when none is saved, or
     * null when the link is switched off.
     */
    public static function resolve(bool $enabled, ?string $url): ?string
    {
        if (!$enabled) {
            return null;
        }

        return $url ?? self::DEFAULT_URL;
    }

    /**
     * The public form for session.php.
     *
     * @return array{url:string}|null
     */
    public static function toPublic(bool $enabled, ?string $url): ?array
    {
        $resolved = self::resolve($enabled, $url);

        return $resolved === null ? null : ['url' => $resolved];
    }

    /**
     * null (the project's form) or a strict https: or mailto: address. Anything else answers
     * 400 validation_error: other schemes (http:, javascript:, data: …), a bare host, upper-case
     * schemes, spaces, control or non-ASCII characters, more than 2048 characters, an https URL
     * without a host or with a user name, or a mailto: whose address isn't one valid address.
     */
    public static function validateUrl(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw self::invalid('accessibility_feedback url must be an https: or mailto: address, or null for the project\'s form.');
        }
        if (strlen($value) > self::MAX_LENGTH) {
            throw self::invalid(sprintf('accessibility_feedback url must be at most %d characters.', self::MAX_LENGTH));
        }
        // Printable ASCII only: no spaces, control characters or look-alike Unicode.
        if (preg_match('/\A[\x21-\x7E]+\z/', $value) !== 1) {
            throw self::invalid('accessibility_feedback url must not contain spaces, control characters or non-ASCII characters.');
        }
        if (str_starts_with($value, 'https://')) {
            $parts = parse_url($value);
            if (!is_array($parts) || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
                throw self::invalid('accessibility_feedback url must be an https: address with a host and no user name.');
            }

            return $value;
        }
        if (str_starts_with($value, 'mailto:')) {
            $address = explode('?', substr($value, strlen('mailto:')), 2)[0];
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw self::invalid('accessibility_feedback url must be mailto: followed by one valid e-mail address.');
            }

            return $value;
        }

        throw self::invalid('accessibility_feedback url must start with https:// or mailto:.');
    }

    /** @return array{enabled:bool, url:?string, default_url:string, effective_url:?string} */
    public function get(AuthenticatedUser $actor): array
    {
        self::requireSuperAdministrator($actor);
        [$enabled, $url] = $this->current(false);

        return self::describe($enabled, $url);
    }

    /** @return array{enabled:bool, url:?string, default_url:string, effective_url:?string} */
    public function update(AuthenticatedUser $actor, bool $enabled, mixed $url, string $ipAddress): array
    {
        self::requireSuperAdministrator($actor);
        $url = self::validateUrl($url);

        $this->pdo->beginTransaction();
        try {
            [$oldEnabled, $oldUrl] = $this->current(true);
            if ($oldEnabled !== $enabled || $oldUrl !== $url) {
                $statement = $this->pdo->prepare(<<<'SQL'
UPDATE system_settings
SET accessibility_feedback_enabled = :enabled,
    accessibility_feedback_url = :url,
    updated_at = NOW()
WHERE id = 1
SQL);
                if ($statement === false) {
                    throw new RuntimeException('Unable to prepare accessibility feedback update.');
                }
                $statement->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
                $statement->bindValue(':url', $url, $url === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $statement->execute();
                (new AuditLogger($this->pdo))->log(
                    actorUserId: $actor->id,
                    action: 'system.accessibility_feedback_updated',
                    subjectType: 'system_settings',
                    subjectId: '1',
                    metadata: [
                        'old' => ['enabled' => $oldEnabled, 'url' => $oldUrl],
                        'new' => ['enabled' => $enabled, 'url' => $url],
                    ],
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

        return self::describe($enabled, $url);
    }

    /** @return array{bool, ?string} */
    private function current(bool $forUpdate): array
    {
        $statement = $this->pdo->query(
            'SELECT accessibility_feedback_enabled::int AS enabled, accessibility_feedback_url AS url FROM system_settings WHERE id = 1'
            . ($forUpdate ? ' FOR UPDATE' : ''),
        );
        $row = $statement === false ? false : $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('System settings are missing.');
        }

        return [(int) $row['enabled'] === 1, $row['url'] === null ? null : (string) $row['url']];
    }

    /** @return array{enabled:bool, url:?string, default_url:string, effective_url:?string} */
    private static function describe(bool $enabled, ?string $url): array
    {
        return [
            'enabled' => $enabled,
            'url' => $url,
            'default_url' => self::DEFAULT_URL,
            'effective_url' => self::resolve($enabled, $url),
        ];
    }

    private static function invalid(string $message): ApiException
    {
        return new ApiException(400, 'validation_error', $message);
    }

    private static function requireSuperAdministrator(AuthenticatedUser $actor): void
    {
        if (!$actor->hasRole('super_admin')) {
            throw new ApiException(403, 'forbidden', 'The accessibility feedback setting requires Super-Administrator access.');
        }
    }
}
