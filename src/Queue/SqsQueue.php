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

namespace Ymir\Bridge\Laravel\Queue;

use Aws\Sqs\SqsClient;
use Illuminate\Cache\DynamoDbStore;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Queue\SqsQueue as LaravelSqsQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * SQS queue that resolves queue URLs from the Ymir environment.
 */
class SqsQueue extends LaravelSqsQueue
{
    /**
     * The cache key prefix for overflow payload pointers.
     *
     * Matches Laravel's "EXTENDED_PAYLOAD_CACHE_PREFIX" constant, which older supported Laravel versions don't define.
     */
    private const OVERFLOW_POINTER_PREFIX = 'laravel:sqs-payloads:';

    /**
     * The overflow storage options for large payload offloading.
     *
     * @var array
     */
    protected $overflowStorage = [];

    /**
     * The queue name suffix.
     *
     * @var string
     */
    protected $suffix;

    /**
     * Constructor.
     */
    public function __construct(SqsClient $sqs, $default, $prefix = '', $suffix = '', $dispatchAfterCommit = false, array $overflowStorage = [])
    {
        parent::__construct($sqs, $default, $prefix, $suffix, $dispatchAfterCommit);

        $this->suffix = $suffix;
        $this->overflowStorage = $overflowStorage;
    }

    /**
     * {@inheritdoc}
     */
    public function getQueue($queue): string
    {
        $queue = $queue ?: $this->default;

        if (false !== filter_var($queue, FILTER_VALIDATE_URL)) {
            return $queue;
        }

        $queueUrl = getenv(sprintf('YMIR_QUEUE_%s', Str::of($queue)->upper()->replace('-', '_'))) ?: $this->suffixQueue($queue, $this->suffix);

        if (!is_string($queueUrl) || false === filter_var($queueUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(sprintf('Queue [%s] is not configured in ymir.yml.', $queue));
        }

        return $queueUrl;
    }

    /**
     * {@inheritdoc}
     */
    protected function createPayloadArray($job, $queue, $data = ''): array
    {
        return array_merge(parent::createPayloadArray($job, $queue, $data), [
            'attempts' => 0,
        ]);
    }

    /**
     * Store the payload in overflow storage and return a pointer payload, failing if the payload cannot be stored.
     *
     * @param string $payload
     */
    protected function overflow($payload): string
    {
        $cache = $this->container->make('cache');
        $storeName = Arr::get($this->overflowStorage, 'store');

        if (!$cache instanceof Factory) {
            throw new \UnexpectedValueException('The "cache" container binding must be a cache factory');
        }

        if (null !== $storeName && !is_string($storeName)) {
            throw new \UnexpectedValueException('The overflow "store" option must be a string');
        }

        $store = $cache->store($storeName);

        if (!$store instanceof Repository) {
            throw new \UnexpectedValueException('The overflow storage must be a cache repository');
        }

        if ($store->getStore() instanceof DynamoDbStore) {
            throw new \UnexpectedValueException(sprintf('Unable to store the job payload in overflow storage for queue connection [%s] because it uses the DynamoDB cache driver, which limits items to 400 KB. Set the connection "overflow.store" option to a Redis or Valkey cache store, or another shared cache store that supports large payloads.', $this->getConnectionName()));
        }

        $uuid = Arr::get((array) json_decode($payload, true), 'uuid');
        $pointer = self::OVERFLOW_POINTER_PREFIX.(is_scalar($uuid) ? (string) $uuid : Str::uuid());

        if (!$store->put($pointer, $payload)) {
            throw new \RuntimeException(sprintf('Unable to store the job payload in overflow storage [%s]', $pointer));
        }

        return json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);
    }

    /**
     * Add the given suffix to the given queue name.
     */
    protected function suffixQueue($queue, $suffix = ''): string
    {
        return (string) Str::of($queue)
            ->beforeLast('.fifo')
            ->start(Str::finish($this->prefix, '/'))
            ->finish($suffix)
            ->append(Str::endsWith($queue, '.fifo') ? '.fifo' : '');
    }
}
