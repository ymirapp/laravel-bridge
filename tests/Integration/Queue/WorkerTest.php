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

namespace Ymir\Bridge\Laravel\Tests\Integration\Queue;

use Aws\Sqs\SqsClient;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Ymir\Bridge\Laravel\Queue\SqsJob;
use Ymir\Bridge\Laravel\Queue\Worker;
use Ymir\Bridge\Laravel\Tests\TestCase;

class WorkerTest extends TestCase
{
    public function testRunSqsJobFailsTimedOutJobWithTimeoutException(): void
    {
        $failureFile = (string) tempnam(sys_get_temp_dir(), 'ymir-timeout-');

        $handler = \Mockery::mock();
        $handler->shouldReceive('fire')->andReturnUsing(function (): void {
            sleep(5);
        });

        $sqs = \Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('deleteMessage');

        $this->app->instance('timing-out-job', $handler);
        $this->app['events']->listen(JobFailed::class, function (JobFailed $event) use ($failureFile): void {
            file_put_contents($failureFile, get_class($event->exception));
        });

        $job = new SqsJob($this->app, $sqs, [
            'MessageId' => 'id',
            'ReceiptHandle' => 'handle',
            'Body' => json_encode(['uuid' => 'uuid', 'displayName' => 'timing-out-job', 'job' => 'timing-out-job', 'maxTries' => 3, 'timeout' => 1, 'failOnTimeout' => true, 'data' => [], 'attempts' => 0]),
            'Attributes' => ['ApproximateReceiveCount' => '1'],
        ], 'sqs', 'https://sqs.us-east-1.amazonaws.com/123456789012/queue-name');
        $worker = new Worker($this->app['queue'], $this->app['events'], \Mockery::mock(ExceptionHandler::class)->shouldIgnoreMissing(), fn (): bool => false);

        // The timeout handler exits the process, so the job runs in a child process.
        $pid = pcntl_fork();

        if (0 === $pid) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $worker->runSqsJob($job, 'sqs', new WorkerOptions());

            exit(0);
        }

        pcntl_waitpid($pid, $status);

        $exceptionClass = file_get_contents($failureFile);
        unlink($failureFile);

        $this->assertSame(1, pcntl_wexitstatus($status));
        $this->assertSame(class_exists('Illuminate\Queue\TimeoutExceededException') ? 'Illuminate\Queue\TimeoutExceededException' : MaxAttemptsExceededException::class, $exceptionClass);
    }
}
