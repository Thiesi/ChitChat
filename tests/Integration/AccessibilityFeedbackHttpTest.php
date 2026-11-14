<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\AccessibilityFeedbackService;
use ChitChat\Auth\AuthService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;

final class AccessibilityFeedbackHttpTest extends DatabaseTestCase
{
    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];

    public function testRealHttpGatesValidationAuditAndPublicField(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', 'a very secure password', '127.0.0.1');
        $admin = $auth->register('Admin', 'another secure password', '127.0.0.2');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$admin->id}, 'admin')");
        $default = AccessibilityFeedbackService::DEFAULT_URL;

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = TestScratch::file('a11y-feedback-http-log');
        $sessions = TestScratch::directory('a11y-feedback-http-sessions');
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, getenv());
        self::assertIsResource($process);
        fclose($pipes[0]);
        $base = '/api/v1/admin/settings/accessibility-feedback/';
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            // Public and resolved: signed out, the default is the project's form.
            [, $anonymous] = $this->request('anonymous', '/api/v1/session.php');
            self::assertSame(['url' => $default], $anonymous['accessibility_feedback'] ?? null);
            self::assertSame(401, $this->request('anonymous', $base . 'get.php')[0]);
            self::assertSame(401, $this->request('anonymous', $base . 'update.php', ['enabled' => false, 'url' => null])[0]);

            $this->login('admin', 'Admin', 'another secure password');
            [$status, $refused] = $this->request('admin', $base . 'get.php');
            self::assertSame([403, 'forbidden'], [$status, $refused['error']['code'] ?? null]);
            self::assertSame(200, $this->request('admin', '/api/v1/step-up.php', ['password' => 'another secure password'])[0]);
            [$status, $refused] = $this->request('admin', $base . 'update.php', ['enabled' => false, 'url' => null]);
            self::assertSame([403, 'forbidden'], [$status, $refused['error']['code'] ?? null]);

            $this->login('root', 'Root', 'a very secure password');
            [$status, $current] = $this->request('root', $base . 'get.php');
            self::assertSame(200, $status);
            self::assertSame(['accessibility_feedback' => [
                'enabled' => true, 'url' => null, 'default_url' => $default, 'effective_url' => $default,
            ]], $current);
            [$status, $csrf] = $this->request('root', $base . 'update.php', ['enabled' => false, 'url' => null], false);
            self::assertSame([403, 'csrf_failed'], [$status, $csrf['error']['code'] ?? null]);
            [$status, $proof] = $this->request('root', $base . 'update.php', ['enabled' => false, 'url' => null]);
            self::assertSame([403, 'step_up_required'], [$status, $proof['error']['code'] ?? null]);
            self::assertSame(1, (int) $this->pdo->query('SELECT accessibility_feedback_enabled::int FROM system_settings')?->fetchColumn(), 'Nothing changes without step-up.');
            self::assertSame(200, $this->request('root', '/api/v1/step-up.php', ['password' => 'a very secure password'])[0]);

            foreach ([
                ['enabled' => true, 'url' => 'javascript:alert(1)'],
                ['enabled' => true, 'url' => 'data:text/html,x'],
                ['enabled' => true, 'url' => 'http://example.org'],
                ['enabled' => true, 'url' => 'example.org'],
                ['enabled' => true, 'url' => 'off'],
                ['enabled' => true],
                ['url' => null],
                ['enabled' => 'yes', 'url' => null],
                [],
            ] as $invalid) {
                [$status, $error] = $this->request('root', $base . 'update.php', $invalid);
                self::assertSame([400, 'validation_error'], [$status, $error['error']['code'] ?? null], json_encode($invalid, JSON_THROW_ON_ERROR));
            }
            self::assertSame(0, $this->auditCount());

            $own = 'https://help.example.org/accessibility';
            [$status, $updated] = $this->request('root', $base . 'update.php', ['enabled' => true, 'url' => $own]);
            self::assertSame(200, $status);
            self::assertSame($own, $updated['accessibility_feedback']['effective_url'] ?? null);
            self::assertSame(1, $this->auditCount());
            [, $signedOut] = $this->request('fresh', '/api/v1/session.php');
            self::assertSame(['url' => $own], $signedOut['accessibility_feedback'] ?? null);
            [, $signedIn] = $this->request('root', '/api/v1/session.php');
            self::assertSame(['url' => $own], $signedIn['accessibility_feedback'] ?? null);

            // Off: null in the session; the saved address stays.
            [$status, $off] = $this->request('root', $base . 'update.php', ['enabled' => false, 'url' => $own]);
            self::assertSame(200, $status);
            self::assertNull($off['accessibility_feedback']['effective_url']);
            self::assertSame($own, $off['accessibility_feedback']['url']);
            [, $hidden] = $this->request('fresh', '/api/v1/session.php');
            self::assertIsArray($hidden);
            self::assertArrayHasKey('accessibility_feedback', $hidden);
            self::assertNull($hidden['accessibility_feedback']);
            self::assertSame(2, $this->auditCount());
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            foreach ([$log, $sessions, ...array_values($this->cookies)] as $path) {
                TestScratch::remove($path);
            }
        }
    }

    private function auditCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'system.accessibility_feedback_updated'")?->fetchColumn();
    }

    private function login(string $client, string $username, string $password): void
    {
        $this->request($client, '/api/v1/session.php');
        self::assertSame(200, $this->request($client, '/api/v1/login.php', ['username' => $username, 'password' => $password])[0]);
    }

    /** @param ?array<string,mixed> $payload @return array{int,array<string,mixed>|string,array<string,string>} */
    private function request(string $client, string $path, ?array $payload = null, bool $withCsrf = true): array
    {
        $cookie = $this->cookies[$client] ??= TestScratch::file('a11y-feedback-cookie-' . $client);
        $curl = curl_init($this->baseUrl . $path);
        self::assertNotFalse($curl);
        $headers = [];
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $headers[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
                }
                return strlen($line);
            }]);
        if ($payload !== null) {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode((object) $payload, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($withCsrf ? ($this->csrf[$client] ?? '') : '')]]);
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        self::assertIsString($body, curl_error($curl));
        unset($curl);
        if (str_starts_with($headers['content-type'] ?? '', 'application/json')) {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            if (isset($decoded['csrf_token']) && is_string($decoded['csrf_token'])) {
                $this->csrf[$client] = $decoded['csrf_token'];
            }
            return [$status, $decoded, $headers];
        }
        return [$status, $body, $headers];
    }
}
