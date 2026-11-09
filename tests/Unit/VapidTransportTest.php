<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use Base64Url\Base64Url;
use ChitChat\Config;
use ChitChat\Tests\Support\PushTestHttpClient;
use ChitChat\WebPush\LibraryPushTransport;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class VapidTransportTest extends TestCase
{
    public function testRealLibraryRequestKeepsEncryptionAndSignsTheCompleteOrigin(): void
    {
        $keys = VAPID::createVapidKeys();
        $config = $this->config($keys);
        $public = Base64Url::decode($keys['publicKey']);
        $jwk = new JWK(['kty' => 'EC', 'crv' => 'P-256',
            'x' => Base64Url::encode(substr($public, 1, 32)), 'y' => Base64Url::encode(substr($public, 33, 32))]);
        $verifier = new JWSVerifier(new AlgorithmManager([new ES256()]));
        foreach ([
            'https://push.example/path/secret' => 'https://push.example',
            'https://push.example:443/path' => 'https://push.example',
            'https://push.example:8443/path' => 'https://push.example:8443',
            'https://[2001:db8::1]:8443/path' => 'https://[2001:db8::1]:8443',
        ] as $endpoint => $audience) {
            $client = new PushTestHttpClient();
            $transport = new LibraryPushTransport($config, $client);
            $result = $transport->send(['id' => 1, 'user_id' => 2, 'endpoint' => $endpoint,
                'p256dh_key' => $keys['publicKey'], 'auth_key' => Base64Url::encode(random_bytes(16))],
                '{"safe":"test"}', 123, 'high');
            self::assertSame('sent', $result->outcome);
            self::assertCount(1, $client->requests);
            $request = $client->requests[0];
            self::assertSame((string) new \Nyholm\Psr7\Uri($endpoint), (string) $request->getUri());
            self::assertSame('123', $request->getHeaderLine('TTL'));
            self::assertSame('high', $request->getHeaderLine('Urgency'));
            self::assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
            self::assertStringNotContainsString('{"safe":"test"}', (string) $request->getBody());
            self::assertGreaterThan(100, strlen((string) $request->getBody()));
            self::assertMatchesRegularExpression('/^vapid t=([^,]+),/', $request->getHeaderLine('Authorization'));
            preg_match('/^vapid t=([^,]+),/', $request->getHeaderLine('Authorization'), $matches);
            $jws = (new CompactSerializer())->unserialize($matches[1]);
            self::assertTrue($verifier->verifyWithKey($jws, $jwk, 0));
            $claims = json_decode($jws->getPayload(), true, 8, JSON_THROW_ON_ERROR);
            self::assertSame($audience, $claims['aud']);
            self::assertNotSame('https://wrong.example', $claims['aud']);
            // Alter the signed audience without re-signing: verification must fail.
            $parts = explode('.', $matches[1]);
            $claims['aud'] = 'https://wrong.example';
            $parts[1] = Base64Url::encode(json_encode($claims, JSON_THROW_ON_ERROR));
            self::assertFalse($verifier->verifyWithKey((new CompactSerializer())->unserialize(implode('.', $parts)), $jwk, 0));
        }
    }

    public function testOnlyExpirationPrunesAndOnly2xxMeansAcceptance(): void
    {
        $keys = VAPID::createVapidKeys();
        foreach ([201 => 'sent', 302 => 'failed', 400 => 'failed', 401 => 'failed', 403 => 'failed',
            404 => 'expired', 410 => 'expired', 408 => 'retry', 429 => 'retry', 500 => 'retry'] as $status => $outcome) {
            $transport = new LibraryPushTransport($this->config($keys), new PushTestHttpClient($status, ['Retry-After' => '120']));
            $result = $transport->send(['id' => 1, 'user_id' => 2, 'endpoint' => 'https://push.example/secret',
                'p256dh_key' => $keys['publicKey'], 'auth_key' => Base64Url::encode(random_bytes(16))], '{}', 30, 'normal');
            self::assertSame($outcome, $result->outcome, (string) $status);
            self::assertSame($status, $result->httpStatus);
            if ($outcome === 'retry') {
                self::assertSame(120, $result->retryAfterSeconds);
            }
        }
    }

    /** @param array{publicKey:string,privateKey:string} $keys */
    private function config(array $keys): Config
    {
        $base = Config::fromEnvironment();
        $values = [];
        foreach ((new ReflectionClass(Config::class))->getConstructor()->getParameters() as $parameter) {
            $values[$parameter->getName()] = $base->{$parameter->getName()};
        }
        return new Config(...array_replace($values, ['webPushVapidPublicKey' => $keys['publicKey'],
            'webPushVapidPrivateKey' => $keys['privateKey'], 'webPushVapidSubject' => 'mailto:admin@example.test']));
    }
}
