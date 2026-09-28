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

use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Cache\DynamoDbStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Queue\Connectors\SqsConnector as LaravelSqsConnector;
use Illuminate\Queue\Jobs\SqsJob as LaravelSqsJob;
use Illuminate\Queue\SqsQueue as LaravelSqsQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\MockObject\MockObject;
use Ymir\Bridge\Laravel\Queue\SqsJob;
use Ymir\Bridge\Laravel\Queue\SqsQueue;
use Ymir\Bridge\Laravel\Tests\TestCase;

class SqsOverflowTest extends TestCase
{
    private const ENVIRONMENT = [
        'LAMBDA_TASK_ROOT' => '/var/task',
        'YMIR_ENVIRONMENT' => 'testing',
    ];
    private const HANDLER = 'ymir.test.handler';
    private const POINTER = 'laravel:sqs-payloads:uuid';
    private const QUEUE_URL = 'https://sqs.us-east-1.amazonaws.com/123456789012/queue-name';

    private array $commands = [];

    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        foreach (self::ENVIRONMENT as $name => $value) {
            $this->previousEnvironment[$name] = getenv($name);

            putenv(sprintf('%s=%s', $name, $value));
        }

        parent::setUp();

        if (!method_exists(LaravelSqsJob::class, 'overflowPointer')) {
            $this->markTestSkipped('SQS overflow storage requires Laravel 13 or newer.');
        }

        $this->commands = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            putenv(false === $value ? $name : sprintf('%s=%s', $name, $value));
        }

        parent::tearDown();
    }

    public function testBulkDoesNotSendMessageWhenOverflowStoreFailsToWrite(): void
    {
        $this->assertInitialSendFailsWhenOverflowStoreFailsToWrite(function (SqsQueue $connection): void {
            $connection->bulk([self::HANDLER], ['foo' => 'bar']);
        });
    }

    public function testDeletingJobForgetsOverflowPayloadWhenDeleteAfterProcessingIsEnabled(): void
    {
        $this->configureConnection(['enabled' => true, 'store' => 'array', 'delete_after_processing' => true]);
        Cache::store('array')->put(self::POINTER, $this->createPayload());

        $this->runJob(function (SqsJob $job): void {
            $job->delete();
        });

        $this->assertSame(['DeleteMessage'], $this->getCommandNames());
        $this->assertNull(Cache::store('array')->get(self::POINTER));
    }

    public function testDeletingJobForgetsOverflowPayloadWhenStoreUsesDynamoDbDriver(): void
    {
        $store = $this->configureDynamoDbStore('large-payloads');
        $store->expects($this->once())->method('get')->with(self::POINTER)->willReturn($this->createPayload());
        $store->expects($this->once())->method('forget')->with(self::POINTER)->willReturn(true);
        $store->expects($this->never())->method('put');
        $store->expects($this->never())->method('forever');
        $this->configureConnection(['enabled' => true, 'store' => 'large-payloads', 'delete_after_processing' => true]);

        $this->runJob(function (SqsJob $job): void {
            $job->delete();
        });

        $this->assertSame(['DeleteMessage'], $this->getCommandNames());
    }

    public function testDeletingJobKeepsOverflowPayloadWhenDeleteAfterProcessingIsDisabled(): void
    {
        $this->configureConnection(['enabled' => true, 'store' => 'array', 'delete_after_processing' => false]);
        Cache::store('array')->put(self::POINTER, $this->createPayload());

        $this->runJob(function (SqsJob $job): void {
            $job->delete();
        });

        $this->assertSame(['DeleteMessage'], $this->getCommandNames());
        $this->assertSame($this->createPayload(), Cache::store('array')->get(self::POINTER));
    }

    public function testLaterDoesNotSendMessageWhenOverflowStoreFailsToWrite(): void
    {
        $this->assertInitialSendFailsWhenOverflowStoreFailsToWrite(function (SqsQueue $connection): void {
            $connection->later(30, self::HANDLER, ['foo' => 'bar']);
        });
    }

    public function testNativeSqsConnectionJobHydratesAndDeletesOverflowPayloadFromItsOwnConfiguration(): void
    {
        $this->app['queue']->extend('native-sqs', fn (): LaravelSqsConnector => new LaravelSqsConnector());
        $this->app['config']->set('queue.default', 'sqs');
        $this->configureConnection(['enabled' => false]);
        $this->configureConnection(['enabled' => true, 'store' => 'array', 'delete_after_processing' => true], 'native', 'native-sqs');
        Cache::store('array')->put(self::POINTER, $this->createPayload());

        $connection = $this->app['queue']->connection('native');

        $this->assertInstanceOf(LaravelSqsQueue::class, $connection);
        $this->assertNotInstanceOf(SqsQueue::class, $connection);

        $this->runJob(function (SqsJob $job): void {
            $job->delete();
        }, 'queue-name', ['ApproximateReceiveCount' => '1'], 'native');

        $this->assertSame(['DeleteMessage'], $this->getCommandNames());
        $this->assertNull(Cache::store('array')->get(self::POINTER));
    }

    public function testPushDoesNotSendMessageWhenOverflowStoreFailsToWrite(): void
    {
        $this->assertInitialSendFailsWhenOverflowStoreFailsToWrite(function (SqsQueue $connection): void {
            $connection->push(self::HANDLER, ['foo' => 'bar']);
        });
    }

    public function testPushRawDoesNotStoreOverflowPayloadWhenDefaultStoreUsesDynamoDbDriver(): void
    {
        $this->app['config']->set('cache.default', 'large-payloads');

        $this->assertPushRawRejectsDynamoDbStore($this->configureDynamoDbStore('large-payloads'), ['enabled' => true, 'always' => true]);
    }

    public function testPushRawDoesNotStoreOverflowPayloadWhenStoreUsesDynamoDbDriver(): void
    {
        $this->assertPushRawRejectsDynamoDbStore($this->configureDynamoDbStore('large-payloads'), ['enabled' => true, 'always' => true, 'store' => 'large-payloads']);
    }

    public function testPushRawSendsPayloadInlineWhenItDoesNotOverflow(): void
    {
        $this->configureConnection(['enabled' => true, 'store' => 'array']);

        $this->app['queue']->connection('sqs')->pushRaw($this->createPayload());

        $this->assertSame(['SendMessage'], $this->getCommandNames());
        $this->assertSame($this->createPayload(), $this->commands[0]['MessageBody']);
        $this->assertNull(Cache::store('array')->get(self::POINTER));
    }

    public function testPushRawSendsPayloadInlineWhenItDoesNotOverflowAndStoreUsesDynamoDbDriver(): void
    {
        $store = $this->configureDynamoDbStore('large-payloads');
        $store->expects($this->never())->method('put');
        $store->expects($this->never())->method('forever');
        $this->configureConnection(['enabled' => true, 'store' => 'large-payloads']);

        $this->app['queue']->connection('sqs')->pushRaw($this->createPayload());

        $this->assertSame(['SendMessage'], $this->getCommandNames());
        $this->assertSame($this->createPayload(), $this->commands[0]['MessageBody']);
    }

    public function testPushRawStoresPayloadAtPointerWithEmptyUuid(): void
    {
        $this->assertPushRawStoresPayloadAtPointer('', 'laravel:sqs-payloads:');
    }

    public function testPushRawStoresPayloadAtPointerWithNumericUuid(): void
    {
        $this->assertPushRawStoresPayloadAtPointer(123, 'laravel:sqs-payloads:123');
    }

    public function testPushRawStoresPayloadInConfiguredStoreWhenOverflowIsForced(): void
    {
        $this->app['config']->set('cache.stores.overflow', ['driver' => 'array']);
        $this->configureConnection(['enabled' => true, 'always' => true, 'store' => 'overflow']);

        $connection = $this->app['queue']->connection('sqs');

        $this->assertInstanceOf(SqsQueue::class, $connection);
        $this->assertSame(LaravelSqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX.'uuid', self::POINTER);

        $connection->pushRaw($this->createPayload());

        $this->assertSame(['SendMessage'], $this->getCommandNames());
        $this->assertSame(self::QUEUE_URL, $this->commands[0]['QueueUrl']);
        $this->assertSame(json_encode(['@pointer' => self::POINTER]), $this->commands[0]['MessageBody']);
        $this->assertSame($this->createPayload(), Cache::store('overflow')->get(self::POINTER));
        $this->assertNull(Cache::store('array')->get(self::POINTER));
    }

    public function testReleasingFifoJobStoresUpdatedPayloadAtExistingOverflowPointer(): void
    {
        $this->configureConnection(['enabled' => true, 'store' => 'array', 'delete_after_processing' => true]);
        Cache::store('array')->put(self::POINTER, $this->createPayload());

        $this->runJob(function (SqsJob $job): void {
            $job->release(30);
        }, 'queue-name.fifo', [
            'ApproximateReceiveCount' => '2',
            'MessageDeduplicationId' => 'dedup',
            'MessageGroupId' => 'group',
        ]);

        $this->assertSame(['DeleteMessage', 'SendMessage'], $this->getCommandNames());
        $this->assertSame([
            'QueueUrl' => self::QUEUE_URL.'.fifo',
            'MessageBody' => json_encode(['@pointer' => self::POINTER]),
            'MessageGroupId' => 'group',
            'MessageDeduplicationId' => 'dedup-2',
        ], $this->getCommandArguments($this->commands[1]));
        $this->assertSame(2, json_decode(Cache::store('array')->get(self::POINTER), true)['attempts']);
    }

    public function testReleasingJobDoesNotDeleteOriginalMessageWhenOverflowStoreFailsToWrite(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('get')->with(self::POINTER)->willReturn($this->createPayload());
        $store->expects($this->once())->method('forever')->with(self::POINTER, $this->callback('is_string'))->willReturn(false);

        Cache::extend('failing', fn (): Repository => Cache::repository($store));

        $this->app['config']->set('cache.stores.failing', ['driver' => 'failing']);
        $overflow = ['enabled' => true, 'store' => 'failing'];
        $this->configureConnection($overflow);

        $job = new SqsJob($this->app, $this->app['queue']->connection('sqs')->getSqs(), $this->createMessage(['ApproximateReceiveCount' => '1']), 'sqs', self::QUEUE_URL, $overflow);

        try {
            $job->release();
            $this->fail('Expected release to fail when the overflow store cannot be written.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(sprintf('Unable to store the released job payload in overflow storage [%s]', self::POINTER), $exception->getMessage());
            $this->assertSame([], $this->commands);
        }
    }

    public function testReleasingJobDoesNotDeleteOriginalMessageWhenOverflowStoreUsesDynamoDbDriver(): void
    {
        $store = $this->configureDynamoDbStore('large-payloads');
        $store->expects($this->once())->method('get')->with(self::POINTER)->willReturn($this->createPayload());
        $store->expects($this->never())->method('put');
        $store->expects($this->never())->method('forever');
        $overflow = ['enabled' => true, 'store' => 'large-payloads'];
        $this->configureConnection($overflow);

        $job = new SqsJob($this->app, $this->app['queue']->connection('sqs')->getSqs(), $this->createMessage(['ApproximateReceiveCount' => '1']), 'sqs', self::QUEUE_URL, $overflow);

        try {
            $job->release();
            $this->fail('Expected release to fail when the overflow store uses the DynamoDB cache driver.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertSame('Unable to store the released job payload in overflow storage for queue connection [sqs] because it uses the DynamoDB cache driver, which limits items to 400 KB. Set the connection "overflow.store" option to a Redis or Valkey cache store, or another shared cache store that supports large payloads.', $exception->getMessage());
            $this->assertSame([], $this->commands);
        }
    }

    public function testReleasingStandardJobStoresUpdatedPayloadAtExistingOverflowPointer(): void
    {
        $this->configureConnection(['enabled' => true, 'store' => 'array', 'delete_after_processing' => true]);
        Cache::store('array')->put(self::POINTER, $this->createPayload());

        $this->runJob(function (SqsJob $job): void {
            $job->release(30);
        });

        $this->assertSame(['DeleteMessage', 'SendMessage'], $this->getCommandNames());
        $this->assertSame('handle', $this->commands[0]['ReceiptHandle']);
        $this->assertSame([
            'QueueUrl' => self::QUEUE_URL,
            'MessageBody' => json_encode(['@pointer' => self::POINTER]),
            'DelaySeconds' => 30,
        ], $this->getCommandArguments($this->commands[1]));
        $this->assertSame(array_merge(json_decode($this->createPayload(), true), ['attempts' => 1]), json_decode(Cache::store('array')->get(self::POINTER), true));
    }

    private function assertInitialSendFailsWhenOverflowStoreFailsToWrite(callable $send): void
    {
        $pointer = null;
        $store = $this->createMock(Store::class);
        $store->expects($this->once())->method('forever')->with($this->callback(function (string $key) use (&$pointer): bool {
            $pointer = $key;

            return Str::startsWith($key, 'laravel:sqs-payloads:');
        }), $this->callback('is_string'))->willReturn(false);

        Cache::extend('failing', fn (): Repository => Cache::repository($store));

        $this->app['config']->set('cache.stores.failing', ['driver' => 'failing']);
        $this->configureConnection(['enabled' => true, 'always' => true, 'store' => 'failing']);

        try {
            $send($this->app['queue']->connection('sqs'));
            $this->fail('Expected sending to fail when the overflow store cannot be written.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(sprintf('Unable to store the job payload in overflow storage [%s]', $pointer), $exception->getMessage());
            $this->assertSame([], $this->commands);
        }
    }

    private function assertPushRawRejectsDynamoDbStore(MockObject $store, array $overflow): void
    {
        $store->expects($this->never())->method('put');
        $store->expects($this->never())->method('forever');
        $this->configureConnection($overflow);

        try {
            $this->app['queue']->connection('sqs')->pushRaw($this->createPayload());
            $this->fail('Expected pushing to fail when the overflow store uses the DynamoDB cache driver.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertSame('Unable to store the job payload in overflow storage for queue connection [sqs] because it uses the DynamoDB cache driver, which limits items to 400 KB. Set the connection "overflow.store" option to a Redis or Valkey cache store, or another shared cache store that supports large payloads.', $exception->getMessage());
            $this->assertSame([], $this->commands);
        }
    }

    private function assertPushRawStoresPayloadAtPointer($uuid, string $pointer): void
    {
        $payload = (string) json_encode(['uuid' => $uuid, 'job' => self::HANDLER.'@handle']);
        $this->configureConnection(['enabled' => true, 'always' => true, 'store' => 'array']);

        $this->app['queue']->connection('sqs')->pushRaw($payload);

        $this->assertSame(['SendMessage'], $this->getCommandNames());
        $this->assertSame(json_encode(['@pointer' => $pointer]), $this->commands[0]['MessageBody']);
        $this->assertSame($payload, Cache::store('array')->get($pointer));
    }

    private function configureConnection(array $overflow, string $connection = 'sqs', string $driver = 'sqs'): void
    {
        $this->app['config']->set(sprintf('queue.connections.%s', $connection), [
            'driver' => $driver,
            'key' => 'key',
            'secret' => 'secret',
            'region' => 'us-east-1',
            'queue' => self::QUEUE_URL,
            'overflow' => $overflow,
            'handler' => function (CommandInterface $command): PromiseInterface {
                $this->commands[] = $command;

                return new FulfilledPromise(new Result(array_merge(['@metadata' => ['statusCode' => 200]], 'SendMessage' === $command->getName() ? ['MessageId' => 'message-id'] : [])));
            },
        ]);
    }

    private function configureDynamoDbStore(string $name): MockObject
    {
        $store = $this->createMock(DynamoDbStore::class);

        Cache::extend('dynamodb', fn (): Repository => Cache::repository($store));

        $this->app['config']->set(sprintf('cache.stores.%s', $name), ['driver' => 'dynamodb']);

        return $store;
    }

    private function createMessage(array $attributes): array
    {
        return [
            'MessageId' => 'id',
            'ReceiptHandle' => 'handle',
            'Body' => json_encode(['@pointer' => self::POINTER]),
            'Attributes' => $attributes,
            'MessageAttributes' => [],
        ];
    }

    private function createPayload(): string
    {
        return (string) json_encode([
            'uuid' => 'uuid',
            'displayName' => self::HANDLER,
            'job' => self::HANDLER.'@handle',
            'maxTries' => null,
            'timeout' => null,
            'data' => ['foo' => 'bar'],
            'attempts' => 0,
        ]);
    }

    private function getCommandArguments(CommandInterface $command): array
    {
        return array_filter($command->toArray(), fn (string $key): bool => '@' !== $key[0], ARRAY_FILTER_USE_KEY);
    }

    private function getCommandNames(): array
    {
        return array_map(fn (CommandInterface $command): string => $command->getName(), $this->commands);
    }

    private function runJob(callable $callback, string $queueName = 'queue-name', array $attributes = ['ApproximateReceiveCount' => '1'], string $connection = 'sqs'): void
    {
        $handler = \Mockery::mock();
        $handler->shouldReceive('handle')
                ->once()
                ->with(\Mockery::type(SqsJob::class), ['foo' => 'bar'])
                ->andReturnUsing($callback);

        $this->app->instance(self::HANDLER, $handler);

        $message = $this->createMessage($attributes);

        $this->artisan('ymir:queue:work', ['--connection' => $connection, '--message' => base64_encode((string) json_encode([
            'messageId' => $message['MessageId'],
            'receiptHandle' => $message['ReceiptHandle'],
            'body' => $message['Body'],
            'attributes' => $message['Attributes'],
            'messageAttributes' => $message['MessageAttributes'],
            'eventSourceARN' => sprintf('arn:aws:sqs:us-east-1:123456789012:%s', $queueName),
        ]))])->assertExitCode(0);
    }
}
