<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\RegistrationChallenge;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;

final class PolicyHttpTest extends DatabaseTestCase
{
    private string $baseUrl;
    /** @var array<string,string> */
    private array $csrf = [];
    /** @var array<string,string> */
    private array $cookies = [];

    public function testRealHttpPoliciesEnforceCsrfRoleStepUpAbuseOrderAndReacceptance(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'policy-http', 'Policy HTTP', '', 'public', 0, 0, '127.0.0.1', 'write');
        $rooms->join($member, $room->id, '127.0.0.2');
        $this->pdo->exec('UPDATE system_settings SET guest_access_enabled = TRUE, registration_rate_limit_max_attempts = 20, registration_min_fill_seconds = 0, registration_proof_of_work_bits = 4 WHERE id = 1');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = TestScratch::file('policy-http-log');
        $sessions = TestScratch::directory('policy-http-sessions');
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            [$status, $public] = $this->request('anonymous', '/api/v1/session.php');
            self::assertSame(200, $status);
            self::assertNull($public['user']);
            self::assertSame(['rules' => null, 'privacy' => null, 'legal_notice' => null], $public['policies']);
            $this->login('root', 'Root', 'a very secure password');
            $this->login('member', 'Member', 'another secure password');
            [$status, $error] = $this->request('member', '/api/v1/admin/policies/preview.php', ['markdown' => 'text']);
            self::assertSame(403, $status);
            self::assertSame('forbidden', $error['error']['code']);
            $update = ['document' => 'rules', 'source' => 'hosted', 'markdown' => '# Community rules', 'material' => false];
            [$status, $error] = $this->request('root', '/api/v1/admin/policies/update.php', $update);
            self::assertSame(403, $status);
            self::assertSame('step_up_required', $error['error']['code']);
            [$status, $preview] = $this->request('root', '/api/v1/admin/policies/preview.php', ['markdown' => $update['markdown']]);
            self::assertSame(200, $status); // Preview itself does not ask for step-up.
            self::assertSame('<h2>Community rules</h2>' . "\n", $preview['html']);
            self::assertSame(200, $this->request('root', '/api/v1/step-up.php', ['password' => 'a very secure password'])[0]);
            [$status, $published] = $this->request('root', '/api/v1/admin/policies/update.php', $update);
            self::assertSame(200, $status);
            self::assertSame(1, $published['document']['version']);
            [$status, $shown] = $this->request('anonymous', '/api/v1/policies/show.php?document=rules');
            self::assertSame(200, $status);
            self::assertSame($preview['html'], $shown['document']['html']);
            self::assertSame($update['markdown'], $shown['document']['markdown']);
            [$status, $session] = $this->request('member', '/api/v1/session.php');
            self::assertTrue($session['user']['rules']['acceptance_required']);
            self::assertNotEmpty(parse_url($session['policies']['rules']['url'], PHP_URL_HOST));
            [$status, $error] = $this->request('member', '/api/v1/policies/accept.php', ['version' => 1], false);
            self::assertSame(403, $status);
            self::assertSame('csrf_failed', $error['error']['code']);
            self::assertSame(200, $this->request('member', '/api/v1/policies/accept.php', ['version' => 1])[0]);
            self::assertSame(201, $this->request('member', '/api/v1/rooms/send.php', ['room_id' => $room->id, 'body' => 'Before material update'])[0]);

            $registration = ['username' => 'NewHttpMember', 'password' => 'a safe new password', 'challenge_nonce' => 'wrong', 'challenge_solution' => '0'];
            [$status, $error] = $this->request('anonymous', '/api/v1/register.php', $registration);
            self::assertSame(400, $status);
            self::assertSame('registration_challenge_missing', $error['error']['code']);
            $registration = ['username' => 'NewHttpMember', 'password' => 'a safe new password'] + $this->challenge('anonymous', '/api/v1/registration-challenge.php');
            [$status, $error] = $this->request('anonymous', '/api/v1/register.php', $registration);
            self::assertSame(400, $status);
            self::assertSame('rules_acceptance_required', $error['error']['code']);
            $registration = ['username' => 'NewHttpMember', 'password' => 'a safe new password', 'accepted_rules_version' => 99]
                + $this->challenge('anonymous', '/api/v1/registration-challenge.php');
            [$status, $error] = $this->request('anonymous', '/api/v1/register.php', $registration);
            self::assertSame(409, $status);
            self::assertSame('rules_version_outdated', $error['error']['code']);
            $registration = ['username' => 'NewHttpMember', 'password' => 'a safe new password', 'accepted_rules_version' => 1]
                + $this->challenge('anonymous', '/api/v1/registration-challenge.php');
            [$status, $created] = $this->request('anonymous', '/api/v1/register.php', $registration);
            self::assertSame(201, $status);
            self::assertSame(1, $created['user']['rules']['accepted_version']);
            self::assertFalse($created['user']['rules']['acceptance_required']);
            $this->request('guest', '/api/v1/session.php');
            [$status, $error] = $this->request('guest', '/api/v1/guest/start.php', ['challenge_nonce' => 'wrong', 'challenge_solution' => '0']);
            self::assertSame(400, $status);
            self::assertSame('registration_challenge_missing', $error['error']['code']);
            [$status, $error] = $this->request('guest', '/api/v1/guest/start.php', $this->challenge('guest', '/api/v1/guest/challenge.php'));
            self::assertSame(400, $status);
            self::assertSame('rules_acceptance_required', $error['error']['code']);
            [$status, $guest] = $this->request('guest', '/api/v1/guest/start.php', ['accepted_rules_version' => 1]
                + $this->challenge('guest', '/api/v1/guest/challenge.php'));
            self::assertSame(201, $status);
            self::assertTrue($guest['user']['guest']);
            self::assertSame(1, $guest['user']['rules']['accepted_version']);

            $update['material'] = true;
            $update['markdown'] = 'Updated community rules';
            self::assertSame(200, $this->request('root', '/api/v1/admin/policies/update.php', $update)[0]);
            [$status, $error] = $this->request('member', '/api/v1/rooms/send.php', ['room_id' => $room->id, 'body' => 'Must not be sent']);
            self::assertSame(403, $status);
            self::assertSame('rules_acceptance_required', $error['error']['code']);
            self::assertSame(200, $this->request('member', '/api/v1/rooms/messages.php?room_id=' . $room->id)[0]);
            self::assertSame(200, $this->request('member', '/api/v1/users/ignore.php', ['user_id' => $root->id, 'ignored' => true])[0]);
            [$status, $error] = $this->request('member', '/api/v1/policies/accept.php', ['version' => 1]);
            self::assertSame(409, $status);
            self::assertSame('rules_version_outdated', $error['error']['code']);
            self::assertSame(200, $this->request('member', '/api/v1/policies/accept.php', ['version' => 2])[0]);
            self::assertSame(201, $this->request('member', '/api/v1/rooms/send.php', ['room_id' => $room->id, 'body' => 'After material update'])[0]);
            [$status, $error] = $this->request('root', '/api/v1/admin/policies/preview.php', ['markdown' => str_repeat('a', 65537)]);
            self::assertSame(400, $status);
            self::assertSame('validation_error', $error['error']['code']);
            self::assertSame(200, $this->request('root', '/api/v1/admin/policies/update.php', [
                'document' => 'privacy', 'source' => 'external', 'url' => 'https://127.0.0.1:9/not-fetched', 'material' => true])[0]);
            [$status, $external] = $this->request('anonymous', '/api/v1/policies/show.php?document=privacy');
            self::assertSame(200, $status);
            self::assertSame('https://127.0.0.1:9/not-fetched', $external['document']['url']);
            self::assertNull($external['document']['html']);
            self::assertNull($external['document']['markdown']);
            self::assertSame(200, $this->request('root', '/api/v1/admin/policies/update.php', ['document' => 'rules', 'source' => 'none'])[0]);
            self::assertSame(404, $this->request('anonymous', '/api/v1/policies/show.php?document=rules')[0]);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            TestScratch::remove($log);
            TestScratch::remove($sessions);
            foreach ($this->cookies as $cookie) {
                TestScratch::remove($cookie);
            }
        }
    }

    private function login(string $client, string $username, string $password): void
    {
        $this->request($client, '/api/v1/session.php');
        [$status, $session] = $this->request($client, '/api/v1/login.php', ['username' => $username, 'password' => $password]);
        self::assertSame(200, $status);
        self::assertArrayHasKey('rules', $session['user']);
    }

    /** @return array{challenge_nonce:string,challenge_solution:string} */
    private function challenge(string $client, string $endpoint): array
    {
        [$status, $response] = $this->request($client, $endpoint);
        self::assertSame(200, $status);
        $challenge = $response['challenge'];
        self::assertIsArray($challenge);
        $solution = 0;
        while (!RegistrationChallenge::meetsDifficulty(hash('sha256', $challenge['nonce'] . ':' . $solution, true), $challenge['bits'])) {
            $solution++;
        }
        return ['challenge_nonce' => $challenge['nonce'], 'challenge_solution' => (string) $solution];
    }

    /** @param ?array<string,mixed> $payload @return array{int,array<string,mixed>} */
    private function request(string $client, string $path, ?array $payload = null, bool $withCsrf = true): array
    {
        $cookie = $this->cookies[$client] ??= TestScratch::file('policy-cookie-' . $client);
        $curl = curl_init($this->baseUrl . $path);
        self::assertNotFalse($curl);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 10]);
        if ($payload !== null) {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($withCsrf ? ($this->csrf[$client] ?? '') : '')]]);
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        self::assertIsString($body, curl_error($curl));
        unset($curl); // Flush the cookie jar before the next request.
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        if (isset($decoded['csrf_token']) && is_string($decoded['csrf_token'])) {
            $this->csrf[$client] = $decoded['csrf_token'];
        }
        return [$status, $decoded];
    }
}
