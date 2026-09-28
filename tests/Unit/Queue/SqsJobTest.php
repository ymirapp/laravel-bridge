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
use Illuminate\Cache\CacheManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Queue\Jobs\SqsJob as LaravelSqsJob;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Ymir\Bridge\Laravel\Queue\SqsJob;

class SqsJobTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const OVERFLOW_POINTER = 'laravel-queue-payloads:uuid';
    private const OVERFLOW_STORAGE = ['enabled' => true, 'store' => 'redis', 'delete_after_processing' => true];

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

    public function testReleaseDeletesMessageAndSendsItWithUpdatedAttemptsAndDelay(): void
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

    public function testReleaseDoesNotDeleteOriginalMessageIfOverflowStoreIsNotCacheRepository(): void
    {
        $this->skipUnlessOverflowIsSupported();

        $sqs = \Mockery::mock(SqsClient::class);
        $sqs->shouldNotReceive('deleteMessage');
        $sqs->shouldNotReceive('sendMessage');

        $job = $this->getMockBuilder(SqsJob::class)
            ->setConstructorArgs([$this->createOverflowContainer(new \stdClass()), $sqs, [
                'Body' => json_encode(['@pointer' => self::OVERFLOW_POINTER]),
                'ReceiptHandle' => 'test-handle',
                'Attributes' => [],
            ], 'test-connection', 'test-queue', self::OVERFLOW_STORAGE])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $job->expects($this->atLeastOnce())
            ->method('getRawBody')
            ->willReturn(json_encode(['job' => 'test-job', 'attempts' => 2]));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The overflow storage must be a cache repository');

        $job->release(60);
    }

    public function testReleaseDoesNotDeleteOriginalMessageIfOverflowStoreReturnsFalse(): void
    {
        $this->skipUnlessOverflowIsSupported();

        $store = $this->createMock(Repository::class);
        $store->expects($this->once())
            ->method('get')
            ->with(self::OVERFLOW_POINTER)
            ->willReturn(json_encode(['job' => 'test-job', 'attempts' => 2]));
        $store->expects($this->once())
            ->method('put')
            ->with(self::OVERFLOW_POINTER, json_encode(['job' => 'test-job', 'attempts' => 3]))
            ->willReturn(false);

        $sqs = \Mockery::mock(SqsClient::class);
        $sqs->shouldNotReceive('deleteMessage');
        $sqs->shouldNotReceive('sendMessage');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to store the released job payload in overflow storage [laravel-queue-payloads:uuid]');

        $this->createOverflowJob($store, $sqs)->release(60);
    }

    public function testReleaseDoesNotDeleteOriginalMessageIfOverflowStoreThrowsException(): void
    {
        $this->skipUnlessOverflowIsSupported();

        $store = $this->createMock(Repository::class);
        $store->expects($this->once())
            ->method('get')
            ->with(self::OVERFLOW_POINTER)
            ->willReturn(json_encode(['job' => 'test-job', 'attempts' => 2]));
        $store->expects($this->once())
            ->method('put')
            ->with(self::OVERFLOW_POINTER, json_encode(['job' => 'test-job', 'attempts' => 3]))
            ->willThrowException(new \RuntimeException('Cache unavailable'));

        $sqs = \Mockery::mock(SqsClient::class);
        $sqs->shouldNotReceive('deleteMessage');
        $sqs->shouldNotReceive('sendMessage');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cache unavailable');

        $this->createOverflowJob($store, $sqs)->release(60);
    }

    public function testReleaseDoesNotStorePayloadIfOverflowPointerIsEmptyString(): void
    {
        $this->assertReleaseIgnoresInvalidOverflowPointer('');
    }

    public function testReleaseDoesNotStorePayloadIfOverflowPointerIsZeroString(): void
    {
        $this->assertReleaseIgnoresInvalidOverflowPointer('0');
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

    public function testReleaseHandlesFifoQueuesWithOverflowPointer(): void
    {
        $this->skipUnlessOverflowIsSupported();

        $store = $this->createMock(Repository::class);
        $store->expects($this->once())
            ->method('get')
            ->with(self::OVERFLOW_POINTER)
            ->willReturn(json_encode(['job' => 'test-job', 'attempts' => 0]));
        $store->expects($this->once())
            ->method('put')
            ->with(self::OVERFLOW_POINTER, json_encode(['job' => 'test-job', 'attempts' => 1]))
            ->willReturn(true);

        $sqs = \Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('deleteMessage')->once();
        $sqs->shouldReceive('sendMessage')
            ->once()
            ->with([
                'QueueUrl' => 'test-queue.fifo',
                'MessageBody' => json_encode(['@pointer' => self::OVERFLOW_POINTER]),
                'MessageGroupId' => 'group-id',
                'MessageDeduplicationId' => 'dedup-id-1',
            ]);

        $this->createOverflowJob($store, $sqs, 'test-queue.fifo', [
            'MessageGroupId' => 'group-id',
            'MessageDeduplicationId' => 'dedup-id',
        ])->release(60);
    }

    public function testReleaseStoresUpdatedPayloadAtExistingOverflowPointerBeforeDeletingMessage(): void
    {
        $this->skipUnlessOverflowIsSupported();

        $store = \Mockery::mock(Repository::class);
        $store->shouldReceive('get')->once()->with(self::OVERFLOW_POINTER)->andReturn(json_encode(['job' => 'test-job', 'attempts' => 2]));
        $store->shouldReceive('getStore')->andReturn(\Mockery::mock(Store::class));
        $store->shouldReceive('put')->once()->with(self::OVERFLOW_POINTER, json_encode(['job' => 'test-job', 'attempts' => 5]))->andReturn(true)->globally()->ordered();
        $store->shouldNotReceive('forget');

        $sqs = \Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('deleteMessage')
            ->once()
            ->with([
                'QueueUrl' => 'test-queue',
                'ReceiptHandle' => 'test-handle',
            ])
            ->globally()
            ->ordered();
        $sqs->shouldReceive('sendMessage')
            ->once()
            ->with([
                'QueueUrl' => 'test-queue',
                'MessageBody' => json_encode(['@pointer' => self::OVERFLOW_POINTER]),
                'DelaySeconds' => 60,
            ])
            ->globally()
            ->ordered();

        $this->createOverflowJob($store, $sqs, 'test-queue', ['ApproximateReceiveCount' => 3])->release(60);
    }

    private function assertReleaseIgnoresInvalidOverflowPointer(string $pointer): void
    {
        $this->skipUnlessOverflowIsSupported();

        $store = $this->createMock(Repository::class);
        $store->expects($this->never())->method('get');
        $store->expects($this->never())->method('put');

        $sqs = \Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('deleteMessage')->once();
        $sqs->shouldReceive('sendMessage')
            ->once()
            ->with([
                'QueueUrl' => 'test-queue',
                'MessageBody' => json_encode(['@pointer' => $pointer, 'attempts' => 1]),
                'DelaySeconds' => 60,
            ]);

        $this->createOverflowJob($store, $sqs, 'test-queue', [], $pointer)->release(60);
    }

    private function createJob(array $payload, string $queue = 'test-queue', ?SqsClient $sqs = null, array $attributes = []): SqsJob
    {
        $container = \Mockery::mock(Container::class);
        $sqs = $sqs ?: \Mockery::mock(SqsClient::class);

        $payload = isset($payload['Body']) ? $payload : [
            'Body' => json_encode($payload),
            'ReceiptHandle' => 'test-handle',
            'Attributes' => $attributes,
        ];

        return new SqsJob($container, $sqs, $payload, 'test-connection', $queue);
    }

    private function createOverflowContainer(object $store): Container
    {
        $cache = $this->createMock(CacheManager::class);
        $cache->expects($this->any())
            ->method('store')
            ->with('redis')
            ->willReturn($store);

        $container = $this->createMock(Container::class);
        $container->expects($this->any())
            ->method('make')
            ->with('cache')
            ->willReturn($cache);

        return $container;
    }

    private function createOverflowJob(Repository $store, SqsClient $sqs, string $queue = 'test-queue', array $attributes = [], string $pointer = self::OVERFLOW_POINTER): SqsJob
    {
        return new SqsJob($this->createOverflowContainer($store), $sqs, [
            'Body' => json_encode(['@pointer' => $pointer]),
            'ReceiptHandle' => 'test-handle',
            'Attributes' => $attributes,
        ], 'test-connection', $queue, self::OVERFLOW_STORAGE);
    }

    private function skipUnlessOverflowIsSupported(): void
    {
        if (!method_exists(LaravelSqsJob::class, 'overflowPointer')) {
            $this->markTestSkipped('SQS overflow storage requires Laravel 13 or newer.');
        }
    }
}
