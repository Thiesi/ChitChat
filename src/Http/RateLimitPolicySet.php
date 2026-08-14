<?php

declare(strict_types=1);
namespace ChitChat\Http;

use InvalidArgumentException;

final readonly class RateLimitPolicySet
{
    /** @var array<string, RateLimitPolicy> */
    private array $policies;

    /** @param array<string, RateLimitPolicy> $policies */
    public function __construct(array $policies)
    {
        $definitions = self::definitions(10, 900);
        foreach ($definitions as $name => $definition) {
            if (!isset($policies[$name])) {
                throw new InvalidArgumentException('Missing rate-limit policy: ' . $name);
            }
            if ($policies[$name]->name !== $name) {
                throw new InvalidArgumentException('Rate-limit policy key and name differ: ' . $name);
            }
            self::assertWithinBounds($policies[$name], $definition);
        }

        foreach ($policies as $name => $policy) {
            if (!isset($definitions[$name])) {
                throw new InvalidArgumentException('Unknown rate-limit policy: ' . $name);
            }
        }

        $this->policies = $policies;
    }

    public static function defaults(int $loginMaximumAttempts = 10, int $loginWindowSeconds = 900): self
    {
        return self::build($loginMaximumAttempts, $loginWindowSeconds, false);
    }

    public static function fromEnvironment(int $loginMaximumAttempts = 10, int $loginWindowSeconds = 900): self
    {
        return self::build($loginMaximumAttempts, $loginWindowSeconds, true);
    }

    public function get(string $name): RateLimitPolicy
    {
        $policy = $this->policies[$name] ?? null;
        if (!$policy instanceof RateLimitPolicy) {
            throw new InvalidArgumentException('Unknown rate-limit policy: ' . $name);
        }

        return $policy;
    }

    public function with(string $name, int $maximumAttempts, int $windowSeconds): self
    {
        $policies = $this->policies;
        if (!isset($policies[$name])) {
            throw new InvalidArgumentException('Unknown rate-limit policy: ' . $name);
        }
        $policies[$name] = new RateLimitPolicy($name, $maximumAttempts, $windowSeconds);

        return new self($policies);
    }

    /** @return array<string, array{name:string, maximum_attempts:int, window_seconds:int}> */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->policies as $name => $policy) {
            $result[$name] = $policy->toArray();
        }
        ksort($result);

        return $result;
    }

    private static function build(int $loginMaximumAttempts, int $loginWindowSeconds, bool $readEnvironment): self
    {
        $definitions = self::definitions($loginMaximumAttempts, $loginWindowSeconds);
        $policies = [];

        foreach ($definitions as $name => $definition) {
            $maximumAttempts = $definition['default_maximum_attempts'];
            $windowSeconds = $definition['default_window_seconds'];
            $prefix = 'RATE_LIMIT_' . strtoupper($name);

            if ($readEnvironment) {
                $maximumAttempts = self::environmentInteger(
                    $prefix . '_MAX_ATTEMPTS',
                    $maximumAttempts,
                );
                $windowSeconds = self::environmentInteger(
                    $prefix . '_WINDOW_SECONDS',
                    $windowSeconds,
                );
            }

            $policy = new RateLimitPolicy($name, $maximumAttempts, $windowSeconds);
            self::assertWithinBounds($policy, $definition);
            $policies[$name] = $policy;
        }

        return new self($policies);
    }

    /**
     * @param array{
     *   default_maximum_attempts:int,
     *   default_window_seconds:int,
     *   minimum_attempts:int,
     *   maximum_attempts:int,
     *   minimum_window_seconds:int,
     *   maximum_window_seconds:int
     * } $definition
     */
    private static function assertWithinBounds(RateLimitPolicy $policy, array $definition): void
    {
        $prefix = 'RATE_LIMIT_' . strtoupper($policy->name);
        if (
            $policy->maximumAttempts < $definition['minimum_attempts']
            || $policy->maximumAttempts > $definition['maximum_attempts']
        ) {
            throw new InvalidArgumentException(sprintf(
                '%s_MAX_ATTEMPTS must be between %d and %d.',
                $prefix,
                $definition['minimum_attempts'],
                $definition['maximum_attempts'],
            ));
        }
        if (
            $policy->windowSeconds < $definition['minimum_window_seconds']
            || $policy->windowSeconds > $definition['maximum_window_seconds']
        ) {
            throw new InvalidArgumentException(sprintf(
                '%s_WINDOW_SECONDS must be between %d and %d.',
                $prefix,
                $definition['minimum_window_seconds'],
                $definition['maximum_window_seconds'],
            ));
        }
    }

    /**
     * @return array<string, array{
     *   default_maximum_attempts:int,
     *   default_window_seconds:int,
     *   minimum_attempts:int,
     *   maximum_attempts:int,
     *   minimum_window_seconds:int,
     *   maximum_window_seconds:int
     * }>
     */
    private static function definitions(int $loginMaximumAttempts, int $loginWindowSeconds): array
    {
        return [
            'login' => self::definition($loginMaximumAttempts, $loginWindowSeconds, 1, 100, 60, 86_400),
            'registration' => self::definition(5, 3_600, 1, 100, 60, 86_400),
            'privileged_step_up' => self::definition(10, 900, 1, 100, 60, 86_400),
            'mfa_assertion' => self::definition(20, 900, 1, 100, 60, 86_400),
            'mfa_recovery' => self::definition(10, 3_600, 1, 100, 60, 86_400),
            'mfa_management' => self::definition(20, 3_600, 1, 100, 60, 86_400),
            'personal_data_export' => self::definition(5, 3_600, 1, 100, 60, 86_400),
            'account_restore' => self::definition(5, 3_600, 1, 100, 60, 86_400),
            'room_send' => self::definition(30, 60, 1, 1_000, 1, 3_600),
            'room_broadcast_mention' => self::definition(5, 3_600, 1, 100, 60, 86_400),
            'room_ping' => self::definition(30, 60, 1, 1_000, 1, 3_600),
            'room_message_mutation' => self::definition(30, 60, 1, 1_000, 1, 3_600),
            'direct_message_send' => self::definition(30, 60, 1, 1_000, 1, 3_600),
            'direct_message_mutation' => self::definition(30, 60, 1, 1_000, 1, 3_600),
            'attachment_upload' => self::definition(10, 3_600, 1, 1_000, 60, 86_400),
            'direct_message_attachment_upload' => self::definition(10, 3_600, 1, 1_000, 60, 86_400),
            'room_invite' => self::definition(60, 3_600, 1, 1_000, 60, 86_400),
            'direct_message_user_search' => self::definition(120, 60, 1, 5_000, 1, 3_600),
            'message_search' => self::definition(60, 60, 1, 5_000, 1, 3_600),
            'admin_user_search' => self::definition(120, 60, 1, 5_000, 1, 3_600),
            'room_invitable_user_search' => self::definition(120, 60, 1, 5_000, 1, 3_600),
            'admin_direct_message_user_search' => self::definition(120, 60, 1, 5_000, 1, 3_600),
            'admin_direct_message_inspection' => self::definition(60, 3_600, 1, 1_000, 60, 86_400),
            'message_revision_review' => self::definition(60, 3_600, 1, 1_000, 60, 86_400),
            'message_report' => self::definition(10, 3_600, 1, 100, 60, 86_400),
            'moderation_queue' => self::definition(120, 60, 1, 5_000, 1, 3_600),
            'moderation_action' => self::definition(60, 3_600, 1, 1_000, 60, 86_400),
        ];
    }

    /**
     * @return array{
     *   default_maximum_attempts:int,
     *   default_window_seconds:int,
     *   minimum_attempts:int,
     *   maximum_attempts:int,
     *   minimum_window_seconds:int,
     *   maximum_window_seconds:int
     * }
     */
    private static function definition(
        int $defaultMaximumAttempts,
        int $defaultWindowSeconds,
        int $minimumAttempts,
        int $maximumAttempts,
        int $minimumWindowSeconds,
        int $maximumWindowSeconds,
    ): array {
        return [
            'default_maximum_attempts' => $defaultMaximumAttempts,
            'default_window_seconds' => $defaultWindowSeconds,
            'minimum_attempts' => $minimumAttempts,
            'maximum_attempts' => $maximumAttempts,
            'minimum_window_seconds' => $minimumWindowSeconds,
            'maximum_window_seconds' => $maximumWindowSeconds,
        ];
    }

    private static function environmentInteger(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            return $default;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException($name . ' must be an integer.');
        }

        return (int) $value;
    }
}
