<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use InvalidArgumentException;

/** Only this push-specific policy can permit an explicitly configured private provider. */
final class PushAddressPolicy
{
    private const HARD_DENY = [
        '0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '240.0.0.0/4',
        '100.100.100.200/32', '168.63.129.16/32',
        '::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48',
        '100::/64', '2001::/32', '2002::/16', 'fe80::/10', 'ff00::/8',
        'fd00:ec2::254/128', 'fd20:ce::254/128',
    ];
    private const NON_GLOBAL_V4 = [
        '10.0.0.0/8', '100.64.0.0/10', '172.16.0.0/12', '192.0.0.0/24', '192.0.0.0/29',
        '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24',
    ];
    private const NON_GLOBAL_V6 = [
        '2001::/23', '2001:db8::/32', '3fff::/20',
    ];

    // More-specific globally reachable allocations inside IANA's non-global 2001::/23.
    private const GLOBAL_V6_EXCEPTIONS = [
        '2001:1::1/128', '2001:1::2/128', '2001:1::3/128', '2001:3::/32',
        '2001:4:112::/48', '2001:20::/28', '2001:30::/28',
    ];

    /** @var list<array{origin:string,cidrs:list<string>}> */
    private readonly array $privateProviders;

    /** @param array<mixed> $privateProviders */
    public function __construct(array $privateProviders = [])
    {
        if (count($privateProviders) > 32) {
            throw new InvalidArgumentException('WEB_PUSH_PRIVATE_PROVIDERS allows at most 32 origins.');
        }
        $validated = [];
        foreach ($privateProviders as $provider) {
            if (!is_array($provider) || count($provider) !== 2 || !isset($provider['origin'], $provider['cidrs'])
                || !is_string($provider['origin']) || !is_array($provider['cidrs'])
                || !array_is_list($provider['cidrs']) || $provider['cidrs'] === [] || count($provider['cidrs']) > 32) {
                throw new InvalidArgumentException('Invalid WEB_PUSH_PRIVATE_PROVIDERS entry.');
            }
            $origin = PushEndpoint::parse($provider['origin']);
            if ($origin->url !== $origin->origin . '/' || rtrim($provider['origin'], '/') !== $origin->origin) {
                throw new InvalidArgumentException('Private push providers must be canonical HTTPS origins.');
            }
            $cidrs = [];
            foreach ($provider['cidrs'] as $cidr) {
                if (!is_string($cidr) || !self::validCidr($cidr)) {
                    throw new InvalidArgumentException('Invalid private push provider CIDR.');
                }
                $cidrs[] = $cidr;
            }
            $validated[] = ['origin' => $origin->origin, 'cidrs' => $cidrs];
        }
        $this->privateProviders = $validated;
    }

    /** @return list<array{origin:string,cidrs:list<string>}> */
    public static function fromJson(string $json): array
    {
        try {
            $providers = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('WEB_PUSH_PRIVATE_PROVIDERS must be a JSON list.');
        }
        if (!is_array($providers) || !array_is_list($providers)) {
            throw new InvalidArgumentException('WEB_PUSH_PRIVATE_PROVIDERS must be a JSON list.');
        }
        $entries = [];
        foreach ($providers as $provider) {
            if (!$provider instanceof \stdClass) {
                throw new InvalidArgumentException('Invalid WEB_PUSH_PRIVATE_PROVIDERS entry.');
            }
            $entries[] = get_object_vars($provider);
        }
        return (new self($entries))->privateProviders;
    }

    public function allows(string $origin, string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false || self::inAny($address, self::HARD_DENY)) {
            return false;
        }
        $v4 = strlen((string) inet_pton($address)) === 4;
        $global = $v4
            ? (!self::inAny($address, self::NON_GLOBAL_V4) || in_array($address, ['192.0.0.9', '192.0.0.10'], true))
            : (self::contains('2000::/3', $address) && (!self::inAny($address, self::NON_GLOBAL_V6) || self::inAny($address, self::GLOBAL_V6_EXCEPTIONS)));
        if ($global) {
            return true;
        }
        foreach ($this->privateProviders as $provider) {
            if ($provider['origin'] === $origin && self::inAny($address, $provider['cidrs'])) {
                return true;
            }
        }
        return false;
    }

    private static function validCidr(string $cidr): bool
    {
        $parts = explode('/', $cidr);
        return count($parts) === 2 && filter_var($parts[0], FILTER_VALIDATE_IP) !== false
            && ctype_digit($parts[1]) && (int) $parts[1] <= (strlen((string) inet_pton($parts[0])) * 8);
    }

    public static function contains(string $cidr, string $address): bool
    {
        [$network, $prefix] = explode('/', $cidr);
        $networkBytes = inet_pton($network);
        $addressBytes = inet_pton($address);
        if ($networkBytes === false || $addressBytes === false || strlen($networkBytes) !== strlen($addressBytes)) {
            return false;
        }
        $bits = (int) $prefix;
        $whole = intdiv($bits, 8);
        $rest = $bits % 8;
        return substr($networkBytes, 0, $whole) === substr($addressBytes, 0, $whole)
            && ($rest === 0 || ((ord($networkBytes[$whole]) ^ ord($addressBytes[$whole])) & (0xff << (8 - $rest))) === 0);
    }

    /** @param list<string> $ranges */
    private static function inAny(string $address, array $ranges): bool
    {
        foreach ($ranges as $cidr) {
            if (self::contains($cidr, $address)) {
                return true;
            }
        }
        return false;
    }
}