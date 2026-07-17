<?php

declare(strict_types=1);

namespace ChitChat\Backup;

final class CliArguments
{
    /**
     * @param list<string> $argv
     * @param array<string, 'value'|'flag'> $definitions
     * @return array<string, string|bool>
     */
    public static function parse(array $argv, array $definitions): array
    {
        $result = [];
        $count = count($argv);

        for ($index = 1; $index < $count; $index++) {
            $argument = $argv[$index];
            if (!str_starts_with($argument, '--')) {
                throw new BackupException('Unexpected positional argument: ' . $argument);
            }

            $nameAndValue = substr($argument, 2);
            if ($nameAndValue === '') {
                throw new BackupException('Empty option name.');
            }

            $equals = strpos($nameAndValue, '=');
            if ($equals === false) {
                $name = $nameAndValue;
                $inlineValue = null;
            } else {
                $name = substr($nameAndValue, 0, $equals);
                $inlineValue = substr($nameAndValue, $equals + 1);
            }

            $kind = $definitions[$name] ?? null;
            if ($kind === null) {
                throw new BackupException('Unknown option: --' . $name);
            }
            if (array_key_exists($name, $result)) {
                throw new BackupException('Option may only be supplied once: --' . $name);
            }

            if ($kind === 'flag') {
                if ($inlineValue !== null) {
                    throw new BackupException('Flag does not accept a value: --' . $name);
                }
                $result[$name] = true;
                continue;
            }

            $value = $inlineValue;
            if ($value === null) {
                $index++;
                if ($index >= $count || str_starts_with($argv[$index], '--')) {
                    throw new BackupException('Option requires a value: --' . $name);
                }
                $value = $argv[$index];
            }
            if ($value === '') {
                throw new BackupException('Option value may not be empty: --' . $name);
            }

            $result[$name] = $value;
        }

        return $result;
    }

    /** @param array<string, string|bool> $options */
    public static function string(array $options, string $name): string
    {
        $value = $options[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new BackupException('Missing required option: --' . $name);
        }

        return $value;
    }

    /** @param array<string, string|bool> $options */
    public static function flag(array $options, string $name): bool
    {
        return ($options[$name] ?? false) === true;
    }
}
