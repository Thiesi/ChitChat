<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;

/** ChitChat for Android (#417) over real HTTP: dark while the store URL is null, gated, audited. */
final class AndroidAppPromotionHttpTest extends DatabaseTestCase
{
    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];

    public function testShipsDarkAndOnlyASuperAdministratorWithStepUpReachesTheSetting(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', 'a very secure password', '127.0.0.1');
        $admin = $auth->register('Admin', 'another secure password', '127.0.0.2');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$admin->id}, 'admin')");

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = TestScratch::file('android-app-http-log');
        $sessions = TestScratch::directory('android-app-http-sessions');
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, getenv());
        self::assertIsResource($process);
        fclose($pipes[0]);
        $settings = [
            'registration_enabled' => true, 'mfa_required_for_admin_roles' => false,
            'room_message_retention_days' => 0, 'direct_message_retention_days' => 0, 'audit_retention_days' => 0,
            'deleted_attachment_retention_days' => 30, 'orphan_attachment_grace_hours' => 24,
            'realtime_event_retention_hours' => 168, 'login_attempt_retention_days' => 30,
        ];
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            // Present for everyone, and null: the setting is off.
            [, $anonymous] = $this->request('anonymous', '/api/v1/session.php');
            self::assertIsArray($anonymous);
            self::assertArrayHasKey('android_app', $anonymous);
            self::assertNull($anonymous['android_app']);

            // Even with the column switched on behind the API's back, nothing is exposed while
            // the store URL is null, whatever Host the request claims.
            $this->pdo->exec('UPDATE system_settings SET android_app_promotion = TRUE WHERE id = 1');
            foreach (['anonymous' => null, 'spoofed' => 'evil.example'] as $client => $host) {
                [, $session] = $this->request($client, '/api/v1/session.php', null, true, $host);
                self::assertIsArray($session);
                self::assertArrayHasKey('android_app', $session);
                self::assertNull($session['android_app'], $client);
            }
            $this->pdo->exec('UPDATE system_settings SET android_app_promotion = FALSE WHERE id = 1');

            // An Administrator who isn't a Super-Administrator is refused, even with step-up.
            $this->login('admin', 'Admin', 'another secure password');
            self::assertSame(200, $this->request('admin', '/api/v1/step-up.php', ['password' => 'another secure password'])[0]);
            [$status, $refused] = $this->request('admin', '/api/v1/admin/settings/update.php', $settings + ['android_app_promotion' => false]);
            self::assertSame([403, 'forbidden'], [$status, $refused['error']['code'] ?? null]);

            $this->login('root', 'Root', 'a very secure password');
            [$status, $read] = $this->request('root', '/api/v1/admin/settings/get.php');
            self::assertSame(200, $status);
            self::assertFalse($read['settings']['android_app_promotion'] ?? null);
            self::assertFalse($read['settings']['android_app_available'] ?? null);
            [$status, $proof] = $this->request('root', '/api/v1/admin/settings/update.php', $settings + ['android_app_promotion' => false]);
            self::assertSame([403, 'step_up_required'], [$status, $proof['error']['code'] ?? null]);
            self::assertSame(200, $this->request('root', '/api/v1/step-up.php', ['password' => 'a very secure password'])[0]);

            [$status, $error] = $this->request('root', '/api/v1/admin/settings/update.php', $settings + ['android_app_promotion' => 'yes']);
            self::assertSame([400, 'validation_error'], [$status, $error['error']['code'] ?? null]);
            [$status, $unavailable] = $this->request('root', '/api/v1/admin/settings/update.php', $settings + ['android_app_promotion' => true]);
            self::assertSame([409, 'android_app_unavailable'], [$status, $unavailable['error']['code'] ?? null]);
            self::assertSame(0, (int) $this->pdo->query('SELECT android_app_promotion::int FROM system_settings')?->fetchColumn());
            self::assertSame(0, $this->auditCount(), 'Refused changes write no audit record.');

            [$status, $saved] = $this->request('root', '/api/v1/admin/settings/update.php', $settings + ['android_app_promotion' => false]);
            self::assertSame(200, $status);
            self::assertFalse($saved['settings']['android_app_promotion'] ?? null);
            self::assertSame(1, $this->auditCount());
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
        return (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'system.settings_updated'")?->fetchColumn();
    }

    private function login(string $client, string $username, string $password): void
    {
        $this->request($client, '/api/v1/session.php');
        self::assertSame(200, $this->request($client, '/api/v1/login.php', ['username' => $username, 'password' => $password])[0]);
    }

    /** @param ?array<string,mixed> $payload @return array{int,array<string,mixed>|string,array<string,string>} */
    private function request(string $client, string $path, ?array $payload = null, bool $withCsrf = true, ?string $host = null): array
    {
        $cookie = $this->cookies[$client] ??= TestScratch::file('android-app-cookie-' . $client);
        $curl = curl_init($this->baseUrl . $path);
        self::assertNotFalse($curl);
        $headers = [];
        $requestHeaders = $host === null ? [] : ['Host: ' . $host, 'X-Forwarded-Host: ' . $host];
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
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode((object) $payload, JSON_THROW_ON_ERROR)]);
            $requestHeaders[] = 'Content-Type: application/json';
            $requestHeaders[] = 'X-CSRF-Token: ' . ($withCsrf ? ($this->csrf[$client] ?? '') : '');
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $requestHeaders);
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
