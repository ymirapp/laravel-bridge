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

use Illuminate\Container\Container;
use Illuminate\Queue\Worker as LaravelQueueWorker;
use Illuminate\Queue\WorkerOptions;

class Worker extends LaravelQueueWorker
{
    /**
     * Process the given SQS job.
     */
    public function runSqsJob(SqsJob $job, string $connectionName, WorkerOptions $options): void
    {
        if (property_exists($this, 'resetScope') && isset($this->resetScope)) {
            ($this->resetScope)();
        } elseif (!property_exists($this, 'resetScope')) {
            Container::getInstance()->forgetScopedInstances();
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGALRM, function () use ($job): void {
            $this->markJobAsFailedIfItShouldFailOnTimeout($job->getConnectionName(), $job, $this->maxAttemptsExceededException($job));

            exit(1);
        });

        pcntl_alarm(max($this->timeoutForJob($job, $options), 0));

        $this->runJob($job, $connectionName, $options);

        pcntl_alarm(0);
    }

    /**
     * Mark the given job as failed if it should fail on timeouts.
     */
    protected function markJobAsFailedIfItShouldFailOnTimeout($connectionName, $job, \Throwable $exception): void
    {
        if (method_exists($job, 'shouldFailOnTimeout') && $job->shouldFailOnTimeout()) {
            $this->failJob($job, $exception);
        }
    }
}
