<?php

declare(strict_types=1);

namespace ChitChat\Account;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Upload\AttachmentFileStore;
use ChitChat\Upload\IncomingFile;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Profile pictures and the small public profile behind every name.
 *
 * Uploads are never stored as sent: GD decodes them and re-encodes a
 * centre-cropped 256x256 WebP, which drops metadata such as GPS positions
 * and neutralises crafted files. Only JPEG, PNG and WebP are accepted, never
 * SVG. GD with WebP support is optional for the installation; without it
 * avatars are simply unavailable.
 */
final class AvatarService
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    public const MAX_SOURCE_DIMENSION = 6000;
    public const SIZE = 256;
    private const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    /** Global roles that may remove someone else's avatar. */
    private const MODERATOR_ROLES = ['super_admin', 'admin', 'chat_admin', 'global_moderator'];
    /** Public staff badges, most senior first. */
    private const BADGES = [
        'super_admin' => 'Super-Administrator',
        'admin' => 'Administrator',
        'chat_admin' => 'Chat Admin',
        'global_moderator' => 'Global Moderator',
    ];

    private readonly AttachmentFileStore $files;
    private readonly AuditLogger $audit;

    public function __construct(private readonly PDO $pdo, Config $config)
    {
        $this->files = new AttachmentFileStore($config);
        $this->audit = new AuditLogger($pdo);
    }

    public static function available(): bool
    {
        return extension_loaded('gd')
            && function_exists('imagewebp')
            && function_exists('imagecreatefromstring')
            && (gd_info()['WebP Support'] ?? false) === true;
    }

    /** How long an imported picture waits for the crop step. */
    private const IMPORT_TTL_SECONDS = 900;
    public const IMPORT_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Keeps a picture fetched from Google or Twitch until the Account page
     * takes it into the crop step; the saved picture is then re-encoded like
     * any upload. Only JPEG, PNG and WebP pass, judged by content.
     */
    public function stashImport(int $userId, string $image): void
    {
        $info = strlen($image) <= self::IMPORT_MAX_BYTES ? @getimagesizefromstring($image) : false;
        $type = is_array($info) ? $info['mime'] : null;
        if ($type === null || !in_array($type, self::ACCEPTED_TYPES, true)) {
            throw new ApiException(422, 'invalid_avatar', 'That picture is not a JPEG, PNG, or WebP image.');
        }
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO avatar_imports (user_id, image, media_type, created_at)
VALUES (:user_id, :image, :media_type, NOW())
ON CONFLICT (user_id) DO UPDATE
SET image = EXCLUDED.image, media_type = EXCLUDED.media_type, created_at = EXCLUDED.created_at
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare picture import.');
        }
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':image', $image, PDO::PARAM_LOB);
        $statement->bindValue(':media_type', $type);
        $statement->execute();
    }

    /**
     * Hands over a waiting imported picture once, or null when there is none
     * (or it waited too long).
     *
     * @return ?array{image:string, media_type:string}
     */
    public function takeImport(int $userId): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
DELETE FROM avatar_imports
WHERE user_id = :user_id
RETURNING image, media_type, (created_at > NOW() - make_interval(secs => :ttl))::int AS fresh
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare picture import lookup.');
        }
        $statement->execute(['user_id' => $userId, 'ttl' => self::IMPORT_TTL_SECONDS]);
        $row = $statement->fetch();
        if (!is_array($row) || (int) $row['fresh'] !== 1) {
            return null;
        }
        $image = is_resource($row['image']) ? stream_get_contents($row['image']) : $row['image'];

        return is_string($image) ? ['image' => $image, 'media_type' => (string) $row['media_type']] : null;
    }

    /** @return array{has_avatar:bool, avatar_version:?int} */
    public function upload(AuthenticatedUser $actor, IncomingFile $file, string $ipAddress): array
    {
        if (!self::available()) {
            throw new ApiException(503, 'avatars_unavailable', 'Profile pictures need the PHP GD extension with WebP support on this server.');
        }
        if ($file->reportedSize < 1 || $file->reportedSize > self::MAX_UPLOAD_BYTES) {
            throw new ApiException(413, 'avatar_too_large', 'Choose an image of at most 5 MB.');
        }
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($file->temporaryPath);
        if (!is_string($mimeType) || !in_array($mimeType, self::ACCEPTED_TYPES, true)) {
            throw new ApiException(415, 'avatar_type_not_allowed', 'Choose a JPEG, PNG, or WebP image.');
        }
        // Check the declared size before decoding, so a tiny file cannot expand into a huge bitmap.
        $dimensions = @getimagesize($file->temporaryPath);
        if (
            !is_array($dimensions)
            || $dimensions[0] < 1
            || $dimensions[1] < 1
            || $dimensions[0] > self::MAX_SOURCE_DIMENSION
            || $dimensions[1] > self::MAX_SOURCE_DIMENSION
        ) {
            throw new ApiException(400, 'avatar_invalid_image', 'The image could not be read, or it is larger than 6000 × 6000 pixels.');
        }

        $key = $this->files->storeBytes($this->reencode($file->temporaryPath));
        $previous = null;
        $this->pdo->beginTransaction();
        try {
            $previous = $this->lockAvatarKey($actor->id);
            $statement = $this->pdo->prepare(
                'UPDATE users SET avatar_key = :key, avatar_updated_at = NOW(), updated_at = NOW() WHERE id = :id',
            );
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare avatar update.');
            }
            $statement->execute(['key' => $key, 'id' => $actor->id]);
            $this->audit->log($actor->id, 'account.avatar_updated', 'user', (string) $actor->id, [], $ipAddress);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->files->remove($key);
            throw $exception;
        }
        if ($previous !== null) {
            $this->files->remove($previous);
        }

        return $this->avatarState($actor->id);
    }

    /** Removes an avatar: your own, or anyone's for global moderators (audited, and they are told). */
    public function remove(AuthenticatedUser $actor, int $userId, string $ipAddress): void
    {
        $self = $userId === $actor->id;
        if (!$self && !$this->isModerator($actor)) {
            throw new ApiException(403, 'forbidden', 'You may only remove your own profile picture.');
        }

        $this->pdo->beginTransaction();
        try {
            $previous = $this->lockAvatarKey($userId);
            if ($previous === null) {
                throw new ApiException(404, 'avatar_not_found', 'There is no profile picture to remove.');
            }
            $statement = $this->pdo->prepare(
                'UPDATE users SET avatar_key = NULL, avatar_updated_at = NOW(), updated_at = NOW() WHERE id = :id',
            );
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare avatar removal.');
            }
            $statement->execute(['id' => $userId]);
            if ($self) {
                $this->audit->log($actor->id, 'account.avatar_removed', 'user', (string) $userId, [], $ipAddress);
            } else {
                $this->audit->log($actor->id, 'moderation.avatar_removed', 'user', (string) $userId, [], $ipAddress);
                $notify = $this->pdo->prepare(
                    "INSERT INTO account_notifications (user_id, kind, context_json) VALUES (:user_id, 'avatar_removed', '{}'::jsonb)",
                );
                if ($notify === false) {
                    throw new RuntimeException('Unable to prepare avatar-removal notification.');
                }
                $notify->execute(['user_id' => $userId]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $this->files->remove($previous);
    }

    /**
     * The file of an active account's avatar, or null when it shows initials.
     *
     * @return ?array{path:string, key:string}
     */
    public function imagePath(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT avatar_key FROM users WHERE id = :id AND account_state = 'active' AND avatar_key IS NOT NULL",
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare avatar lookup.');
        }
        $statement->execute(['id' => $userId]);
        $key = $statement->fetchColumn();
        if (!is_string($key)) {
            return null;
        }
        $path = $this->files->pathForKey($key);

        return is_file($path) ? ['path' => $path, 'key' => $key] : null;
    }

    /**
     * The small profile behind a name.
     *
     * @return array{id:int, username:string, member_since:string, badge:?string, has_avatar:bool, avatar_version:?int, can_remove_avatar:bool}
     */
    public function profile(AuthenticatedUser $viewer, int $userId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT u.id, u.username, u.created_at, u.avatar_key, u.avatar_updated_at,
       COALESCE(array_to_json(array_agg(r.role) FILTER (WHERE r.role IS NOT NULL)), '[]')::text AS roles
FROM users u
LEFT JOIN user_roles r ON r.user_id = u.id
WHERE u.id = :id AND u.account_state = 'active'
GROUP BY u.id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare profile lookup.');
        }
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'user_not_found', 'This account is not available.');
        }

        $roles = json_decode((string) $row['roles'], true);
        $roles = is_array($roles) ? $roles : [];
        $badge = null;
        foreach (self::BADGES as $role => $label) {
            if (in_array($role, $roles, true)) {
                $badge = $label;
                break;
            }
        }
        $hasAvatar = $row['avatar_key'] !== null;

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'member_since' => (string) $row['created_at'],
            'badge' => $badge,
            'has_avatar' => $hasAvatar,
            'avatar_version' => $hasAvatar ? $this->version($row['avatar_updated_at']) : null,
            'can_remove_avatar' => $hasAvatar && ($viewer->id === (int) $row['id'] || $this->isModerator($viewer)),
        ];
    }

    /** @return array{has_avatar:bool, avatar_version:?int} */
    public function avatarState(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT avatar_key, avatar_updated_at FROM users WHERE id = :id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare avatar state lookup.');
        }
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        $has = is_array($row) && $row['avatar_key'] !== null;

        return ['has_avatar' => $has, 'avatar_version' => $has ? $this->version($row['avatar_updated_at']) : null];
    }

    /**
     * For the personal data export: the stored image itself.
     *
     * @return ?array{mime_type:string, width:int, height:int, data_base64:string}
     */
    public function exportFor(int $userId): ?array
    {
        $image = $this->imagePath($userId);
        if ($image === null) {
            return null;
        }
        $content = file_get_contents($image['path']);
        if ($content === false) {
            return null;
        }

        return [
            'mime_type' => 'image/webp',
            'width' => self::SIZE,
            'height' => self::SIZE,
            'data_base64' => base64_encode($content),
        ];
    }

    /**
     * Clears the avatar of an account being permanently closed, inside the
     * caller's transaction, and returns its key so the caller can delete the
     * file once that transaction has committed.
     */
    public function clearForClosure(int $userId): ?string
    {
        $key = $this->lockAvatarKey($userId);
        if ($key === null) {
            return null;
        }
        $statement = $this->pdo->prepare('UPDATE users SET avatar_key = NULL, avatar_updated_at = NULL WHERE id = :id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare avatar purge.');
        }
        $statement->execute(['id' => $userId]);

        return $key;
    }

    public function removeFile(string $key): void
    {
        $this->files->remove($key);
    }

    private function reencode(string $path): string
    {
        $content = file_get_contents($path);
        $source = $content === false ? false : @imagecreatefromstring($content);
        if ($source === false) {
            throw new ApiException(400, 'avatar_invalid_image', 'The image could not be read.');
        }

        try {
            $width = imagesx($source);
            $height = imagesy($source);
            $side = min($width, $height);
            $target = imagecreatetruecolor(self::SIZE, self::SIZE);
            if ($target === false) {
                throw new RuntimeException('Unable to allocate the avatar image.');
            }
            try {
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
                imagecopyresampled(
                    $target,
                    $source,
                    0,
                    0,
                    intdiv($width - $side, 2),
                    intdiv($height - $side, 2),
                    self::SIZE,
                    self::SIZE,
                    $side,
                    $side,
                );
                ob_start();
                $written = imagewebp($target, null, 85);
                $encoded = ob_get_clean();
            } finally {
                imagedestroy($target);
            }
        } finally {
            imagedestroy($source);
        }

        if (!$written || !is_string($encoded) || $encoded === '') {
            throw new RuntimeException('Unable to encode the avatar.');
        }

        return $encoded;
    }

    private function lockAvatarKey(int $userId): ?string
    {
        $statement = $this->pdo->prepare('SELECT avatar_key FROM users WHERE id = :id FOR UPDATE');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare avatar lock.');
        }
        $statement->execute(['id' => $userId]);
        $key = $statement->fetchColumn();
        if ($key === false) {
            throw new ApiException(404, 'user_not_found', 'This account is not available.');
        }

        return is_string($key) ? $key : null;
    }

    private function isModerator(AuthenticatedUser $actor): bool
    {
        foreach (self::MODERATOR_ROLES as $role) {
            if ($actor->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    private function version(mixed $updatedAt): int
    {
        $time = is_string($updatedAt) ? strtotime($updatedAt) : false;

        return $time === false ? 0 : $time;
    }
}
