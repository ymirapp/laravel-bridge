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

use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;

require_once __DIR__.'/../../../../vendor/autoload.php';

if ('modern' === getenv('YMIR_FIXTURE_BOOTSTRAP')) {
    $app = call_user_func_array([Application::configure(dirname(__DIR__)), 'withRouting'], ['health' => '/up'])
        ->withMiddleware()
        ->withExceptions()
        ->create();
} else {
    $app = new Application(dirname(__DIR__));

    $app->singleton(HttpKernelContract::class, fn (Application $app): HttpKernel => new class($app, $app['router']) extends HttpKernel {
        protected $middleware = [
            PreventRequestsDuringMaintenance::class,
        ];
    });
    $app->singleton(ConsoleKernelContract::class, ConsoleKernel::class);
    $app->singleton(ExceptionHandler::class, Handler::class);
}

$app->useStoragePath((string) getenv('YMIR_FIXTURE_STORAGE_PATH'));

return $app;
