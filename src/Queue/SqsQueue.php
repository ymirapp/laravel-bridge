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
use Illuminate\Queue\SqsQueue as LaravelSqsQueue;
use Illuminate\Support\Str;

class SqsQueue extends LaravelSqsQueue
{
    /**
     * The queue name suffix.
     *
     * @var string
     */
    protected $suffix;

    /**
     * Constructor.
     */
    public function __construct(SqsClient $sqs, $default, $prefix = '', $suffix = '', $dispatchAfterCommit = false)
    {
        parent::__construct($sqs, $default, $prefix, $suffix, $dispatchAfterCommit);

        $this->suffix = $suffix;
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
