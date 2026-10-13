<?php

declare(strict_types=1);

/*
 * This file is part of Ymir Laravel Bridge.
 *
 * (c) Carl Alexander <support@ymirapp.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Ymir\Bridge\Laravel\Events\DiagnosingHealth;

$app = require __DIR__.'/bootstrap/app.php';

$diagnostics = 0;

$app->make('events')->listen(DiagnosingHealth::class, function () use (&$diagnostics): void {
    ++$diagnostics;

    if (getenv('YMIR_FIXTURE_DIAGNOSTIC_FAILS')) {
        throw new RuntimeException('secret-database-password');
    }
});

if ('fpm-fcgi' === PHP_SAPI) {
    $request = Request::capture();
} else {
    $request = Request::create($argv[1], 'GET', [], [], [], isset($argv[2]) ? ['YMIR_HEALTH_CHECK' => $argv[2]] : []);
}

$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request);

if ('fpm-fcgi' === PHP_SAPI) {
    http_response_code($response->getStatusCode());
    header('Content-Type: application/json');
}

echo json_encode([
    'configurationIsCached' => $app->configurationIsCached(),
    'content' => $response->getContent(),
    'diagnostics' => $diagnostics,
    'routesAreCached' => $app->routesAreCached(),
    'status' => $response->getStatusCode(),
    'workerPid' => getmypid(),
]);

$kernel->terminate($request, $response);
