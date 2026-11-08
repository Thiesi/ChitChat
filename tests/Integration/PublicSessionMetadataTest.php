<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\ApplicationNameService;
use ChitChat\Auth\AuthService;
use ChitChat\Http\PublicSessionMetadata;

final class PublicSessionMetadataTest extends DatabaseTestCase
{
    public function testPublicBrandingUsesTheEffectiveOverrideAndConfiguredVersionWithoutAnActor(): void
    {
        $config = $this->configWith(['applicationVersion' => '3.3.0-rc.1+fixture']);
        $metadata = new PublicSessionMetadata($this->pdo, $config);
        self::assertSame([
            'name' => $config->applicationName,
            'version' => '3.3.0-rc.1+fixture',
        ], $metadata->application());
        $root = (new AuthService($this->pdo, $config))->register('Root', 'a very secure password', '127.0.0.1');
        $names = new ApplicationNameService($this->pdo, $config);
        $names->update($root, '  Harbor Chat  ', '127.0.0.1');
        // Reading the same metadata needs no account or administrative role.
        self::assertSame(['name' => 'Harbor Chat', 'version' => $config->applicationVersion], $metadata->application());
        $names->update($root, null, '127.0.0.1');
        self::assertSame($config->applicationName, $metadata->application()['name']);
    }

    public function testCapabilityDiscoveryContainsOnlyTheShippedPublicContract(): void
    {
        $metadata = new PublicSessionMetadata($this->pdo, $this->config);
        self::assertSame([
            'schema_version' => 1,
            'native_passkey_origins' => false,
            'native_auth_handoff' => false,
        ], $metadata->capabilities());
        $policy = $metadata->messageRevisionPolicy();
        self::assertSame($this->config->messageRevisionReviewEnabled, $policy['admin_review_enabled']);
        self::assertSame($this->config->messageRevisionReviewRole, $policy['admin_review_role']);
        self::assertTrue($policy['participant_notification']);
    }
}
