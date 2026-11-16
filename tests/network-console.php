<?php

use Symfony\Component\Console\Input\ArgvInput;

require dirname(__DIR__).'/vendor/autoload.php';
if (($argv[1] ?? '') !== 'migrate' || count(array_diff(array_slice($argv, 2), ['--force', '--no-interaction'])) > 0) {
    throw new RuntimeException('Seule la migration additive de la base privee est autorisee.');
}
define('LARAVEL_START', microtime(true));
$app = require __DIR__.'/network-application.php';
exit($app->handleCommand(new ArgvInput));
