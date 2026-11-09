<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use Base64Url\Base64Url;
use ChitChat\Http\ApiException;
use ChitChat\WebPush\PushAddressPolicy;
use ChitChat\WebPush\PushDestinationGuard;
use ChitChat\WebPush\PushDnsResolver;
use ChitChat\WebPush\PushEndpoint;
use ChitChat\WebPush\PushKeyMaterial;
use ChitChat\WebPush\GuardedPushHttpClient;
use ChitChat\Tests\Support\PushTestHttpClient;
use InvalidArgumentException;
use Minishlink\WebPush\VAPID;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class PushDestinationTest extends TestCase
{
    public function testStrictKeyValidationAndCanonicalPadding(): void
    {
        $key = VAPID::createVapidKeys()['publicKey'];
        $auth = Base64Url::encode(random_bytes(16));
        $material = PushKeyMaterial::fromInput($key . '=', $auth . '==');
        self::assertSame($key, $material->publicKey);
        self::assertSame($auth, $material->auth);
        foreach ([
            ['', $auth], [$key, ''], ['abc', $auth], [$key, $auth . '='],
            [Base64Url::encode("\x04" . str_repeat("\0", 64)), $auth],
            [Base64Url::encode("\x03" . random_bytes(64)), $auth],
            [$key, Base64Url::encode(random_bytes(17))], [str_repeat('a', 100000), $auth],
            [$key . "\n", $auth], [str_replace('-', '+', '-' . substr($key, 1)), $auth],
        ] as [$point, $secret]) {
            try {
                PushKeyMaterial::fromInput($point, $secret);
                self::fail('Invalid keys were accepted.');
            } catch (ApiException $exception) {
                self::assertSame(400, $exception->status);
                self::assertStringNotContainsString($auth, $exception->getMessage());
            }
        }
    }

    public function testUnambiguousEndpointsAndBlockedRepresentations(): void
    {
        self::assertSame('https://push.example/a?q=x', PushEndpoint::parse('https://PUSH.example:443/a?q=x')->url);
        self::assertSame('https://[2606:4700:4700::1111]:8443', PushEndpoint::parse('https://[2606:4700:4700::1111]:8443/a')->origin);
        foreach (['http://push.example/a', 'https://name:pass@push.example/a',
            'https://push.example/a#x', "https://push.example/\r\nsecret",
            'https://push.example\\@127.0.0.1/a', 'https://127.1/a', 'https://2130706433/a',
            'https://0177.0.0.1/a', 'https://0x7f000001/a', 'https://push.example./a',
            'https://[fe80::1%25eth0]/a', 'https://push.example:0/a', 'https://push.example:65536/a',
        ] as $url) {
            try {
                PushEndpoint::parse($url);
                self::fail('Invalid endpoint was accepted: ' . $url);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEveryAddressAndPrivateOriginPlusRangeAreChecked(): void
    {
        $policy = new PushAddressPolicy();
        foreach (['8.8.8.8', '1.1.1.1', '192.0.0.9', '2606:4700:4700::1111'] as $ip) {
            self::assertTrue($policy->allows('https://push.example', $ip), $ip);
        }
        $denied = ['0.0.0.0', '127.0.0.1', '10.0.0.1', '100.64.0.1', '172.16.1.2', '192.168.1.2',
            '169.254.169.254', '168.63.129.16', '100.100.100.200', '224.0.0.1', '255.255.255.255',
            '192.0.2.1', '198.18.0.1', '198.51.100.2', '203.0.113.2',
            '::', '::1', '::ffff:127.0.0.1', '64:ff9b::7f00:1', '2002:7f00:1::',
            '2001:db8::1', '2001::1234', '3fff::1', 'fe80::1', 'ff02::1', 'fc00::1'];
        foreach ($denied as $ip) {
            self::assertFalse($policy->allows('https://push.example', $ip), $ip);
        }
        $private = new PushAddressPolicy([['origin' => 'https://push.example:8443', 'cidrs' => ['10.1.0.0/16', 'fc00::/7']]]);
        self::assertTrue($private->allows('https://push.example:8443', '10.1.2.3'));
        self::assertTrue($private->allows('https://push.example:8443', 'fd00::123'));
        self::assertFalse($private->allows('https://push.example', '10.1.2.3'));
        self::assertFalse($private->allows('https://push.example:8443', '10.2.2.3'));
        $broad = new PushAddressPolicy([['origin' => 'https://push.example', 'cidrs' => ['0.0.0.0/0', '::/0']]]);
        foreach (['127.0.0.1', '169.254.169.254', '168.63.129.16', '100.100.100.200', '::1', '::ffff:10.1.2.3',
            'fe80::1', 'ff00::1', 'fd00:ec2::254', 'fd20:ce::254'] as $ip) {
            self::assertFalse($broad->allows('https://push.example', $ip), $ip);
        }
        $resolver = new class implements PushDnsResolver {
            public function resolve(string $host): array { return ['8.8.8.8', '::1']; }
        };
        $this->expectException(InvalidArgumentException::class);
        (new PushDestinationGuard($resolver, $policy))->validate('https://push.example/secret');
    }

    public function testSendRechecksDnsAndPinsOnlyValidatedAddresses(): void
    {
        $resolver = new class implements PushDnsResolver {
            public int $calls = 0;
            public function resolve(string $host): array {
                return ++$this->calls === 1 ? ['8.8.8.8', '2606:4700:4700::1111'] : ['127.0.0.1'];
            }
        };
        $network = new PushTestHttpClient();
        $captured = [];
        $client = new GuardedPushHttpClient(new PushDestinationGuard($resolver, new PushAddressPolicy()),
            static function (array $options) use ($network, &$captured): PushTestHttpClient {
                $captured = $options;
                return $network;
            });
        $request = new Request('POST', 'https://push.example:8443/secret');
        $client->sendRequest($request);
        self::assertSame(['push.example:8443:8.8.8.8,[2606:4700:4700::1111]'], $captured[CURLOPT_RESOLVE]);
        self::assertFalse($captured[CURLOPT_FOLLOWLOCATION]);
        self::assertSame('', $captured[CURLOPT_PROXY]);
        self::assertTrue($captured[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $captured[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(10000, $captured[CURLOPT_TIMEOUT_MS]);
        self::assertSame(0, $captured[CURLOPT_XFERINFOFUNCTION](curl_init(), 0, 65536, 0, 0));
        self::assertSame(1, $captured[CURLOPT_XFERINFOFUNCTION](curl_init(), 0, 65537, 0, 0));
        try {
            $client->sendRequest($request);
            self::fail('Rebound DNS address was sent.');
        } catch (InvalidArgumentException) {
            self::assertCount(1, $network->requests);
            self::assertSame(2, $resolver->calls);
        }
    }
}