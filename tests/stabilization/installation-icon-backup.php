<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use ChitChat\Auth\AuthService;
use ChitChat\Config;
use ChitChat\Database;
use ChitChat\InstallationIcon\IconRepository;
use ChitChat\InstallationIcon\IconService;
use ChitChat\Upload\IncomingFile;

$config = Config::fromEnvironment();
if ($config->environment !== 'test' || !in_array($config->databaseName, ['chitchat_backup', 'chitchat_backup_restore'], true)) {
    throw new RuntimeException('Icon backup fixture requires the rehearsal database.');
}
$pdo = Database::connect($config);
$expectedPath = $argv[2] ?? throw new RuntimeException('Expected metadata path is required.');
$repository = new IconRepository($pdo);
if (($argv[1] ?? '') === 'seed') {
    $actor = (new AuthService($pdo,$config))->register('BackupFixture','a very secure password','127.0.0.1');
    $service = new IconService($pdo,$config);
    $service->update($actor,IncomingFile::forTesting('fixture.png',
        dirname(__DIR__,2).'/public/assets/app-icons/icon-512.png'),'#14111C','127.0.0.1');
    $service->update($actor,null,'#FBF7F1','127.0.0.1');
    $expected = ['current'=>$repository->currentRevision(),'generations'=>[]];
    foreach ($pdo->query('SELECT revision FROM installation_icons ORDER BY revision')->fetchAll(PDO::FETCH_COLUMN) as $revision) {
        $generation = $repository->find($revision)['generation'];
        $expected['generations'][$revision] = [];
        foreach ($generation->bytes as $variant=>$bytes) {
            $expected['generations'][$revision][$variant] = hash('sha256',$bytes);
        }
    }
    file_put_contents($expectedPath,json_encode($expected,JSON_THROW_ON_ERROR));
} elseif (($argv[1] ?? '') === 'verify') {
    $expected = json_decode((string)file_get_contents($expectedPath),true,32,JSON_THROW_ON_ERROR);
    if ($repository->currentRevision() !== $expected['current']
        || (int)$pdo->query('SELECT count(*) FROM installation_icons')->fetchColumn() !== 2) {
        throw new RuntimeException('Restored icon pointer/generation retention differs.');
    }
    foreach ($expected['generations'] as $revision=>$hashes) {
        $row = $repository->find($revision);
        if ($row === null) throw new RuntimeException('Restored icon generation missing.');
        foreach ($hashes as $variant=>$sha) {
            if (!hash_equals($sha,hash('sha256',$row['generation']->bytes[$variant]))) {
                throw new RuntimeException('Restored icon bytes differ.');
            }
        }
    }
    echo "Installation icon pointer and all rendition bytes survived backup/restore.\n";
} else {
    throw new RuntimeException('Use seed or verify.');
}
