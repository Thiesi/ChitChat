<?php

declare(strict_types=1);

// Test-only DB/certificate fixture for real cross-site browser proof. Never served by public/.
require dirname(__DIR__, 3) . '/vendor/autoload.php';
$input = json_decode((string) stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$config = \ChitChat\Config::fromEnvironment();
if ($config->environment !== 'test' || !in_array($config->databaseName, ['chitchat_test', 'chitchat_browser', 'chitchat_webkit'], true)) {
    throw new RuntimeException('Native browser fixture requires an explicit test database.');
}
$pdo = \ChitChat\Database::connect($config);
if (($input['action'] ?? '') === 'cleanup') {
    foreach ($input['transactions'] ?? [] as $id) {
        $statement = $pdo->prepare('DELETE FROM native_auth_transactions WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
    $statement = $pdo->prepare('DELETE FROM audit_log WHERE actor_user_id = :id');
    $statement->execute(['id' => $input['user_id']]);
    $statement = $pdo->prepare('DELETE FROM users WHERE id = :id AND username = :username');
    $statement->execute(['id' => $input['user_id'], 'username' => $input['username']]);
    echo '{}';
    exit;
}
$version = (int) $pdo->query("SELECT version FROM policy_documents WHERE document = 'rules'")->fetchColumn();
$user = (new \ChitChat\Auth\AuthService($pdo, $config))->register($input['username'], 'a very secure password', '192.0.2.123', '1980-01-01', $version);
$statement = $pdo->prepare("INSERT INTO user_identities(user_id,provider,subject) VALUES (:id,'google',:subject)");
$statement->execute(['id' => $user->id, 'subject' => $input['username']]);
$csrConfig = dirname($input['path']) . '/csr.cnf';
file_put_contents($csrConfig, "[req]\ndistinguished_name=req_dn\n[req_dn]\n[v3_req]\nsubjectAltName=DNS:localhost,IP:127.0.0.1\n");
try {
    $options = ['config' => $csrConfig, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $key = openssl_pkey_new($options);
    if (!$key instanceof OpenSSLAsymmetricKey) throw new RuntimeException('Cannot create fixture key.');
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key, $options + ['req_extensions' => 'v3_req']);
    if (!$csr instanceof OpenSSLCertificateSigningRequest) throw new RuntimeException('Cannot create fixture CSR.');
    $certificate = openssl_csr_sign($csr, null, $key, 1, $options + ['x509_extensions' => 'v3_req']);
    if (!$certificate instanceof OpenSSLCertificate || !openssl_x509_export($certificate, $cert) || !openssl_pkey_export($key, $pem, null, $options)) throw new RuntimeException('Cannot export fixture certificate.');
    file_put_contents($input['path'], json_encode(['issuer' => $input['issuer'], 'pem' => $pem, 'tls_cert' => $cert,
        'subject' => $input['username'], 'kid' => 'native-browser-' . $input['username'],
        'browser_return' => $input['origin'] . '/api/v1/oidc/callback.php'], JSON_THROW_ON_ERROR));
    echo json_encode(['user_id' => $user->id, 'username' => $user->username], JSON_THROW_ON_ERROR);
} finally {
    unlink($csrConfig);
}