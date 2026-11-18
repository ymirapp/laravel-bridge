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
 * Exception for an HTTP request that the Ymir runtime stopped because it ran out of time.
 */
class RequestTimeoutException extends RuntimeErrorException
{
}
