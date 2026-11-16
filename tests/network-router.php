<?php

use Illuminate\Http\Request;

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
define('LARAVEL_START', microtime(true));
require dirname(__DIR__).'/vendor/autoload.php';
header('X-Validation-Worker: '.getmypid());
$app = require __DIR__.'/network-application.php';
$app->handleRequest(Request::capture());
