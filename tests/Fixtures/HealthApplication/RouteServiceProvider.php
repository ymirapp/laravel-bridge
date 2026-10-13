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

namespace Ymir\Bridge\Laravel\Tests\Fixtures\HealthApplication;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as BaseRouteServiceProvider;
use Illuminate\Routing\Router;

class RouteServiceProvider extends BaseRouteServiceProvider
{
    public function map(Router $router): void
    {
        $router->redirect('/', '/home');
        $router->group(['domain' => 'admin.example.com'], function () use ($router): void {
            $router->redirect('ymir/health', '/private');
        });
    }
}
