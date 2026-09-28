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
use PHPUnit\Framework\TestCase;
use Ymir\Bridge\Laravel\Queue\SqsConnector;

class SqsConnectorTest extends TestCase
{
    private const QUEUE_URL = 'https://sqs.us-east-1.amazonaws.com/123456789012/test-queue';

    public function testConnectKeepsSdkOptionsWhenQueueOptionsAreConfigured(): void
    {
        $connector = new SqsConnector();

        $queue = $connector->connect([
            'driver' => 'sqs',
            'queue' => self::QUEUE_URL,
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789012',
            'suffix' => '-production',
            'after_commit' => true,
            'overflow' => ['enabled' => true, 'store' => 'redis'],
            'region' => 'us-east-1',
            'endpoint' => 'https://sqs.example.test',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'token' => 'test-token',
            'http' => ['timeout' => 12, 'connect_timeout' => 3],
        ]);

        $sqs = $queue->getSqs();
        $credentials = $sqs->getCredentials()->wait();

        $this->assertSame('us-east-1', $sqs->getRegion());
        $this->assertSame('https://sqs.example.test', (string) $sqs->getEndpoint());
        $this->assertSame(['timeout' => 12, 'connect_timeout' => 3], $sqs->getCommand('SendMessage')['@http']);
        $this->assertSame('test-key', $credentials->getAccessKeyId());
        $this->assertSame('test-secret', $credentials->getSecretKey());
        $this->assertSame('test-token', $credentials->getSecurityToken());
        $this->assertSame(self::QUEUE_URL, $queue->getQueue(null));
    }

    public function testConnectSendsPayloadInlineIfOverflowIsNotConfigured(): void
    {
        $this->assertPayloadIsSentInline([]);
    }

    public function testConnectSendsPayloadInlineIfOverflowIsNull(): void
    {
        $this->assertPayloadIsSentInline(['overflow' => null]);
    }

    private function assertPayloadIsSentInline(array $config): void
    {
        $commands = [];
        $connector = new SqsConnector();

        $queue = $connector->connect(array_merge([
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'queue' => self::QUEUE_URL,
            'handler' => function (CommandInterface $command) use (&$commands): PromiseInterface {
                $commands[] = $command;

                return new FulfilledPromise(new Result(['MessageId' => 'message-id', '@metadata' => ['statusCode' => 200]]));
            },
        ], $config));

        $this->assertSame('message-id', $queue->pushRaw('payload'));
        $this->assertCount(1, $commands);
        $this->assertSame('SendMessage', $commands[0]->getName());
        $this->assertSame(self::QUEUE_URL, $commands[0]['QueueUrl']);
        $this->assertSame('payload', $commands[0]['MessageBody']);
    }
}
