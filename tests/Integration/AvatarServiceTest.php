<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\AvatarService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Maintenance\CleanupService;
use ChitChat\Upload\IncomingFile;

final class AvatarServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!AvatarService::available()) {
            self::markTestSkipped('GD with WebP support is required for avatar uploads.');
        }
    }

    public function testUploadsAreReencodedToASquareWebpAndSurviveTheOrphanSweep(): void
    {
        [$member] = $this->users();
        $avatars = new AvatarService($this->pdo, $this->config);

        $state = $avatars->upload($member, $this->upload('png', 1200, 800), '127.0.0.1');
        self::assertTrue($state['has_avatar']);
        self::assertIsInt($state['avatar_version']);

        $image = $avatars->imagePath($member->id) ?? self::fail('Expected a stored avatar.');
        $info = getimagesize($image['path']);
        self::assertIsArray($info);
        self::assertSame(['image/webp', 256, 256], [$info['mime'], $info[0], $info[1]]);

        // The orphan sweep knows avatar files and leaves them alone.
        touch($image['path'], time() - 10 * 86400);
        (new CleanupService($this->pdo, $this->config))->run(false);
        self::assertFileExists($image['path']);

        // A new upload replaces the old file.
        $avatars->upload($member, $this->upload('jpg', 300, 500), '127.0.0.1');
        self::assertFileDoesNotExist($image['path']);
        self::assertNotNull($avatars->exportFor($member->id));
    }

    public function testOnlyTheOwnerOrAGlobalModeratorMayRemoveAPicture(): void
    {
        [$member, $other, $moderator] = $this->users();
        $avatars = new AvatarService($this->pdo, $this->config);
        $avatars->upload($member, $this->upload('png', 400, 400), '127.0.0.1');

        try {
            $avatars->remove($other, $member->id, '127.0.0.2');
            self::fail('Expected another member to be refused.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }
        self::assertTrue($avatars->profile($moderator, $member->id)['can_remove_avatar']);
        self::assertFalse($avatars->profile($other, $member->id)['can_remove_avatar']);

        $file = ($avatars->imagePath($member->id) ?? self::fail('Expected a stored avatar.'))['path'];
        $avatars->remove($moderator, $member->id, '127.0.0.3');
        self::assertNull($avatars->imagePath($member->id));
        self::assertFileDoesNotExist($file);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM account_notifications WHERE kind = 'avatar_removed' AND user_id = {$member->id}"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'moderation.avatar_removed'"));

        // Removing your own picture is not a moderation event.
        $avatars->upload($member, $this->upload('png', 64, 64), '127.0.0.1');
        $avatars->remove($member, $member->id, '127.0.0.1');
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM account_notifications WHERE kind = 'avatar_removed'"));
    }

    public function testProfilesShowStaffBadgesAndRefuseSvg(): void
    {
        [$member, , $moderator] = $this->users();
        $avatars = new AvatarService($this->pdo, $this->config);

        $profile = $avatars->profile($member, $moderator->id);
        self::assertSame('Global Moderator', $profile['badge']);
        self::assertFalse($profile['has_avatar']);
        self::assertNull($avatars->profile($moderator, $member->id)['badge']);

        $svg = tempnam(sys_get_temp_dir(), 'avatar');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"><script>alert(1)</script></svg>');
        try {
            $avatars->upload($member, IncomingFile::forTesting('avatar.svg', $svg), '127.0.0.1');
            self::fail('Expected SVG to be refused.');
        } catch (ApiException $exception) {
            self::assertSame('avatar_type_not_allowed', $exception->errorCode);
        }
    }

    public function testClosingAnAccountForGoodRemovesItsPicture(): void
    {
        [$member] = $this->users();
        $avatars = new AvatarService($this->pdo, $this->config);
        $avatars->upload($member, $this->upload('png', 128, 128), '127.0.0.1');
        $file = ($avatars->imagePath($member->id) ?? self::fail('Expected a stored avatar.'))['path'];

        $this->pdo->beginTransaction();
        $key = $avatars->clearForClosure($member->id);
        $this->pdo->commit();
        self::assertIsString($key);
        $avatars->removeFile($key);

        self::assertFileDoesNotExist($file);
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM users WHERE id = {$member->id} AND avatar_key IS NOT NULL"));
    }

    /** @return array{AuthenticatedUser, AuthenticatedUser, AuthenticatedUser} */
    private function users(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', 'a very secure password', '127.0.0.9');
        $member = $auth->register('Member', 'another secure password', '127.0.0.1');
        $other = $auth->register('Other', 'third secure password', '127.0.0.2');
        $moderator = $auth->register('Moderator', 'fourth secure password', '127.0.0.3');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$moderator->id}, 'global_moderator')");
        $moderator = (new UserRepository($this->pdo))->findAuthenticatedById($moderator->id) ?? self::fail('Moderator vanished.');

        return [$member, $other, $moderator];
    }

    private function upload(string $type, int $width, int $height): IncomingFile
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), intdiv($height, 2), (int) imagecolorallocate($image, 200, 80, 40));
        $path = tempnam(sys_get_temp_dir(), 'avatar');
        self::assertIsString($path);
        $type === 'png' ? imagepng($image, $path) : imagejpeg($image, $path, 90);
        imagedestroy($image);

        return IncomingFile::forTesting("avatar.{$type}", $path);
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }
}
