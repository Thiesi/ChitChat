<?php

declare(strict_types=1);

namespace ChitChat\Policy;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final readonly class PolicyService
{
    public function __construct(private PDO $pdo, private ?Config $config = null)
    {
    }

    /** @return array{rules:?array{url:string,version:int,updated_at:?string},privacy:?array{url:string,updated_at:?string},legal_notice:?array{url:string}} */
    public function discovery(): array
    {
        $rules = $this->discoveryDocument('rules');
        $privacy = $this->discoveryDocument('privacy');
        $legal = $this->legalNoticeUrl();
        return [
            'rules' => $rules === null ? null : ['url' => $rules['url'], 'version' => $rules['version'], 'updated_at' => $rules['updated_at']],
            'privacy' => $privacy === null ? null : ['url' => $privacy['url'], 'updated_at' => $privacy['updated_at']],
            'legal_notice' => $legal === null ? null : ['url' => $legal],
        ];
    }

    public function legalNoticeUrl(): ?string
    {
        $row = $this->raw('legal_notice');
        return $row['source'] === 'none' ? null : (string) $row['url'];
    }

    /** @return ?array{url:string,version:int,updated_at:?string} */
    private function discoveryDocument(string $kind): ?array
    {
        $row = $this->raw($kind);
        return $row['source'] === 'none' ? null : [
            'url' => $row['source'] === 'hosted' ? $this->origin() . ($kind === 'rules' ? '/rules.php' : '/privacy.php') : (string) $row['url'],
            'version' => (int) $row['version'],
            'updated_at' => self::timestamp($row['updated_at']),
        ];
    }

    /** @return ?array{source:string,url:string,html:?string,markdown:?string,version:int,updated_at:?string,change_summary:?string} */
    public function publicDocument(string $kind): ?array
    {
        $this->requireDocument($kind);
        $row = $this->raw($kind);
        if ($row['source'] === 'none') {
            return null;
        }
        $hosted = $row['source'] === 'hosted';
        return [
            'source' => (string) $row['source'],
            'url' => $hosted ? $this->origin() . ($kind === 'rules' ? '/rules.php' : '/privacy.php') : (string) $row['url'],
            'html' => $hosted ? (new PolicyMarkdownRenderer())->convert((string) $row['markdown']) : null,
            'markdown' => $hosted ? (string) $row['markdown'] : null,
            'version' => (int) $row['version'],
            'updated_at' => self::timestamp($row['updated_at']),
            'change_summary' => $row['change_summary'] === null ? null : (string) $row['change_summary'],
        ];
    }

    /** @return array{accepted_version:int,accepted_at:?string,acceptance_required:bool} */
    public function rulesStatus(int $userId): array
    {
        $statement = $this->prepare(<<<'SQL'
SELECT rules.source, rules.version, accepted.version AS accepted_version, accepted.accepted_at
FROM policy_documents rules
LEFT JOIN LATERAL (
    SELECT version, accepted_at FROM rules_acceptances
    WHERE user_id = :user_id ORDER BY version DESC LIMIT 1
) accepted ON TRUE
WHERE rules.document = 'rules'
SQL);
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Community rules settings are missing.');
        }
        $accepted = (int) ($row['accepted_version'] ?? 0);
        return [
            'accepted_version' => $accepted,
            'accepted_at' => self::timestamp($row['accepted_at']),
            'acceptance_required' => $row['source'] !== 'none' && $accepted < (int) $row['version'],
        ];
    }

    /** Call inside the transaction that creates the account/guest, after abuse checks. */
    public function registrationVersion(mixed $acceptedVersion): int
    {
        $rules = $this->lockedRules();
        if ($rules['source'] === 'none') {
            return 0;
        }
        if ($acceptedVersion === null) {
            throw new ApiException(400, 'rules_acceptance_required', 'Agree to the community rules before continuing.');
        }
        $this->requireCurrentVersion($acceptedVersion, $rules);
        return (int) $rules['version'];
    }

    /** Used by account creation while its rules lock and transaction are still held. */
    public function recordAcceptance(int $userId, int $version, string $ipAddress): void
    {
        if ($version === 0) {
            return;
        }
        $statement = $this->prepare(<<<'SQL'
INSERT INTO rules_acceptances (user_id, version) VALUES (:user_id, :version)
ON CONFLICT (user_id, version) DO NOTHING
SQL);
        $statement->execute(['user_id' => $userId, 'version' => $version]);
        if ($statement->rowCount() === 1) {
            (new AuditLogger($this->pdo))->log($userId, 'policy.rules_accepted', 'user', (string) $userId,
                ['version' => $version], $ipAddress);
        }
    }

    /** @return array{accepted_version:int,accepted_at:?string,acceptance_required:bool} */
    public function accept(AuthenticatedUser $actor, mixed $version, string $ipAddress): array
    {
        return $this->transaction(function () use ($actor, $version, $ipAddress): array {
            $rules = $this->lockedRules();
            // Serialize with password changes and final tombstoning. A stale
            // session cannot recreate acceptance history after it was erased.
            $user = $this->prepare('SELECT account_state, session_version FROM users WHERE id = :id FOR SHARE');
            $user->execute(['id' => $actor->id]);
            $current = $user->fetch();
            if (!is_array($current) || $current['account_state'] !== 'active'
                || (int) $current['session_version'] !== $actor->sessionVersion) {
                throw new ApiException(401, 'authentication_required', 'Authentication is required.');
            }
            $this->requireCurrentVersion($version, $rules);
            $this->recordAcceptance($actor->id, (int) $rules['version'], $ipAddress);
            return $this->rulesStatus($actor->id);
        });
    }

    /**
     * Hold the shared rules-row lock through the caller's content transaction,
     * then the actor's account row (#164). FOR KEY SHARE conflicts with the
     * username key update of a moderator's "require a new username" and of
     * choosing the new name, but not with ordinary updates of the actor's row.
     * A path that will update the actor's own row (non-key columns) passes
     * $accountForNoKeyUpdate, so it never upgrades a weaker lock while a rename
     * waits; NO KEY UPDATE still serializes with a rename's FOR UPDATE (#248).
     *
     * Returns the actor's username as of this lock. A rename cannot commit
     * until the caller's transaction ends, so names stored or published by
     * the write (mention and ping notices, ping events) are never stale.
     */
    public function assertMayWrite(AuthenticatedUser $actor, bool $accountForNoKeyUpdate = false): string
    {
        $rules = $this->lockedRules();
        if ($rules['source'] !== 'none') {
            $statement = $this->prepare('SELECT COALESCE(MAX(version), 0) FROM rules_acceptances WHERE user_id = :id');
            $statement->execute(['id' => $actor->id]);
            if ((int) $statement->fetchColumn() < (int) $rules['version']) {
                throw new ApiException(403, 'rules_acceptance_required', 'Agree to the updated community rules to write. You can still read.');
            }
        }
        $account = $this->prepare('SELECT username, (username_change_required_at IS NOT NULL)::int AS pending FROM users WHERE id = :id '
            . ($accountForNoKeyUpdate ? 'FOR NO KEY UPDATE' : 'FOR KEY SHARE'));
        $account->execute(['id' => $actor->id]);
        $row = $account->fetch();
        if (is_array($row) && (int) $row['pending'] === 1) {
            throw new ApiException(403, 'username_change_required', 'Choose a new username to write. You can still read.');
        }

        return is_array($row) ? (string) $row['username'] : $actor->username;
    }

    /**
     * @template T
     * @param callable():T $write
     * @return T
     */
    public function write(AuthenticatedUser $actor, callable $write): mixed
    {
        return $this->transaction(function () use ($actor, $write): mixed {
            $this->assertMayWrite($actor);
            return $write();
        });
    }

    /** @return array{rules:array<string,mixed>,privacy:array<string,mixed>,legal_notice:array{url:?string}} */
    public function administration(AuthenticatedUser $actor): array
    {
        self::requireAdministrator($actor);
        $legal = $this->raw('legal_notice');
        return ['rules' => $this->adminDocument('rules'), 'privacy' => $this->adminDocument('privacy'),
            'legal_notice' => ['url' => $legal['url'] === null ? null : (string) $legal['url']]];
    }

    public function preview(AuthenticatedUser $actor, string $markdown): string
    {
        self::requireAdministrator($actor);
        return (new PolicyMarkdownRenderer())->convert($markdown);
    }

    /** @return array<string,mixed> */
    public function update(AuthenticatedUser $actor, string $kind, string $source, ?string $markdown,
        ?string $url, bool $material, ?string $summary, string $ipAddress): array
    {
        self::requireAdministrator($actor);
        $this->requireDocument($kind, true);
        if ($kind === 'legal_notice') {
            $source = $url === null || trim($url) === '' ? 'none' : 'external';
        }
        if (!in_array($source, ['none', 'hosted', 'external'], true)) {
            throw new ApiException(400, 'validation_error', 'source must be none, hosted or external.');
        }
        $markdown = $source === 'hosted' ? ($markdown ?? '') : null;
        $url = $source === 'external' ? self::httpsUrl($url ?? '') : null;
        if ($markdown !== null) {
            if (trim($markdown) === '') {
                throw new ApiException(400, 'validation_error', 'A hosted policy must contain Markdown.');
            }
            (new PolicyMarkdownRenderer())->convert($markdown);
        }
        $summary = $summary === null || trim($summary) === '' ? null : trim($summary);
        if ($summary !== null && (!mb_check_encoding($summary, 'UTF-8') || mb_strlen($summary) > 200)) {
            throw new ApiException(400, 'validation_error', 'change_summary must contain at most 200 characters.');
        }
        return $this->transaction(function () use ($actor, $kind, $source, $markdown, $url, $material, $summary, $ipAddress): array {
            $old = $this->raw($kind, 'FOR UPDATE');
            $effectiveMaterial = $material || ($source !== 'none' && $old['source'] === 'none');
            $version = (int) $old['version'];
            if ($kind !== 'legal_notice' && $source !== 'none' && $effectiveMaterial) {
                $version++;
            }
            $statement = $this->prepare(<<<'SQL'
UPDATE policy_documents SET source = :source, markdown = :markdown, url = :url,
    version = :version, change_summary = :summary, updated_at = NOW()
WHERE document = :document
SQL);
            $statement->execute(['source' => $source, 'markdown' => $markdown, 'url' => $url,
                'version' => $version, 'summary' => $summary, 'document' => $kind]);
            (new AuditLogger($this->pdo))->log($actor->id, 'policy.document_published', 'policy', $kind,
                ['source' => $source, 'version' => $version, 'material' => $effectiveMaterial], $ipAddress);
            if ($kind === 'privacy' && $effectiveMaterial) {
                $notify = $this->prepare(<<<'SQL'
INSERT INTO account_notifications (user_id, kind, context_json, push_dispatched_at)
SELECT id, 'privacy_policy_updated', CAST(:context AS jsonb), NOW()
FROM users WHERE account_kind = 'member' AND account_state <> 'closed'
SQL);
                $notify->execute(['context' => json_encode(['version' => $version, 'change_summary' => $summary,
                    'url' => $source === 'none' ? null : ($url ?? $this->origin() . '/privacy.php')], JSON_THROW_ON_ERROR)]);
            }
            return $kind === 'legal_notice' ? ['url' => $url] : $this->adminDocument($kind);
        });
    }

    /** @return list<array{version:int,accepted_at:string}> */
    public function acceptanceHistory(int $userId): array
    {
        $statement = $this->prepare('SELECT version, accepted_at FROM rules_acceptances WHERE user_id = :id ORDER BY version');
        $statement->execute(['id' => $userId]);
        return array_values(array_map(static fn (array $row): array => ['version' => (int) $row['version'],
            'accepted_at' => (string) self::timestamp($row['accepted_at'])], $statement->fetchAll()));
    }

    public static function requireAdministrator(AuthenticatedUser $actor): void
    {
        if (!$actor->hasRole('super_admin')) {
            throw new ApiException(403, 'forbidden', 'Installation policies require Super-Administrator access.');
        }
    }

    /** @return array<string,mixed> */
    private function adminDocument(string $kind): array
    {
        $row = $this->raw($kind);
        return ['source' => (string) $row['source'], 'markdown' => $row['markdown'], 'url' => $row['url'],
            'version' => (int) $row['version'], 'updated_at' => self::timestamp($row['updated_at']),
            'change_summary' => $row['change_summary']];
    }

    /** @return array<string,mixed> */
    private function lockedRules(): array
    {
        return $this->raw('rules', 'FOR SHARE');
    }

    /** @return array<string,mixed> */
    private function raw(string $kind, string $lock = ''): array
    {
        $statement = $this->prepare('SELECT * FROM policy_documents WHERE document = :kind ' . $lock);
        $statement->execute(['kind' => $kind]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Installation policy settings are missing.');
        }
        return $row;
    }

    /** @param array<string,mixed> $rules */
    private function requireCurrentVersion(mixed $version, array $rules): void
    {
        if (!is_int($version)) {
            throw new ApiException(400, 'validation_error', 'The rules version must be an integer.');
        }
        if ($rules['source'] === 'none' || $version !== (int) $rules['version']) {
            throw new ApiException(409, 'rules_version_outdated', 'The community rules changed. Read the current rules and try again.');
        }
    }

    private function requireDocument(string $kind, bool $allowLegal = false): void
    {
        if (!in_array($kind, $allowLegal ? ['rules', 'privacy', 'legal_notice'] : ['rules', 'privacy'], true)) {
            throw new ApiException(400, 'validation_error', 'Unknown policy document.');
        }
    }

    private static function httpsUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new ApiException(400, 'validation_error', 'Policy links must be absolute HTTPS URLs without credentials or whitespace.');
        }
        return $url;
    }

    private function origin(): string
    {
        if ($this->config !== null) {
            foreach ([$this->config->applicationOrigin, $this->config->oidcRedirectOrigin, $this->config->webauthnOrigin] as $configured) {
                if ($configured !== '') {
                    return rtrim($configured, '/');
                }
            }
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (preg_match('/\A(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?\z/iD', $host) !== 1) {
            throw new ApiException(400, 'invalid_host', 'The request host is invalid.');
        }
        $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
        return ($https ? 'https://' : 'http://') . $host;
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare installation policy query.');
        }
        return $statement;
    }

    private static function timestamp(mixed $value): ?string
    {
        return $value === null ? null : (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $operation();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            $this->rollbackIfActive();
            throw $exception;
        }
    }

    private function rollbackIfActive(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
