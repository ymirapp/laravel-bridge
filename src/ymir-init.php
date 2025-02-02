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

use Ymir\Bridge\Laravel\StorageDirectories;

/*
 * This file is loaded by the Composer autoloader and will only execute in a Ymir environment. This allows us to
 * run code that needs to be executed before the Laravel application boots.
 */

if (!getenv('LAMBDA_TASK_ROOT') || !getenv('YMIR_ENVIRONMENT')) {
    return;
}

StorageDirectories::create();
