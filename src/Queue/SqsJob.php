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
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Queue\Jobs\SqsJob as LaravelSqsJob;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class SqsJob extends LaravelSqsJob
{
    /**
     * The overflow storage options for large payload offloading.
     *
     * @var array
     */
    protected $overflowStorage = [];

    /**
     * Constructor.
     */
    public function __construct(Container $container, SqsClient $sqs, array $job, $connectionName, $queue, array $overflowStorage = [])
    {
        parent::__construct($container, $sqs, $job, $connectionName, $queue);

        $this->overflowStorage = $overflowStorage;
    }

    /**
     * {@inheritdoc}
     */
    public function attempts(): int
    {
        $attempts = Arr::get($this->payload(), 'attempts');
        $receiveCount = Arr::get($this->job, 'Attributes.ApproximateReceiveCount');

        if (!is_numeric($attempts)) {
            $attempts = 0;
        }

        if (!is_numeric($receiveCount)) {
            $receiveCount = 1;
        }

        return (int) $attempts + (int) $receiveCount;
    }

    /**
     * {@inheritdoc}
     */
    public function release($delay = 0): void
    {
        $this->released = true;

        $payload = array_merge($this->payload(), [
            'attempts' => $this->attempts(),
        ]);

        $message = [
            'QueueUrl' => $this->queue,
            'MessageBody' => $this->createReleasedMessageBody($payload),
            'DelaySeconds' => $this->secondsUntil($delay),
        ];

        if (Str::endsWith($this->queue, '.fifo')) {
            $message['MessageGroupId'] = Arr::get($this->job, 'Attributes.MessageGroupId');
            $message['MessageDeduplicationId'] = sprintf('%s-%s', Arr::get($this->job, 'Attributes.MessageDeduplicationId'), $payload['attempts']);

            unset($message['DelaySeconds']);
        }

        $this->sqs->deleteMessage([
            'QueueUrl' => $this->queue,
            'ReceiptHandle' => Arr::get($this->job, 'ReceiptHandle'),
        ]);

        $this->sqs->sendMessage($message);
    }

    /**
     * Create the message body for the released job, storing the payload at its existing overflow pointer if it has one.
     */
    protected function createReleasedMessageBody(array $payload): string
    {
        $messageBody = json_encode($payload, JSON_THROW_ON_ERROR);

        if (!is_callable([$this, 'overflowPointer']) || !is_callable([$this, 'overflowStore'])) {
            return $messageBody;
        }

        $pointer = $this->overflowPointer();

        if (empty($pointer) || !is_string($pointer)) {
            return $messageBody;
        }

        $store = $this->overflowStore();

        if (!$store instanceof Repository) {
            throw new \UnexpectedValueException('The overflow storage must be a cache repository');
        }

        if ($store->getStore() instanceof DynamoDbStore) {
            throw new \UnexpectedValueException(sprintf('Unable to store the released job payload in overflow storage for queue connection [%s] because it uses the DynamoDB cache driver, which limits items to 400 KB. Set the connection "overflow.store" option to a Redis or Valkey cache store, or another shared cache store that supports large payloads.', $this->getConnectionName()));
        }

        if (!$store->put($pointer, $messageBody)) {
            throw new \RuntimeException(sprintf('Unable to store the released job payload in overflow storage [%s]', $pointer));
        }

        return json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);
    }
}
