<?php

declare(strict_types=1);

namespace ChitChat\Tests\Support;

/**
 * Keeps local test scratch bounded (docs/operations/local-disk-hygiene.md).
 *
 * Another project on this development host once let per-run scratch grow to
 * several million files inside its worktrees, which took the host down; moved
 * to the system temp folder unbounded, the same scratch later passed half a
 * million entries there. ChitChat's tests therefore write temporary files only
 * under one fixed folder, `<temp>/chitchat-tests`, which tests clean up after
 * themselves and the PHPUnit bootstrap removes when the run ends. The bootstrap
 * also refuses to start when this project's scratch has already grown past
 * the limit, and names the command that cleans it.
 */
final class TestScratch
{
    public const ROOT_NAME = 'chitchat-tests';
    public const DEFAULT_LIMIT = 50_000;
    public const LIMIT_VARIABLE = 'CHITCHAT_SCRATCH_LIMIT';

    /** Ignored, reproducible scratch in the checkout; every tool here regenerates it. */
    public const WORKTREE_SCRATCH = ['test-results', 'playwright-report', '.phpunit.cache', 'var/phpstan'];

    /**
     * Top-level entries in the system temp folder that belong to this project:
     * the fixed test root, the stabilization rehearsals' fixed folders, and the
     * per-run folder names earlier versions of the tests created.
     */
    public const TEMP_PATTERNS = [
        self::ROOT_NAME,
        'chitchat-*-rehearsal',
        'chitchat-attachments-*',
        'chitchat-dm-attachments-*',
        'chitchat-maintenance-*',
        'chitchat-mutations-*',
        'chitchat-observability-*',
        'chitchat-inventory-*',
        'chitchat-manifest-*',
    ];

    public static function root(): string
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . self::ROOT_NAME;
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new \RuntimeException("Could not create the test scratch folder {$root}.");
        }

        return $root;
    }

    /** A path under the fixed root that does not exist yet; unique within this run. */
    public static function path(string $label): string
    {
        return self::root() . DIRECTORY_SEPARATOR . $label . '-' . bin2hex(random_bytes(8));
    }

    public static function directory(string $label): string
    {
        $path = self::path($label);
        if (!mkdir($path, 0700, true)) {
            throw new \RuntimeException("Could not create {$path}.");
        }

        return $path;
    }

    public static function file(string $label, string $content = '', string $suffix = '.tmp'): string
    {
        $path = self::path($label) . $suffix;
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Could not write {$path}.");
        }

        return $path;
    }

    /** Removes a file or tree. A link is removed itself; its target is never touched. */
    public static function remove(string $path): bool
    {
        if (is_link($path)) {
            // A directory link on Windows needs rmdir; elsewhere unlink.
            return @unlink($path) || @rmdir($path);
        }
        if (is_file($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) {
            return !file_exists($path);
        }
        $ok = true;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $ok = self::remove($path . DIRECTORY_SEPARATOR . $entry) && $ok;
            }
        }

        return @rmdir($path) && $ok;
    }

    public static function removeRoot(): void
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . self::ROOT_NAME;
        if (file_exists($root) || is_link($root)) {
            self::remove($root);
        }
    }

    public static function limit(): int
    {
        $value = getenv(self::LIMIT_VARIABLE);

        return is_string($value) && ctype_digit($value) ? (int) $value : self::DEFAULT_LIMIT;
    }

    /** Files under $root without following links, counting at most $stopAfter + 1 so a huge tree stays cheap. */
    public static function countFiles(string $root, int $stopAfter = PHP_INT_MAX): int
    {
        if (is_link($root) || is_file($root)) {
            return 1;
        }
        $count = 0;
        $pending = [$root];
        while ($pending !== []) {
            $directory = array_pop($pending);
            $handle = @opendir($directory);
            if ($handle === false) {
                continue;
            }
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($path) && !is_link($path)) {
                    $pending[] = $path;
                    continue;
                }
                if (++$count > $stopAfter) {
                    closedir($handle);

                    return $count;
                }
            }
            closedir($handle);
        }

        return $count;
    }

    /** @return list<string> */
    public static function tempEntries(?string $temp = null): array
    {
        $temp = rtrim($temp ?? sys_get_temp_dir(), '/\\');
        $found = [];
        foreach (self::TEMP_PATTERNS as $pattern) {
            foreach (glob($temp . DIRECTORY_SEPARATOR . $pattern, GLOB_NOSORT) ?: [] as $path) {
                $found[$path] = true;
            }
        }
        $paths = array_keys($found);
        sort($paths);

        return $paths;
    }

    public static function tempFileCount(?string $temp = null, int $stopAfter = PHP_INT_MAX): int
    {
        $total = 0;
        foreach (self::tempEntries($temp) as $path) {
            $total += self::countFiles($path, $stopAfter - $total);
            if ($total > $stopAfter) {
                break;
            }
        }

        return $total;
    }

    public static function worktreeFileCount(string $repositoryRoot, int $stopAfter = PHP_INT_MAX): int
    {
        $total = 0;
        foreach (self::WORKTREE_SCRATCH as $name) {
            $path = $repositoryRoot . DIRECTORY_SEPARATOR . $name;
            if (file_exists($path)) {
                $total += self::countFiles($path, $stopAfter - $total);
                if ($total > $stopAfter) {
                    break;
                }
            }
        }

        return $total;
    }

    public static function worktreeProblem(string $repositoryRoot, ?int $limit = null): ?string
    {
        $limit ??= self::limit();
        if (self::worktreeFileCount($repositoryRoot, $limit) <= $limit) {
            return null;
        }

        return sprintf(
            'This checkout holds more than %s scratch files in %s. Run `composer clean-scratch` '
            . '(keep any evidence you need on the PR or in docs/ first). %s raises the limit for one run.',
            number_format($limit),
            implode(', ', self::WORKTREE_SCRATCH),
            self::LIMIT_VARIABLE,
        );
    }

    public static function tempProblem(?string $temp = null, ?int $limit = null): ?string
    {
        $limit ??= self::limit();
        if (self::tempFileCount($temp, $limit) <= $limit) {
            return null;
        }

        return sprintf(
            'ChitChat holds more than %s files in the system temp folder (%s). The temp folder is not storage, '
            . 'and past half a million entries it has taken this host down. Run `composer clean-scratch -- --temp`, '
            . 'which keeps entries written in the last 24 hours; once no run is active, add `--older-than 0`. '
            . '%s raises the limit for one run.',
            number_format($limit),
            implode(', ', self::TEMP_PATTERNS),
            self::LIMIT_VARIABLE,
        );
    }
}
