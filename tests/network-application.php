<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Tests\DatabaseSafety;

$root = dirname(__DIR__);
$run = getenv('ANINF_VALIDATION_RUN_DIR');
$id = getenv('ANINF_VALIDATION_RUN_ID');
$port = (int) getenv('ANINF_VALIDATION_MYSQL_PORT');
if (basename($root) !== 'aninf-api-test' || ! is_string($run) || ! is_string($id) || $port < 1024 || $port === 3306) {
    throw new RuntimeException('Environnement reseau isole manquant.');
}
$real = realpath($run);
$base = realpath($root.'/storage/framework/testing');
if ($real === false || $base === false || ! str_starts_with($real, $base.DIRECTORY_SEPARATOR.'network-') || ! hash_equals($id, trim(file_get_contents($real.'/run-id')))) {
    throw new RuntimeException('Repertoire de validation non autorise.');
}
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($real.'/storage');
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app) use ($port): void {
    $app->make('config')->set([
        'app.env' => 'testing', 'app.debug' => false,
        'database.default' => 'mysql', 'database.connections.mysql.host' => '127.0.0.1',
        'database.connections.mysql.port' => $port, 'database.connections.mysql.database' => 'aninf_test',
        'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => getenv('DB_PASSWORD'),
        'database.connections.mysql.url' => null, 'database.connections.mysql.options' => [],
        'logging.default' => 'single', 'logging.channels.single.path' => $app->storagePath('logs/laravel.log'),
        'logging.channels.single.level' => 'debug', 'cache.default' => 'database', 'session.driver' => 'array',
        'queue.default' => 'sync', 'mail.default' => 'array', 'hashing.bcrypt.rounds' => 4,
    ]);
});
DatabaseSafety::install($app, []);

return $app;
