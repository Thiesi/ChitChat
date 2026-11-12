<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\View\Lockup;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** The signed-out pages' lockup (S3, #169; #227): the installation's icon, its name as the heading. */
final class LockupTest extends TestCase
{
    public function testTheDefaultInstallationShowsTheMarkAndItsNameWithoutRepeatingIt(): void
    {
        $html = Lockup::render('ChitChat', '/assets/app-icons/icon-192.png', 'auth-title');
        self::assertStringContainsString('<h1 id="auth-title" class="lockup-name">ChitChat</h1>', $html);
        self::assertStringContainsString('<img src="/assets/app-icons/icon-192.png" alt=""', $html);
        self::assertStringNotContainsString('on ChitChat', $html);
        self::assertStringNotContainsString('lockup-horizontal', $html);
    }

    public function testARenamedInstallationSaysWhatItRunsOn(): void
    {
        $html = Lockup::render('TalkSquare', '/installation-icon.php?v=3&size=mark', 'auth-title');
        self::assertStringContainsString('>TalkSquare</h1><p class="lockup-sub">on ChitChat</p>', $html);
        // A #226 preset or an upload: whatever icon the installation has, shown as-is.
        self::assertStringContainsString('src="/installation-icon.php?v=3&amp;size=mark"', $html);
    }

    public function testOnlyChitChatItselfLeavesOutTheLine(): void
    {
        self::assertFalse(Lockup::showsPlatform('chitchat'));
        self::assertFalse(Lockup::showsPlatform(' CHITCHAT '));
        self::assertTrue(Lockup::showsPlatform('ChitChat Riverside'));
        self::assertTrue(Lockup::showsPlatform('Ωmega'));
    }

    public function testNamesAndSourcesAreEscapedAndTheHorizontalFormIsMarked(): void
    {
        $html = Lockup::render('<b>"Riverside" & co</b>', '/x?a="b"', 'restore-brand', horizontal: true);
        self::assertStringContainsString('&lt;b&gt;&quot;Riverside&quot; &amp; co&lt;/b&gt;</h1>', $html);
        self::assertStringContainsString('src="/x?a=&quot;b&quot;"', $html);
        self::assertStringContainsString('class="lockup lockup-horizontal"', $html);
    }

    public function testTheHeadingIdIsChecked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Lockup::render('ChitChat', '/icon.png', 'bad" onload="x');
    }
}
