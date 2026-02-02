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

use Illuminate\Queue\Jobs\SqsJob as LaravelSqsJob;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class SqsJob extends LaravelSqsJob
{
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

        $this->sqs->deleteMessage([
            'QueueUrl' => $this->queue,
            'ReceiptHandle' => Arr::get($this->job, 'ReceiptHandle'),
        ]);

        $message = [
            'QueueUrl' => $this->queue,
            'MessageBody' => json_encode($payload),
            'DelaySeconds' => $this->secondsUntil($delay),
        ];

        if (Str::endsWith($this->queue, '.fifo')) {
            $message['MessageGroupId'] = Arr::get($this->job, 'Attributes.MessageGroupId');
            $message['MessageDeduplicationId'] = sprintf('%s-%s', Arr::get($this->job, 'Attributes.MessageDeduplicationId'), $payload['attempts']);

            unset($message['DelaySeconds']);
        }

        $this->sqs->sendMessage($message);
    }
}
