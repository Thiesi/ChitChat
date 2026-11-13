<?php

declare(strict_types=1);
namespace ChitChat\Tests\Unit;

use ChitChat\Auth\PasswordHasher;
use ChitChat\Auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The production hashing path stays PHP's default (#163); only the test bootstrap lowers the cost.
 */
final class PasswordHasherTest extends TestCase
{
    public function testTheProductionPathHashesAtPhpsDefaultCostAndVerifiesStoredHashes(): void
    {
        // A fresh PHP process that loads only the autoloader, never tests/bootstrap.php: the hook is unset.
        $script = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
$hash = ChitChat\Auth\PasswordHasher::hash('a very secure password');
echo json_encode([
    'hash' => $hash,
    'info' => password_get_info($hash),
    'default' => password_get_info(password_hash('x', PASSWORD_DEFAULT)),
    'verifies' => password_verify('a very secure password', $hash),
    'needs_rehash_own' => ChitChat\Auth\PasswordHasher::needsRehash($hash),
    'needs_rehash_cost4' => ChitChat\Auth\PasswordHasher::needsRehash(password_hash('x', PASSWORD_BCRYPT, ['cost' => 4])),
    'needs_rehash_stored' => ChitChat\Auth\PasswordHasher::needsRehash($argv[2]),
]);
PHP;
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, '-r', $script, $root, PasswordPolicy::DUMMY_PASSWORD_HASH],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);

        /** @var array{hash: string, info: array{algo: string, options: array{cost: int}}, default: array{algo: string, options: array{cost: int}}, verifies: bool, needs_rehash_own: bool, needs_rehash_cost4: bool, needs_rehash_stored: bool} $result */
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($result['default'], $result['info'], 'Same algorithm and options as a plain password_hash(…, PASSWORD_DEFAULT).');
        self::assertGreaterThanOrEqual(10, $result['info']['options']['cost']);
        self::assertTrue($result['verifies']);
        self::assertFalse($result['needs_rehash_own']);
        // A cheap hash would be upgraded at the next sign-in, if one ever reached production.
        self::assertTrue($result['needs_rehash_cost4']);
        // The stored hash format already in production databases (the cost-12 dummy) stays valid.
        self::assertSame(password_needs_rehash(PasswordPolicy::DUMMY_PASSWORD_HASH, PASSWORD_DEFAULT), $result['needs_rehash_stored']);
    }

    public function testExistingStoredHashesVerifyWhateverTheCost(): void
    {
        $stored = password_hash('a very secure password', PASSWORD_DEFAULT);
        self::assertTrue(password_verify('a very secure password', $stored));
        self::assertTrue(password_verify('a very secure password', PasswordHasher::hash('a very secure password')));
        self::assertFalse(password_verify('another password', PasswordHasher::hash('a very secure password')));
    }

    public function testThisTestProcessUsesTheMinimumCost(): void
    {
        self::assertSame(4, password_get_info(PasswordHasher::hash('a very secure password'))['options']['cost'] ?? null);
    }

    public function testOnlyFilesUnderTestsCallTheTestOnlyHook(): void
    {
        $root = dirname(__DIR__, 2);
        $callers = [];
        foreach (['bin', 'bootstrap', 'public', 'src', 'migrations', 'scripts'] as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if ($relative === 'src/Auth/PasswordHasher.php') {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getPathname()), 'useMinimumCostInTestsOnly')) {
                    $callers[] = $relative;
                }
            }
        }
        foreach (glob($root . '/*.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), 'useMinimumCostInTestsOnly')) {
                $callers[] = basename($file);
            }
        }
        self::assertSame([], $callers);
        self::assertStringContainsString('useMinimumCostInTestsOnly', (string) file_get_contents($root . '/tests/bootstrap.php'));
    }
}
