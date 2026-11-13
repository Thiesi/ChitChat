<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TemporaryPaths;
use ChitChat\Tests\Support\TestImages;
use ChitChat\Upload\AttachmentFileStore;

final class AttachmentImageHttpTest extends DatabaseTestCase
{
    use TemporaryPaths;

    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];

    public function testRealHttpPreviewsRevalidateAuthorizationDeletionAndIntegrityBefore304(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD with WebP is unavailable.');
        }
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $other = $auth->register('Other', 'a third safe password', '127.0.0.3');
        $room = (new RoomService($this->pdo))->create($root, 'image-private', 'Private images', '', 'private', 0, 0, '127.0.0.1');
        $storage = $this->temporaryDirectory(true);
        $config = $this->configWith(['attachmentStoragePath' => $storage]);
        $bytes = TestImages::jpeg(6);
        $source = $this->temporaryFile($bytes);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = $this->temporaryFile();
        $sessions = $this->temporaryDirectory(true);
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['ATTACHMENT_STORAGE_PATH'] = $storage;
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            $this->login('root', 'Root', 'a very secure password');
            $this->login('member', 'Member', 'another secure password');
            $this->login('other', 'Other', 'a third safe password');
            $created = $this->request('root', '/api/v1/attachments/upload.php',
                ['room_id' => (string) $room->id, 'file' => new \CURLFile($source, 'image/jpeg', 'portrait.jpg')], [], true);
            self::assertSame(201, $created['status'], $created['body']);
            $message = $this->json($created)['message'];
            $attachment = $message['attachment'];
            self::assertSame([60, 100], [$attachment['width'], $attachment['height']]);
            self::assertNotNull($attachment['preview']);
            $path = $attachment['preview']['url'];
            self::assertSame('/api/v1/attachments/download.php?id=' . $attachment['id'] . '&variant=preview', $path);
            $preview = $this->request('root', $path);
            $this->assertPreview($preview, $attachment['preview']['sha256']);
            $etag = $preview['headers']['etag'];
            foreach ([$etag, 'W/' . $etag, '"unrelated", ' . $etag, '*'] as $tag) {
                $cached = $this->request('root', $path, null, ['If-None-Match' => $tag]);
                self::assertSame(304, $cached['status']);
                self::assertSame('', $cached['body']);
                self::assertSame($etag, $cached['headers']['etag']);
                self::assertSame('private, no-cache', $cached['headers']['cache-control']);
                self::assertTrue(!isset($cached['headers']['content-type']) || $cached['headers']['content-type'] === 'image/webp');
            }
            self::assertSame(200, $this->request('root', $path, null, ['If-None-Match' => '"wrong"'])['status']);
            $original = $this->request('root', '/api/v1/attachments/download.php?id=' . $attachment['id'] . '&inline=1',
                null, ['If-None-Match' => $etag]);
            self::assertSame(200, $original['status']);
            self::assertSame($bytes, $original['body']);
            self::assertSame('private, no-store', $original['headers']['cache-control']);
            self::assertArrayNotHasKey('etag', $original['headers']);
            self::assertSame(401, $this->request('anonymous', $path, null, ['If-None-Match' => '*'])['status']);
            // Not in the private room: answered like a missing attachment, even with a valid ETag (#326).
            self::assertSame(404, $this->request('member', $path, null, ['If-None-Match' => $etag])['status']);
            self::assertSame(400, $this->request('root', $path . 'x')['status']);
            self::assertSame(400, $this->request('root', '/api/v1/attachments/download.php?id=' . $attachment['id'] . '&variant[]=preview')['status']);
            $metadataReply = $this->request('root', '/api/v1/attachments/metadata.php?room_id=' . $room->id . '&message_ids=' . $message['id']);
            self::assertSame(200, $metadataReply['status']);
            $metadata = $this->json($metadataReply)['attachments'][0];
            self::assertSame($attachment['preview'], $metadata['preview']);
            self::assertSame(60, $metadata['width']);

            $directCreated = $this->request('root', '/api/v1/direct-messages/attachments/upload.php',
                ['recipient_user_id' => (string) $member->id, 'file' => new \CURLFile($source, 'image/jpeg', 'portrait.jpg')], [], true);
            self::assertSame(201, $directCreated['status'], $directCreated['body']);
            $directMessage = $this->json($directCreated)['message'];
            $directMetadata = $this->request('member', '/api/v1/direct-messages/attachments/metadata.php?message_ids=' . $directMessage['id']);
            self::assertSame(200, $directMetadata['status']);
            $direct = $this->json($directMetadata)['attachments'][0];
            self::assertSame([60, 100], [$direct['width'], $direct['height']]);
            $directPath = $direct['preview']['url'];
            self::assertSame('/api/v1/direct-messages/attachments/download.php?id=' . $direct['id'] . '&variant=preview', $directPath);
            $directPreview = $this->request('member', $directPath);
            $this->assertPreview($directPreview, $direct['preview']['sha256']);
            $directEtag = $directPreview['headers']['etag'];
            self::assertSame(304, $this->request('root', $directPath, null, ['If-None-Match' => $directEtag])['status']);
            self::assertSame(404, $this->request('other', $directPath, null, ['If-None-Match' => $directEtag])['status']);
            self::assertSame(401, $this->request('anonymous', $directPath, null, ['If-None-Match' => '*'])['status']);
            // Stale cache validators never bypass invalid rendition bytes or a missing original.
            $files = new AttachmentFileStore($config);
            $previewKey = $this->pdo->query('SELECT preview_storage_key FROM direct_message_attachments WHERE id = ' . $direct['id'])->fetchColumn();
            self::assertIsString($previewKey);
            file_put_contents($files->pathForKey($previewKey), 'truncated');
            $invalid = $this->request('member', $directPath, null, ['If-None-Match' => $directEtag]);
            self::assertSame(410, $invalid['status']);
            self::assertSame('attachment_storage_invalid', $this->json($invalid)['error']['code']);
            $key = $this->pdo->query('SELECT storage_key FROM direct_message_attachments WHERE id = ' . $direct['id'])->fetchColumn();
            self::assertIsString($key);
            $files->remove($key);
            self::assertSame(410, $this->request('root', $directPath, null, ['If-None-Match' => '*'])['status']);

            (new MessageService($this->pdo))->delete($root, $message['id'], '127.0.0.1');
            $deleted = $this->request('root', $path, null, ['If-None-Match' => $etag]);
            self::assertSame(410, $deleted['status']);
            self::assertSame('attachment_deleted', $this->json($deleted)['error']['code']);
            self::assertArrayNotHasKey('etag', $deleted['headers']);
            self::assertSame('no-store', $deleted['headers']['cache-control']);

            $legacyCreated = $this->request('root', '/api/v1/attachments/upload.php',
                ['room_id' => (string) $room->id, 'file' => new \CURLFile($source, 'image/jpeg', 'legacy.jpg')], [], true);
            self::assertSame(201, $legacyCreated['status'], $legacyCreated['body']);
            $legacy = $this->json($legacyCreated)['message']['attachment'];
            $legacyKey = $this->pdo->query('SELECT preview_storage_key FROM attachments WHERE id = ' . $legacy['id'])->fetchColumn();
            self::assertIsString($legacyKey);
            $files->remove($legacyKey);
            $this->pdo->exec('UPDATE attachments SET image_width = NULL, image_height = NULL, preview_storage_key = NULL, preview_width = NULL, preview_height = NULL, preview_size_bytes = NULL, preview_sha256 = NULL WHERE id = ' . $legacy['id']);
            $missing = $this->request('root', '/api/v1/attachments/download.php?id=' . $legacy['id'] . '&variant=preview', null, ['If-None-Match' => '*']);
            self::assertSame(404, $missing['status']);
            self::assertSame('attachment_preview_unavailable', $this->json($missing)['error']['code']);
            self::assertNull($this->pdo->query('SELECT preview_storage_key FROM attachments WHERE id = ' . $legacy['id'])->fetchColumn());
            $legacyOriginal = $this->request('root', '/api/v1/attachments/download.php?id=' . $legacy['id']);
            self::assertSame(200, $legacyOriginal['status']);
            self::assertSame($bytes, $legacyOriginal['body']);

            // Public room visibility does not bypass the existing age gate for either variant.
            $aged = (new RoomService($this->pdo))->create($root, 'age-images', 'Adults', '', 'public', 18, 0, '127.0.0.1');
            $agedUpload = $this->request('root', '/api/v1/attachments/upload.php',
                ['room_id' => (string) $aged->id, 'file' => new \CURLFile($source, 'image/jpeg', 'adult.jpg')], [], true);
            self::assertSame(201, $agedUpload['status'], $agedUpload['body']);
            $agedAttachment = $this->json($agedUpload)['message']['attachment'];
            $ageDenied = $this->request('member', '/api/v1/attachments/download.php?id=' . $agedAttachment['id'] . '&variant=preview', null, ['If-None-Match' => '*']);
            self::assertSame(403, $ageDenied['status']);
            self::assertSame('birth_date_required', $this->json($ageDenied)['error']['code']);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            $this->removeTemporaryPaths();
        }
    }

    /**
     * @param array{status:int,body:string,headers:array<string,string>} $response
     */
    private function assertPreview(array $response, string $sha): void
    {
        self::assertSame(200, $response['status'], $response['body']);
        self::assertSame('image/webp', $response['headers']['content-type']);
        self::assertSame('private, no-cache', $response['headers']['cache-control']);
        self::assertSame('"' . $sha . '"', $response['headers']['etag']);
        self::assertSame($sha, hash('sha256', $response['body']), 'bytes=' . strlen($response['body']) . '; prefix=' . bin2hex(substr($response['body'], 0, 100)));
        self::assertSame('nosniff', $response['headers']['x-content-type-options']);
        self::assertStringContainsString('sandbox', $response['headers']['content-security-policy']);
        $size = getimagesizefromstring($response['body']);
        self::assertIsArray($size);
        self::assertSame([60, 100], [$size[0], $size[1]]);
        self::assertStringNotContainsString('private GPS/camera metadata', $response['body']);
    }

    private function login(string $client, string $username, string $password): void
    {
        $this->request($client, '/api/v1/session.php');
        $reply = $this->request($client, '/api/v1/login.php', ['username' => $username, 'password' => $password]);
        self::assertSame(200, $reply['status'], $reply['body']);
    }

    /**
     * @param array{status:int,body:string,headers:array<string,string>} $response
     * @return array<string,mixed>
     */
    private function json(array $response): array
    {
        $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /**
     * @param ?array<string,mixed> $payload
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private function request(string $client, string $path, ?array $payload = null, array $headers = [], bool $multipart = false): array
    {
        $cookie = $this->cookies[$client] ??= $this->temporaryFile();
        $curl = curl_init($this->baseUrl . $path);
        self::assertNotFalse($curl);
        $received = [];
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $received[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            }]);
        if ($payload !== null) {
            $headers['X-CSRF-Token'] = $this->csrf[$client] ?? '';
            if (!$multipart) {
                $headers['Content-Type'] = 'application/json';
            }
            curl_setopt_array($curl, [CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $multipart ? $payload : json_encode((object) $payload, JSON_THROW_ON_ERROR)]);
        }
        if ($headers !== []) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, array_map(
                static fn (string $name, string $value): string => $name . ': ' . $value, array_keys($headers), array_values($headers)));
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        self::assertIsString($body, curl_error($curl));
        unset($curl);
        if (str_contains($received['content-type'] ?? '', 'application/json')) {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && is_string($decoded['csrf_token'] ?? null)) {
                $this->csrf[$client] = $decoded['csrf_token'];
            }
        }
        return ['status' => $status, 'body' => $body, 'headers' => $received];
    }
}
