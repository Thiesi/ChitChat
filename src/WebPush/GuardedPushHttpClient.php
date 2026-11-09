<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use Closure;
use Http\Client\Curl\Client;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** DNS is checked for each send, and cannot be resolved again by the HTTP stack. */
final readonly class GuardedPushHttpClient implements ClientInterface
{
    /** @param (Closure(array<int,mixed>):ClientInterface)|null $clientFactory */
    public function __construct(private PushDestinationGuard $guard, private ?Closure $clientFactory = null)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $destination = $this->guard->validate((string) $request->getUri());
        $endpoint = $destination['endpoint'];
        $options = self::options($endpoint, $destination['addresses']);
        // A fresh handle per attempt avoids pooled connections, alternative-service state,
        // DNS cache reuse and a previous private-provider exception affecting this send.
        $client = $this->clientFactory !== null ? ($this->clientFactory)($options) : new Client(options: $options);
        return $client->sendRequest($request);
    }

    /**
     * @param non-empty-list<string> $addresses
     * @return array<int,mixed>
     */
    public static function options(PushEndpoint $endpoint, array $addresses): array
    {
        $pinned = array_map(static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $addresses);
        $resolveHost = str_contains($endpoint->host, ':') ? '[' . $endpoint->host . ']' : $endpoint->host;
        return [
            CURLOPT_RESOLVE => filter_var($endpoint->host, FILTER_VALIDATE_IP) !== false ? []
                : [$resolveHost . ':' . $endpoint->port . ':' . implode(',', $pinned)],
            CURLOPT_CONNECTTIMEOUT_MS => 3000, CURLOPT_TIMEOUT_MS => 10000,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
            CURLOPT_MAXFILESIZE_LARGE => 65536, CURLOPT_NOPROGRESS => false,
            // Older libcurl only checks MAXFILESIZE when Content-Length is known.
            CURLOPT_XFERINFOFUNCTION => static fn (\CurlHandle $handle, float $total, float $downloaded, float $uploadTotal, float $uploaded): int
                => ($total > 65536 || $downloaded > 65536) ? 1 : 0,
        ];
    }
}