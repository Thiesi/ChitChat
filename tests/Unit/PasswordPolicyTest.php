<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\PasswordPolicy;
use ChitChat\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testLongPasswordIsAccepted(): void
    {
        PasswordPolicy::validate('correct horse battery staple', 'Alice');
        self::addToAssertionCount(1);
    }

    public function testShortPasswordIsRejected(): void
    {
        $this->expectException(ApiException::class);
        PasswordPolicy::validate('too short', 'Alice');
    }

    public function testPasswordContainingUsernameIsRejected(): void
    {
        $this->expectException(ApiException::class);
        PasswordPolicy::validate('Alice has a secure password', 'Alice');
    }
}
