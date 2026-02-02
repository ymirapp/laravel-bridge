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

namespace Ymir\Bridge\Laravel\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Ymir\Bridge\Laravel\YmirServiceProvider;

class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            YmirServiceProvider::class,
        ];
    }
}
