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

namespace Ymir\Bridge\Laravel\Exception;

/**
 * Exception for an HTTP request that PHP-FPM failed to complete because it crashed.
 */
class PhpFpmCrashException extends RuntimeErrorException
{
}
