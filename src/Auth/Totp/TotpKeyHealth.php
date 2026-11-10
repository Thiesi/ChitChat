<?php

declare(strict_types=1);

namespace ChitChat\Auth\Totp;

use ChitChat\Config;
use ChitChat\Http\ApiException;
use PDO;
use RuntimeException;

/** Protected status only. A bounded scan explicitly reports when it is incomplete. */
final readonly class TotpKeyHealth
{
    public function __construct(private PDO $pdo, private Config $config) {}

    /** @return array{mode:string,available:bool,checked_credentials:int,unavailable_credentials:int,scan_complete:bool,error_code:?string} */
    public function status(): array
    {
        $keys = new TotpKeyStore($this->pdo, $this->config);
        $statement = $this->pdo->query('SELECT DISTINCT user_id FROM totp_credentials ORDER BY user_id LIMIT 101');
        if ($statement === false) throw new RuntimeException('Unable to inspect authenticator key health.');
        $users = $statement->fetchAll(PDO::FETCH_COLUMN);
        $complete = count($users) <= 100;
        $checked = 0;
        $unavailable = 0;
        $repository = new TotpRepository($this->pdo);
        foreach (array_slice($users, 0, 100) as $user) {
            foreach (['active', 'pending'] as $phase) {
                $credential = $repository->credential((int) $user, $phase);
                if ($credential === null) continue;
                ++$checked;
                try { $credential->decrypt($keys); }
                catch (ApiException) { ++$unavailable; }
            }
        }
        return ['mode' => $keys->mode(), 'available' => $keys->available(), 'checked_credentials' => $checked,
            'unavailable_credentials' => $unavailable, 'scan_complete' => $complete,
            'error_code' => $this->config->totpKeyRing->valid() ? null : 'invalid_server_key_ring'];
    }
}