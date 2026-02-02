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
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Ymir\Bridge\Laravel\Queue\SqsQueue;

class SqsQueueTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        putenv('YMIR_QUEUE_TEST_QUEUE');
        putenv('YMIR_QUEUE_TEST_QUEUE_NAME');
        putenv('YMIR_QUEUE_DEFAULT');
    }

    public function testCreatePayloadArrayAddsAttempts(): void
    {
        $queue = $this->createQueue();

        $reflection = new \ReflectionClass(SqsQueue::class);
        $method = $reflection->getMethod('createPayloadArray');
        $method->setAccessible(true);

        $payload = $method->invoke($queue, 'job', 'test-queue', 'data');

        $this->assertArrayHasKey('attempts', $payload);
        $this->assertEquals(0, $payload['attempts']);
    }

    public function testGetQueueReturnsEnvironmentVariableIfItExists(): void
    {
        putenv('YMIR_QUEUE_TEST_QUEUE=https://sqs.us-east-1.amazonaws.com/123456789012/env-queue');

        $queue = $this->createQueue();

        $this->assertEquals('https://sqs.us-east-1.amazonaws.com/123456789012/env-queue', $queue->getQueue('test-queue'));
    }

    public function testGetQueueReturnsEnvironmentVariableWithHyphensTransformed(): void
    {
        putenv('YMIR_QUEUE_TEST_QUEUE_NAME=https://sqs.us-east-1.amazonaws.com/123456789012/hyphen-queue');

        $queue = $this->createQueue();

        $this->assertEquals('https://sqs.us-east-1.amazonaws.com/123456789012/hyphen-queue', $queue->getQueue('test-queue-name'));
    }

    public function testGetQueueReturnsQueueIfItIsAUrl(): void
    {
        $queue = $this->createQueue();

        $this->assertEquals('https://sqs.us-east-1.amazonaws.com/123456789012/test-queue', $queue->getQueue('https://sqs.us-east-1.amazonaws.com/123456789012/test-queue'));
    }

    public function testGetQueueReturnsSuffixedFifoQueueIfEnvironmentVariableDoesNotExist(): void
    {
        $queue = $this->createQueue('default', 'https://sqs.us-east-1.amazonaws.com/123456789012/', '-suffix');

        $this->assertEquals('https://sqs.us-east-1.amazonaws.com/123456789012/test-queue-suffix.fifo', $queue->getQueue('test-queue.fifo'));
    }

    public function testGetQueueReturnsSuffixedQueueIfEnvironmentVariableDoesNotExist(): void
    {
        $queue = $this->createQueue('default', 'https://sqs.us-east-1.amazonaws.com/123456789012/', '-suffix');

        $this->assertEquals('https://sqs.us-east-1.amazonaws.com/123456789012/test-queue-suffix', $queue->getQueue('test-queue'));
    }

    public function testGetQueueThrowsExceptionIfQueueIsNotAUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Queue [test-queue] is not configured in ymir.yml.');

        $queue = $this->createQueue();

        $queue->getQueue('test-queue');
    }

    public function testGetQueueUsesDefaultQueueIfNoneProvided(): void
    {
        putenv('YMIR_QUEUE_DEFAULT=https://sqs.us-east-1.amazonaws.com/123456789012/default-queue');

        $queue = $this->createQueue('default');

        $this->assertEquals('https://sqs.us-east-1.amazonaws.com/123456789012/default-queue', $queue->getQueue(null));
    }

    private function createQueue(string $default = 'default', string $prefix = '', string $suffix = ''): SqsQueue
    {
        return new SqsQueue(\Mockery::mock(SqsClient::class), $default, $prefix, $suffix);
    }
}
