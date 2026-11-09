<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use InvalidArgumentException;

/** A deliberately unambiguous HTTPS endpoint; never included in public errors. */
final readonly class PushEndpoint
{
    private function __construct(public string $url, public string $host, public int $port, public string $origin)
    {
    }

    public static function parse(string $url): self
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f-\xff\\\\]/', $url)) {
            throw new InvalidArgumentException('Invalid push endpoint.');
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Invalid push endpoint.');
        }
        $host = strtolower($parts['host']);
        $literal = str_starts_with($host, '[') && str_ends_with($host, ']');
        if ($literal) {
            $host = substr($host, 1, -1);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException('Invalid push endpoint.');
            }
            $host = (string) inet_ntop((string) inet_pton($host));
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            if (strlen($host) > 253 || preg_match('/[a-z]/', $host) !== 1
                || preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host) !== 1
                || preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*\z/D', $host)) {
                throw new InvalidArgumentException('Invalid push endpoint.');
            }
        }
        $port = $parts['port'] ?? 443;
        if ($port < 1) {
            throw new InvalidArgumentException('Invalid push endpoint.');
        }
        $authority = ($literal ? '[' . $host . ']' : $host) . ($port === 443 ? '' : ':' . $port);
        $origin = 'https://' . $authority;
        return new self($origin . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : ''), $host, $port, $origin);
    }
}