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

use Aws\Sqs\SqsClient;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Support\Arr;

class SqsConnector implements ConnectorInterface
{
    private const AFTER_COMMIT_KEY = 'after_commit';
    private const CREDENTIAL_KEYS = ['key', 'secret', 'token'];
    private const OVERFLOW_KEY = 'overflow';
    private const PREFIX_KEY = 'prefix';
    private const QUEUE_KEY = 'queue';
    private const QUEUE_KEYS = ['driver', self::QUEUE_KEY, self::PREFIX_KEY, self::SUFFIX_KEY, self::AFTER_COMMIT_KEY, self::OVERFLOW_KEY, 'credential_cache'];
    private const SUFFIX_KEY = 'suffix';

    /**
     * {@inheritdoc}
     */
    public function connect(array $config): SqsQueue
    {
        $config = $this->getDefaultConfiguration($config);
        $overflowStorage = $config[self::OVERFLOW_KEY] ?? [];

        if (!is_array($overflowStorage)) {
            throw new \InvalidArgumentException('The SQS "overflow" configuration must be an array');
        }

        if (!empty($config['key']) && !empty($config['secret'])) {
            $config['credentials'] = Arr::only($config, self::CREDENTIAL_KEYS);
        }

        $sqsConfig = Arr::except($config, array_merge(self::CREDENTIAL_KEYS, self::QUEUE_KEYS));

        return new SqsQueue(
            new SqsClient($sqsConfig), $config[self::QUEUE_KEY], $config[self::PREFIX_KEY] ?? '', $config[self::SUFFIX_KEY] ?? '', $config[self::AFTER_COMMIT_KEY] ?? null, $overflowStorage
        );
    }

    /**
     * Get the default configuration for SQS.
     */
    protected function getDefaultConfiguration(array $config): array
    {
        return array_merge([
            'version' => '2012-11-05',
            'http' => [
                'timeout' => 60,
                'connect_timeout' => 60,
            ],
        ], $config);
    }
}
