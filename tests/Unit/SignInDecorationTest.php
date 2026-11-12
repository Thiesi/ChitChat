<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Admin\SignInDecorationService;
use ChitChat\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class SignInDecorationTest extends TestCase
{
    public function testOnlyTheExactOptionsAreAcceptedInSelectOrder(): void
    {
        self::assertSame(['off', 'winter', 'spring', 'celebration'], SignInDecorationService::OPTIONS);
        foreach (SignInDecorationService::OPTIONS as $option) {
            self::assertSame($option, SignInDecorationService::validate($option));
        }
        foreach (['Winter', ' winter', 'autumn', '', null, 1, true, ['winter']] as $invalid) {
            try {
                SignInDecorationService::validate($invalid);
                self::fail('Accepted ' . var_export($invalid, true));
            } catch (ApiException $exception) {
                self::assertSame([400, 'validation_error'], [$exception->status, $exception->errorCode]);
            }
        }
    }

    public function testOffIsNullInThePublicForm(): void
    {
        self::assertNull(SignInDecorationService::toPublic('off'));
        self::assertSame('spring', SignInDecorationService::toPublic('spring'));
    }
}
