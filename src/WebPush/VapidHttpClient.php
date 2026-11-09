<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use ChitChat\Config;
use InvalidArgumentException;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\VAPID;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Fix v11's port-less audience without modifying vendor code or encryption. */
final class VapidHttpClient implements ClientInterface
{
    /** @var array{subject:string,publicKey:string,privateKey:string} Decoded by VAPID. */
    private readonly array $keys;

    public function __construct(private readonly ClientInterface $client, Config $config)
    {
        $this->keys = VAPID::validate([
            'subject' => $config->webPushVapidSubject,
            'publicKey' => $config->webPushVapidPublicKey,
            'privateKey' => $config->webPushVapidPrivateKey,
        ]);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        if (strtolower($uri->getScheme()) !== 'https' || $uri->getHost() === '' || $uri->getUserInfo() !== '') {
            throw new InvalidArgumentException('Invalid push service origin.');
        }
        $host = strtolower($uri->getHost());
        if (str_contains($host, ':')) {
            $host = '[' . trim($host, '[]') . ']';
        }
        $port = $uri->getPort();
        $audience = 'https://' . $host . ($port === null || $port === 443 ? '' : ':' . $port);
        $headers = VAPID::getVapidHeaders($audience, $this->keys['subject'],
            $this->keys['publicKey'], $this->keys['privateKey'], ContentEncoding::aes128gcm);
        return $this->client->sendRequest($request->withHeader('Authorization', $headers['Authorization']));
    }
}
