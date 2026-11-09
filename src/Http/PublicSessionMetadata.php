<?php

declare(strict_types=1);

namespace ChitChat\Http;

use ChitChat\Admin\ApplicationNameService;
use ChitChat\Config;
use PDO;

/** Installation metadata that is safe to disclose before authentication. */
final readonly class PublicSessionMetadata
{
    public function __construct(private PDO $pdo, private Config $config)
    {
    }

    /** @return array{name:string, version:string,icon:array{url:string,width:int,height:int,mime_type:string,sha256:string}|null} */
    public function application(): array
    {
        return [
            'name' => (new ApplicationNameService($this->pdo, $this->config))->effective(),
            'version' => $this->config->applicationVersion,
            'icon' => (new \ChitChat\InstallationIcon\IconService($this->pdo, $this->config))->publicIcon(),
        ];
    }

    /** @return array{schema_version:int, native_passkey_origins:bool, native_auth_handoff:bool} */
    public function capabilities(): array
    {
        return [
            'schema_version' => 1,
            // Native passkeys require explicit installation trust; handoff is separate.
            'native_passkey_origins' => $this->config->webauthnEnabled() && $this->config->nativeAndroidApps !== [],
            'native_auth_handoff' => \ChitChat\Auth\NativeAuth\NativeAuthConfiguration::available($this->config),
        ];
    }

    /** @return array{admin_review_enabled:bool, admin_review_role:string, reason_required:bool, audit_each_review:bool, participant_notification:bool} */
    public function messageRevisionPolicy(): array
    {
        return [
            'admin_review_enabled' => $this->config->messageRevisionReviewEnabled,
            'admin_review_role' => $this->config->messageRevisionReviewRole,
            'reason_required' => true,
            'audit_each_review' => true,
            // The successful-access audit trigger notifies the room author
            // or the DM participants, excluding accounts already closed.
            'participant_notification' => true,
        ];
    }
}
