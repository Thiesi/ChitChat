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
            'icon' => null,
        ], $metadata->application());
        $root = (new AuthService($this->pdo, $config))->register('Root', 'a very secure password', '127.0.0.1');
        $names = new ApplicationNameService($this->pdo, $config);
        $names->update($root, '  Harbor Chat  ', '127.0.0.1');
        // Reading the same metadata needs no account or administrative role.
        self::assertSame(['name' => 'Harbor Chat', 'version' => $config->applicationVersion, 'icon' => null], $metadata->application());
        $names->update($root, null, '127.0.0.1');
        self::assertSame($config->applicationName, $metadata->application()['name']);
    }

    public function testCapabilityDiscoveryContainsOnlyTheShippedPublicContract(): void
    {
        $metadata = new PublicSessionMetadata($this->pdo, $this->config);
        self::assertSame([
            'schema_version' => 1,
            'signed_in_sessions' => true,
            'native_passkey_origins' => false,
            'native_auth_handoff' => false,
        ], $metadata->capabilities());
        $policy = $metadata->messageRevisionPolicy();
        self::assertSame($this->config->messageRevisionReviewEnabled, $policy['admin_review_enabled']);
        self::assertSame($this->config->messageRevisionReviewRole, $policy['admin_review_role']);
        self::assertTrue($policy['participant_notification']);
    }

    public function testNativePasskeysAdvertiseOnlyConfiguredTrustAndDoNotEnableHandoff(): void
    {
        $apps = [['package' => 'de.networkxxiii.chitchat', 'sha256_cert_fingerprint' => implode(':', array_fill(0, 32, 'AB'))]];
        foreach ([
            [[], '', '', false],
            [$apps, '', '', false],
            [[], 'chat.test', 'https://chat.test', false],
            [$apps, 'chat.test', 'https://chat.test', true],
        ] as [$trust, $rp, $origin, $expected]) {
            $config = $this->configWith(['nativeAndroidApps' => $trust, 'webauthnRpId' => $rp, 'webauthnOrigin' => $origin]);
            self::assertSame(['schema_version' => 1,
            'signed_in_sessions' => true, 'native_passkey_origins' => $expected, 'native_auth_handoff' => false],
                (new PublicSessionMetadata($this->pdo, $config))->capabilities());
        }
    }
    public function testNativeHandoffDiscoveryRequiresAllInstallationInputsAndTheIntegratedRenderer(): void
    {
        self::assertTrue(\ChitChat\View\NativeAuthPage::integrated());
        $values = ['applicationOrigin' => 'https://chat.test', 'oidcRedirectOrigin' => 'https://chat.test',
            'nativeAndroidApps' => [['package' => 'de.networkxxiii.chitchat', 'sha256_cert_fingerprint' => implode(':', array_fill(0, 32, 'AB'))]],
            'oidcProviders' => ['google' => ['client_id' => 'client', 'client_secret' => 'secret', 'endpoint_override' => '']]];
        self::assertTrue((new PublicSessionMetadata($this->pdo, $this->configWith($values)))->capabilities()['native_auth_handoff']);
        foreach ([['applicationOrigin' => ''], ['applicationOrigin' => 'http://127.0.0.1'], ['oidcRedirectOrigin' => 'https://other.test'],
            ['nativeAndroidApps' => []], ['oidcProviders' => []]] as $invalid) {
            self::assertFalse((new PublicSessionMetadata($this->pdo, $this->configWith(array_replace($values, $invalid))))->capabilities()['native_auth_handoff']);
        }
    }
}
