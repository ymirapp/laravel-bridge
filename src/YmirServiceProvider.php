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

namespace Ymir\Bridge\Laravel;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Ymir\Bridge\Monolog\Formatter\CloudWatchFormatter;

class YmirServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(): void
    {
        if (!$this->runningOnYmir()) {
            return;
        }

        $this->ensureDynamoDbCacheIsConfigured();
        $this->changeStoragePath();
        $this->overrideConfigurationOptions();

        $this->configureAssetUrls();
        $this->configureStderrLogging();
        $this->configureTrustedProxy();

        // Run this last so that the AWS session token is set for all AWS services after we're done configuring them
        $this->addAwsSessionToken();
    }

    /**
     * Add the AWS session token to all AWS configuration using the Lambda access ID.
     */
    protected function addAwsSessionToken(): void
    {
        $credentials = $this->getAwsCredentials();
        $stores = Config::get('cache.stores');

        if (empty($credentials['key']) || empty($credentials['token']) || !is_array($stores)) {
            return;
        }

        collect($stores)
            ->filter(fn ($store): bool => is_array($store) && isset($store['driver'], $store['key']) && 'dynamodb' === $store['driver'] && $credentials['key'] === $store['key'])
            ->each(function ($store, $name) use ($credentials): void {
                Config::set("cache.stores.{$name}.token", $credentials['token']);
            });

        if ($credentials['key'] === Config::get('services.ses.key')) {
            Config::set('services.ses.token', $credentials['token']);
        }
    }

    /**
     * Change the storage path for the application.
     */
    protected function changeStoragePath(): void
    {
        if (!method_exists($this->app, 'useStoragePath')) {
            return;
        }

        $this->app->useStoragePath(StorageDirectories::PATH);

        Config::set('view.compiled', StorageDirectories::PATH.'/framework/views');
        Config::set('cache.stores.file.path', StorageDirectories::PATH.'/framework/cache');
    }

    /**
     * Configure Laravel asset URLs to point to Ymir asset storage.
     */
    protected function configureAssetUrls(): void
    {
        $assetUrl = getenv('YMIR_ASSETS_URL');

        if (empty($assetUrl) || !is_string($assetUrl)) {
            return;
        }

        if (!Config::get('app.asset_url')) {
            Config::set('app.asset_url', $assetUrl);
        }

        if (!Config::get('app.mix_url')) {
            Config::set('app.mix_url', $assetUrl);
        }
    }

    /**
     * Configure STDERR logging channel.
     */
    protected function configureStderrLogging(): void
    {
        if (!Config::has('logging.channels.stderr')) {
            return;
        }

        $stderrFormatter = Config::get('logging.channels.stderr.formatter');

        if (is_string($stderrFormatter) && class_exists($stderrFormatter)) {
            return;
        }

        Config::set('logging.channels.stderr.formatter', CloudWatchFormatter::class);
    }

    /**
     * Configure trusted proxy.
     */
    protected function configureTrustedProxy(): void
    {
        Config::set('trustedproxy.proxies', Config::get('trustedproxy.proxies') ?? ['0.0.0.0/0', '2000:0:0:0:0:0:0:0/3']);
    }

    /**
     * Ensure that we configured the DynamoDB cache.
     */
    protected function ensureDynamoDbCacheIsConfigured(): void
    {
        $table = getenv('YMIR_CACHE_TABLE');

        if (!is_string($table) || empty($table)) {
            return;
        }

        if (!Config::get('cache.stores.dynamodb')) {
            Config::set('cache.stores.dynamodb', array_merge([
                'driver' => 'dynamodb',
                'table' => $table,
            ], $this->getAwsCredentials()));
        }
    }

    /**
     * Get the Lambda execution role AWS credentials.
     *
     * @return array<string, string|null>
     */
    protected function getAwsCredentials(): array
    {
        return [
            'key' => getenv('AWS_ACCESS_KEY_ID') ?: null,
            'secret' => getenv('AWS_SECRET_ACCESS_KEY') ?: null,
            'token' => getenv('AWS_SESSION_TOKEN') ?: null,
            'region' => getenv('AWS_REGION') ?: 'us-east-1',
        ];
    }

    /**
     * Override Laravel configuration options that don't work with Ymir.
     */
    protected function overrideConfigurationOptions(): void
    {
        if ('relay' !== Config::get('database.redis.client')) {
            Config::set('database.redis.client', 'relay');
        }

        if ('file' === Config::get('session.driver')) {
            Config::set('session.driver', 'cookie');
        }
    }

    /**
     * Check if the application is running in a Ymir environment.
     */
    protected function runningOnYmir(): bool
    {
        return getenv('LAMBDA_TASK_ROOT') && getenv('YMIR_ENVIRONMENT');
    }
}
