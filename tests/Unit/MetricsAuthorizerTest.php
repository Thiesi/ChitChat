<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Observability\MetricsAuthorizer;
use PHPUnit\Framework\TestCase;

final class MetricsAuthorizerTest extends TestCase
{
    public function testDisabledAndMalformedAuthorizationAreRejected(): void
    {
        self::assertFalse(MetricsAuthorizer::accepts('', 'Bearer anything'));
        self::assertFalse(MetricsAuthorizer::accepts('correct-token-with-enough-entropy', ''));
        self::assertFalse(MetricsAuthorizer::accepts('correct-token-with-enough-entropy', 'Basic abc'));
        self::assertFalse(MetricsAuthorizer::accepts('correct-token-with-enough-entropy', 'Bearer wrong'));
    }

    public function testExactBearerTokenIsAccepted(): void
    {
        self::assertTrue(MetricsAuthorizer::accepts(
            'correct-token-with-enough-entropy',
            '  Bearer correct-token-with-enough-entropy  ',
        ));
    }
}
