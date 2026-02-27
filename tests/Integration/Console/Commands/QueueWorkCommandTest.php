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

namespace Ymir\Bridge\Laravel\Tests\Integration\Console\Commands;

use Aws\Sqs\SqsClient;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\SqsQueue;
use Illuminate\Queue\WorkerOptions;
use Ymir\Bridge\Laravel\Queue\SqsJob;
use Ymir\Bridge\Laravel\Queue\Worker;
use Ymir\Bridge\Laravel\Tests\TestCase;

class QueueWorkCommandTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('LAMBDA_TASK_ROOT=/var/task');
        putenv('YMIR_ENVIRONMENT=testing');

        parent::setUp();

        $this->app->bind(Worker::class, fn () => \Mockery::mock(Worker::class));
    }

    protected function tearDown(): void
    {
        putenv('LAMBDA_TASK_ROOT');
        putenv('YMIR_ENVIRONMENT');

        parent::tearDown();
    }

    public function testHandleFailsIfConnectionIsNotSqs(): void
    {
        $this->app['config']->set('queue.connections.not-sqs', ['driver' => 'sync']);

        $this->artisan('ymir:queue:work', ['--connection' => 'not-sqs', '--message' => base64_encode(json_encode(['foo' => 'bar']))])
             ->assertExitCode(1)
             ->expectsOutput('Connection [not-sqs] must be an SQS connection');
    }

    public function testHandleFailsIfMessageCannotBeDecoded(): void
    {
        $this->artisan('ymir:queue:work', ['--message' => 'invalid-base64'])
             ->assertExitCode(1)
             ->expectsOutput('Unable to decode the SQS message');
    }

    public function testHandleFailsIfMessageIsMissing(): void
    {
        $this->artisan('ymir:queue:work')
             ->assertExitCode(1)
             ->expectsOutput('The "--message" option is required');
    }

    public function testHandleFailsIfQueueUrlCannotBeResolved(): void
    {
        $sqs = \Mockery::mock(SqsClient::class);
        $queue = \Mockery::mock(SqsQueue::class);
        $queue->shouldReceive('getSqs')->andReturn($sqs);
        $queue->shouldReceive('setConnectionName')->andReturnSelf();
        $queue->shouldReceive('setContainer');
        $queue->shouldReceive('setConfig');

        $connector = \Mockery::mock(ConnectorInterface::class);
        $connector->shouldReceive('connect')->andReturn($queue);

        $this->app['queue']->extend('sqs', fn () => $connector);

        $this->app['config']->set('queue.connections.sqs', ['driver' => 'sqs']);

        $message = base64_encode(json_encode(['foo' => 'bar']));

        $this->artisan('ymir:queue:work', ['--message' => $message])
             ->assertExitCode(1)
             ->expectsOutput('Unable to resolve queue URL');
    }

    public function testHandleRunsWorkerWithSqsJob(): void
    {
        $sqs = \Mockery::mock(SqsClient::class);
        $queue = \Mockery::mock(SqsQueue::class);
        $queue->shouldReceive('getSqs')->andReturn($sqs);
        $queue->shouldReceive('setConnectionName')->andReturnSelf();
        $queue->shouldReceive('setContainer');
        $queue->shouldReceive('setConfig');

        $connector = \Mockery::mock(ConnectorInterface::class);
        $connector->shouldReceive('connect')->andReturn($queue);

        $this->app['queue']->extend('sqs', fn () => $connector);

        $this->app['config']->set('queue.connections.sqs', ['driver' => 'sqs']);

        $messageData = [
            'messageId' => 'id',
            'receiptHandle' => 'handle',
            'body' => 'body',
            'attributes' => ['attr'],
            'messageAttributes' => ['msgAttr'],
            'eventSourceARN' => 'arn:aws:sqs:us-east-1:123456789012:queue-name',
        ];
        $message = base64_encode(json_encode($messageData));

        $worker = \Mockery::mock(Worker::class);
        $worker->shouldReceive('runSqsJob')
               ->once()
               ->with(
                   \Mockery::type(SqsJob::class),
                   'sqs',
                   \Mockery::on(function (WorkerOptions $options): bool {
                       $this->assertIsInt($options->backoff);
                       $this->assertIsInt($options->timeout);
                       $this->assertIsInt($options->maxTries);
                       $this->assertIsBool($options->force);

                       return true;
                   })
               );

        $this->app->instance(Worker::class, $worker);

        $this->artisan('ymir:queue:work', ['--message' => $message])
             ->assertExitCode(0);
    }
}
