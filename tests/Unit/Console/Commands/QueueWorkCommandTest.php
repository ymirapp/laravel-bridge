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

namespace Ymir\Bridge\Laravel\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use Ymir\Bridge\Laravel\Console\Commands\QueueWorkCommand;
use Ymir\Bridge\Laravel\Queue\Worker;

class QueueWorkCommandTest extends TestCase
{
    public function testNormalizeMessageReturnsCorrectArray(): void
    {
        $command = new QueueWorkCommand(\Mockery::mock(Worker::class));

        $reflection = new \ReflectionClass(QueueWorkCommand::class);
        $method = $reflection->getMethod('normalizeMessage');
        $method->setAccessible(true);

        $message = [
            'messageId' => 'id',
            'receiptHandle' => 'handle',
            'body' => 'body',
            'attributes' => ['attr'],
            'messageAttributes' => ['msgAttr'],
        ];

        $expected = [
            'MessageId' => 'id',
            'ReceiptHandle' => 'handle',
            'Body' => 'body',
            'Attributes' => ['attr'],
            'MessageAttributes' => ['msgAttr'],
        ];

        $this->assertEquals($expected, $method->invoke($command, $message));
    }

    public function testResolveQueueUrlReturnsCorrectUrl(): void
    {
        $command = new QueueWorkCommand(\Mockery::mock(Worker::class));

        $reflection = new \ReflectionClass(QueueWorkCommand::class);
        $method = $reflection->getMethod('resolveQueueUrl');
        $method->setAccessible(true);

        $message = ['eventSourceARN' => 'arn:aws:sqs:us-east-1:123456789012:queue-name'];
        $expected = 'https://sqs.us-east-1.amazonaws.com/123456789012/queue-name';

        $this->assertEquals($expected, $method->invoke($command, $message));
    }

    public function testResolveQueueUrlReturnsNullIfArnIsInvalid(): void
    {
        $command = new QueueWorkCommand(\Mockery::mock(Worker::class));

        $reflection = new \ReflectionClass(QueueWorkCommand::class);
        $method = $reflection->getMethod('resolveQueueUrl');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($command, []));
        $this->assertNull($method->invoke($command, ['eventSourceARN' => 'invalid-arn']));
    }
}
