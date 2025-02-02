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

namespace Ymir\Bridge\Laravel;

class StorageDirectories
{
    /**
     * The storage path for the execution environment.
     *
     * @var string
     */
    public const PATH = '/tmp/storage';

    /**
     * The directories that need to be created.
     *
     * @var array<string>
     */
    private const DIRECTORIES = [
        self::PATH.'/bootstrap/cache',
        self::PATH.'/framework/cache',
        self::PATH.'/framework/views',
    ];

    /**
     * Ensure the necessary storage directories exist.
     */
    public static function create(): void
    {
        collect(self::DIRECTORIES)->filter(function ($directory) {
            return !is_dir($directory);
        })->each(function ($directory) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Directory "%s" could not be created', $directory));
            }
        });
    }
}
