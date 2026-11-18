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
class RequestTimeoutException extends \RuntimeException
{
    /**
     * The ID of the Lambda invocation that timed out.
     */
    private string $requestId;

    /**
     * The time when the request timed out.
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
     * Create the exception from a timeout that the Ymir runtime recorded as a JSON line.
     */
    public static function fromRuntimeRecord(string $json): self
    {
        $record = json_decode($json, true);

        if (!is_array($record) || 'timeout' !== ($record['type'] ?? null)) {
            throw new \UnexpectedValueException('The Ymir runtime record isn\'t a timeout');
        }

        if (!is_string($record['message'] ?? null) || !is_string($record['method'] ?? null) || !is_string($record['path'] ?? null) || !is_string($record['requestId'] ?? null) || !is_int($record['timestamp'] ?? null)) {
            throw new \UnexpectedValueException('The Ymir runtime timeout record needs a string "message", "method", "path" and "requestId" and an integer "timestamp"');
        }

        return new self($record['message'], $record['method'], $record['path'], $record['requestId'], $record['timestamp']);
    }

    /**
     * Get the context that Laravel adds to the exception log entry.
     */
    public function context(): array
    {
        return [
            'requestId' => $this->requestId,
            'timedOutAt' => date(DATE_ATOM, $this->timestamp),
        ];
    }
}
