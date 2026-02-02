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

namespace Ymir\Bridge\Laravel\Tests\Integration;

use Symfony\Component\Filesystem\Filesystem;
use Ymir\Bridge\Laravel\StorageDirectories;
use Ymir\Bridge\Laravel\Tests\TestCase;

class StorageDirectoriesTest extends TestCase
{
    public function testCreateCreatesDirectories(): void
    {
        (new Filesystem())->remove(StorageDirectories::PATH);

        $this->assertDirectoryDoesNotExist(StorageDirectories::PATH.'/bootstrap/cache');
        $this->assertDirectoryDoesNotExist(StorageDirectories::PATH.'/framework/cache');
        $this->assertDirectoryDoesNotExist(StorageDirectories::PATH.'/framework/views');

        StorageDirectories::create();

        $this->assertDirectoryExists(StorageDirectories::PATH.'/bootstrap/cache');
        $this->assertDirectoryExists(StorageDirectories::PATH.'/framework/cache');
        $this->assertDirectoryExists(StorageDirectories::PATH.'/framework/views');

        (new Filesystem())->remove(StorageDirectories::PATH);
    }
}
