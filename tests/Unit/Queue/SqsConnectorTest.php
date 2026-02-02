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

use PHPUnit\Framework\TestCase;
use Ymir\Bridge\Laravel\Queue\SqsConnector;
use Ymir\Bridge\Laravel\Queue\SqsQueue;

class SqsConnectorTest extends TestCase
{
    public function testConnectConfiguresCredentialsIfKeyAndSecretArePresent(): void
    {
        $connector = new SqsConnector();

        $config = [
            'region' => 'us-east-1',
            'queue' => 'test-queue',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'token' => 'test-token',
        ];

        $queue = $connector->connect($config);

        $reflection = new \ReflectionClass($queue);
        $sqsProperty = $reflection->getParentClass()->getProperty('sqs');
        $sqsProperty->setAccessible(true);
        $sqs = $sqsProperty->getValue($queue);

        $this->assertEquals('test-key', $sqs->getCredentials()->wait()->getAccessKeyId());
        $this->assertEquals('test-secret', $sqs->getCredentials()->wait()->getSecretKey());
        $this->assertEquals('test-token', $sqs->getCredentials()->wait()->getSecurityToken());
    }

    public function testConnectReturnsSqsQueueInstance(): void
    {
        $connector = new SqsConnector();

        $config = [
            'region' => 'us-east-1',
            'queue' => 'test-queue',
        ];

        $queue = $connector->connect($config);

        $this->assertInstanceOf(SqsQueue::class, $queue);
    }

    public function testConnectUsesDefaultConfiguration(): void
    {
        $connector = new SqsConnector();

        $config = [
            'region' => 'us-east-1',
            'queue' => 'test-queue',
        ];

        $queue = $connector->connect($config);

        $reflection = new \ReflectionClass($queue);
        $sqsProperty = $reflection->getParentClass()->getProperty('sqs');
        $sqsProperty->setAccessible(true);
        $sqs = $sqsProperty->getValue($queue);

        $this->assertEquals('2012-11-05', $sqs->getApi()->getApiVersion());
    }
}
