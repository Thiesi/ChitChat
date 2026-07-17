<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Backup\AttachmentInventory;
use ChitChat\Backup\BackupException;
use PHPUnit\Framework\TestCase;

final class AttachmentInventoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/chitchat-inventory-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/nested', 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_link($this->root . '/link')) {
            unlink($this->root . '/link');
        }
        @unlink($this->root . '/nested/second.bin');
        @unlink($this->root . '/first.txt');
        @rmdir($this->root . '/nested');
        @rmdir($this->root);
    }

    public function testCountsRegularAttachmentTree(): void
    {
        file_put_contents($this->root . '/first.txt', 'hello');
        file_put_contents($this->root . '/nested/second.bin', '1234567');

        $inventory = AttachmentInventory::scan($this->root);

        self::assertSame(2, $inventory->fileCount);
        self::assertSame(1, $inventory->directoryCount);
        self::assertSame(12, $inventory->totalBytes);
        self::assertTrue($inventory->equals(new AttachmentInventory(2, 1, 12)));
    }

    public function testRejectsSymbolicLinks(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('Symbolic links are unavailable.');
        }
        symlink($this->root . '/nested', $this->root . '/link');

        $this->expectException(BackupException::class);
        AttachmentInventory::scan($this->root);
    }
}
