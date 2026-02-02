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
    private const CREDENTIAL_KEYS = ['key', 'secret', 'token'];

    /**
     * {@inheritdoc}
     */
    public function connect(array $config): SqsQueue
    {
        $config = $this->getDefaultConfiguration($config);

        if (!empty($config['key']) && !empty($config['secret'])) {
            $config['credentials'] = Arr::only($config, self::CREDENTIAL_KEYS);
        }

        return new SqsQueue(
            new SqsClient(Arr::except($config, self::CREDENTIAL_KEYS)), $config['queue'], $config['prefix'] ?? '', $config['suffix'] ?? '', $config['after_commit'] ?? null
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
