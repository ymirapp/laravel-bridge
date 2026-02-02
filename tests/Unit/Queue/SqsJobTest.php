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

namespace Ymir\Bridge\Laravel\Tests\Unit\Queue;

use Aws\Sqs\SqsClient;
use Illuminate\Container\Container;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Ymir\Bridge\Laravel\Queue\SqsJob;

class SqsJobTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testAttemptsReturnsApproximateReceiveCountIfAttemptsIsNotNumeric(): void
    {
        $job = $this->createJob(['attempts' => 'foo'], 'test-queue', null, ['ApproximateReceiveCount' => 3]);

        $this->assertEquals(3, $job->attempts());
    }

    public function testAttemptsReturnsAttemptsPlusApproximateReceiveCount(): void
    {
        $job = $this->createJob(['attempts' => 2], 'test-queue', null, ['ApproximateReceiveCount' => 3]);

        $this->assertEquals(5, $job->attempts());
    }

    public function testAttemptsReturnsAttemptsPlusOneIfApproximateReceiveCountIsNotNumeric(): void
    {
        $job = $this->createJob(['attempts' => 2], 'test-queue', null, ['ApproximateReceiveCount' => 'foo']);

        $this->assertEquals(3, $job->attempts());
    }

    public function testAttemptsReturnsOneIfAttemptsAndApproximateReceiveCountAreNotPresent(): void
    {
        $job = $this->createJob([]);

        $this->assertEquals(1, $job->attempts());
    }

    public function testReleaseHandlesFifoQueues(): void
    {
        $sqs = \Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('deleteMessage')
            ->once();

        $sqs->shouldReceive('sendMessage')
            ->once()
            ->with(\Mockery::on(fn ($message): bool => 'test-queue.fifo' === $message['QueueUrl']
                && !isset($message['DelaySeconds'])
                && 'group-id' === $message['MessageGroupId']
                && 'dedup-id-1' === $message['MessageDeduplicationId']));

        $payload = [
            'Body' => json_encode(['foo' => 'bar']),
            'ReceiptHandle' => 'test-handle',
            'Attributes' => [
                'MessageGroupId' => 'group-id',
                'MessageDeduplicationId' => 'dedup-id',
            ],
        ];

        $job = $this->createJob($payload, 'test-queue.fifo', $sqs);

        $job->release(60);
    }

    public function testReleaseSendsCorrectMessageToSqs(): void
    {
        $sqs = \Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('deleteMessage')
            ->once()
            ->with([
                'QueueUrl' => 'test-queue',
                'ReceiptHandle' => 'test-handle',
            ]);

        $sqs->shouldReceive('sendMessage')
            ->once()
            ->with(\Mockery::on(fn ($message): bool => 'test-queue' === $message['QueueUrl']
                && 60 === $message['DelaySeconds']
                && 5 === json_decode($message['MessageBody'], true)['attempts']));

        $job = $this->createJob(['attempts' => 2], 'test-queue', $sqs, ['ApproximateReceiveCount' => 3]);

        $job->release(60);
    }

    private function createJob($payload, string $queue = 'test-queue', $sqs = null, array $attributes = []): SqsJob
    {
        $container = \Mockery::mock(Container::class);
        $sqs = $sqs ?: \Mockery::mock(SqsClient::class);

        $payload = is_array($payload) && isset($payload['Body']) ? $payload : [
            'Body' => json_encode($payload),
            'ReceiptHandle' => 'test-handle',
            'Attributes' => $attributes,
        ];

        return new SqsJob($container, $sqs, $payload, 'test-connection', $queue);
    }
}
