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
 * Base exception for an HTTP request that the Ymir runtime handled without the application seeing the error.
 */
abstract class RuntimeErrorException extends \RuntimeException
{
    /**
     * The ID of the Lambda invocation where the error occurred.
     */
    private string $requestId;

    /**
     * The time when the error occurred.
     */
    private int $timestamp;

    /**
     * Constructor.
     */
    public function __construct(string $message, string $method, string $path, string $requestId, int $timestamp)
    {
        parent::__construct(sprintf('%s while handling "%s %s"', $message, $method, $path));

        $this->requestId = $requestId;
        $this->timestamp = $timestamp;
    }

    /**
     * Get the context that Laravel adds to the exception log entry.
     */
    public function context(): array
    {
        return [
            'requestId' => $this->requestId,
            'occurredAt' => date(DATE_ATOM, $this->timestamp),
        ];
    }
}
