<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TemporaryPaths;
use ChitChat\Tests\Support\TestImages;

final class IconHttpTest extends DatabaseTestCase
{
    use TemporaryPaths;
    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];

    public function testRealHttpPreviewAccessPublicCachingAndFaviconCookieBoundary(): void
    {
        if (!\ChitChat\InstallationIcon\IconRenderer::available()) self::markTestSkipped('GD with PNG and WebP is unavailable.');
        $auth = new AuthService($this->pdo,$this->config);
        $auth->register('Root','a very secure password','127.0.0.1');
        $auth->register('Member','another secure password','127.0.0.2');
        $socket = stream_socket_server('tcp://127.0.0.1:0',$errorCode,$errorMessage);
        self::assertIsResource($socket,$errorMessage);
        $address = stream_socket_get_name($socket,false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://'.$address;
        $log = $this->temporaryFile();
        $sessions = $this->temporaryDirectory(true);
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['RATE_LIMIT_APPLICATION_ICON_PREVIEW_MAX_ATTEMPTS'] = '1';
        $process = proc_open([PHP_BINARY,'-d','session.save_path='.$sessions,'-d','post_max_size=128K','-d','display_errors=0',
            '-S',$address,'-t',dirname(__DIR__,2).'/public',dirname(__DIR__).'/Support/icon-http-router.php'],
            [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,null,$environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $base = '/api/v1/admin/settings/application-icon/';
        try {
            PhpHttpFixture::waitUntilReady($process,$address,$log);
            $default = $this->request('public','/favicon.ico');
            self::assertSame(200,$default['status'],$default['body']);
            self::assertArrayNotHasKey('set-cookie',$default['headers']);
            self::assertSame('image/x-icon',$default['headers']['content-type']);
            self::assertSame("\0\0\1\0\1\0",substr($default['body'],0,6));
            self::assertSame('no-cache',$default['headers']['cache-control']);
            $head = $this->request('public','/favicon.ico',null,['X-Test-Head'=>'1']);
            self::assertSame('',$head['body']);
            self::assertSame(strlen($default['body']),(int)$head['headers']['content-length']);
            self::assertArrayNotHasKey('set-cookie',$head['headers']);
            self::assertSame(304,$this->request('public','/favicon.ico',null,['If-None-Match'=>$default['headers']['etag']])['status']);
            self::assertSame(401,$this->request('anonymous',$base.'get.php')['status']);
            $this->login('root','Root','a very secure password');
            $this->login('member','Member','another secure password');
            self::assertSame(403,$this->request('member',$base.'get.php')['status']);
            $get = $this->request('root',$base.'get.php');
            self::assertSame(200,$get['status']);
            self::assertSame('default',$this->json($get)['application_icon']['state']);
            $file = $this->temporaryFile(TestImages::png(600,600));
            $upload = ['icon'=>new \CURLFile($file,'image/png','private-source.png'),'background'=>'#abc'];
            self::assertSame(403,$this->request('root',$base.'update.php',$upload,['X-CSRF-Token'=>'invalid'],true)['status']);
            $needProof = $this->request('root',$base.'update.php',$upload,[],true);
            self::assertSame(403,$needProof['status'],$needProof['body']);
            self::assertSame(200,$this->request('root','/api/v1/step-up.php',['password'=>'a very secure password'])['status']);
            $audits = $this->pdo->query('SELECT count(*) FROM audit_log')->fetchColumn();
            $preview = $this->request('root',$base.'update.php',$upload+['dry_run'=>'1'],[],true);
            self::assertSame(200,$preview['status'],$preview['body']);
            self::assertStringContainsString('no-store',$preview['headers']['cache-control']);
            $data = $this->json($preview);
            foreach (['favicon'=>32,'apple_touch'=>180,'maskable'=>512] as $variant=>$side) {
                self::assertStringStartsWith('data:image/png;base64,',$data['preview'][$variant]);
                $png = base64_decode(substr($data['preview'][$variant],22),true);
                self::assertIsString($png);
                $header = getimagesizefromstring($png);
                self::assertIsArray($header);
                self::assertSame([$side,$side],[$header[0],$header[1]]);
            }
            self::assertSame($audits,$this->pdo->query('SELECT count(*) FROM audit_log')->fetchColumn());
            self::assertSame(0,(int)$this->pdo->query('SELECT count(*) FROM installation_icons')->fetchColumn());
            $limited = $this->request('root',$base.'update.php',$upload+['dry_run'=>'1'],[],true);
            self::assertSame(429,$limited['status'],$limited['body']);
            self::assertSame((int)$limited['headers']['retry-after'],$this->json($limited)['error']['retry_after_seconds']);
            $saved = $this->request('root',$base.'update.php',$upload,[],true);
            self::assertSame(200,$saved['status'],$saved['body']);
            $description = $this->json($saved)['application_icon'];
            $url = $description['renditions']['maskable']['url'];
            $image = $this->request('fresh',$url);
            self::assertSame(200,$image['status']);
            self::assertArrayNotHasKey('set-cookie',$image['headers']);
            self::assertSame('image/png',$image['headers']['content-type']);
            self::assertSame('nosniff',$image['headers']['x-content-type-options']);
            self::assertSame('public, max-age=31536000, immutable',$image['headers']['cache-control']);
            self::assertSame($description['renditions']['maskable']['sha256'],hash('sha256',$image['body']));
            self::assertSame(304,$this->request('fresh',$url,null,['If-None-Match'=>$image['headers']['etag']])['status']);
            $head = $this->request('fresh',$url,null,['X-Test-Head'=>'1']);
            self::assertSame('',$head['body']);
            self::assertSame(strlen($image['body']),(int)$head['headers']['content-length']);
            self::assertSame(404,$this->request('fresh',$url.'x',null,['If-None-Match'=>'*'])['status']);
            $page = $this->request('fresh','/');
            self::assertStringNotContainsString('href="/favicon.svg"',$page['body']);
            self::assertStringContainsString('variant=favicon',$page['body']);
            $manifest = $this->json($this->request('fresh','/manifest.php'));
            self::assertCount(3,$manifest['icons']);
            $session = $this->json($this->request('anonymous','/api/v1/session.php'));
            self::assertSame($description['renditions']['icon_512']['url'],$session['application']['icon']['url']);
            self::assertSame(200,$this->request('root',$base.'reset.php',[])['status']);
            self::assertSame(200,$this->request('fresh',$url)['status']);
            $again = $this->request('root',$base.'update.php',array_replace($upload,['background'=>'#000']),[],true);
            self::assertSame(200,$again['status'],$again['body']);
            self::assertSame(404,$this->request('fresh',$url,null,['If-None-Match'=>$image['headers']['etag']])['status']);
            $discarded = $this->request('root',$base.'update.php',['padding'=>str_repeat('x',140000)],[],true);
            self::assertSame(413,$discarded['status'],$discarded['body']);
            self::assertSame(65536,$this->json($discarded)['error']['max_bytes']);
        } finally {
            if (proc_get_status($process)['running']) proc_terminate($process);
            proc_close($process);
            $this->removeTemporaryPaths();
        }
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
            $headers['X-CSRF-Token'] ??= $this->csrf[$client] ?? '';
            if (!$multipart) {
                $headers['Content-Type'] = 'application/json';
            }
            curl_setopt_array($curl, [CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $multipart ? $payload : json_encode((object) $payload, JSON_THROW_ON_ERROR)]);
        }
        if (isset($headers['X-Test-Head'])) { curl_setopt($curl, CURLOPT_NOBODY, true); unset($headers['X-Test-Head']); }
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
