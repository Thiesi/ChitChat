<?php

declare(strict_types=1);

namespace ChitChat\Tests\Support;

use ChitChat\Auth\Base64Url;
use ChitChat\Auth\MfaRepository;
use ChitChat\Auth\Totp\TotpCipher;
use ChitChat\Auth\Totp\TotpKeyStore;
use ChitChat\Config;
use PDO;
use Throwable;

/** Encrypted fixture only; real enrollment is covered by TotpServiceTest/HTTP. */
final class TestTotp
{
    public static function seed(PDO $pdo, Config $config, int $userId): string
    {
        $seed = random_bytes(20);
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR NO KEY UPDATE');
            $lock->execute(['id' => $userId]);
            $key = (new TotpKeyStore($pdo, $config))->writeKey();
            $id = Base64Url::encode(random_bytes(32));
            $sealed = TotpCipher::encrypt($seed, $key['key'], $userId, $id, $key['id']);
            $insert = $pdo->prepare(<<<'SQL'
INSERT INTO totp_credentials(id, user_id, phase, key_id, ciphertext, iv, tag, activated_at)
VALUES (:id, :user, 'active', :key, decode(:ciphertext,'hex'), decode(:iv,'hex'), decode(:tag,'hex'), clock_timestamp())
SQL);
            $insert->execute(['id' => $id, 'user' => $userId, 'key' => $key['id'],
                'ciphertext' => bin2hex($sealed['ciphertext']), 'iv' => bin2hex($sealed['iv']), 'tag' => bin2hex($sealed['tag'])]);
            (new MfaRepository($pdo))->markEnabled($userId);
            $pdo->commit();
            return $seed;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}