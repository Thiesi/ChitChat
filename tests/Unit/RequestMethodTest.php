<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Http\ApiException;
use ChitChat\Http\Request;
use PHPUnit\Framework\TestCase;

/** Request::requireMethod() and Request::isHead() (#357, #361 follow-up). */
final class RequestMethodTest extends TestCase
{
    private mixed $saved;

    protected function setUp(): void
    {
        $this->saved = $_SERVER['REQUEST_METHOD'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->saved === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->saved;
        }
    }

    public function testIsHeadOnlyForExactlyHead(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        self::assertTrue(Request::isHead());
        // Methods are case-sensitive, and PHP only drops the body for "HEAD": a "head" request
        // is not treated as one, so it never gets a body-less 200 that PHP would then fill.
        foreach (['head', 'Head', 'GET', 'POST'] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;
            self::assertFalse(Request::isHead(), $method);
        }
        unset($_SERVER['REQUEST_METHOD']);
        self::assertFalse(Request::isHead());
    }

    public function testRequireMethodAcceptsAnyListedMethodAndRefusesOthers(): void
    {
        foreach (['GET', 'HEAD', 'get'] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;
            Request::requireMethod('GET', 'HEAD');
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            Request::requireMethod('GET', 'HEAD');
            self::fail('POST should be refused.');
        } catch (ApiException $exception) {
            self::assertSame(405, $exception->status);
            self::assertSame('method_not_allowed', $exception->errorCode);
        }
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $this->expectException(ApiException::class);
        Request::requireMethod('GET');
    }
}
