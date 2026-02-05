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

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Ymir\Bridge\Laravel\Console\Commands\QueueWorkCommand;
use Ymir\Bridge\Laravel\Queue\SqsConnector;
use Ymir\Bridge\Laravel\Queue\Worker;
use Ymir\Bridge\Monolog\Formatter\CloudWatchFormatter;

class YmirServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ymir.php' => config_path('ymir.php'),
            ], 'ymir-config');
        }

        if (!$this->runningOnYmir()) {
            return;
        }

        if ($this->app->resolved('queue')) {
            $this->addQueueConnector();
        } else {
            $this->app->afterResolving('queue', function (): void {
                $this->addQueueConnector();
            });
        }
    }

    /**
     * {@inheritdoc}
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ymir.php', 'ymir');

        if (!$this->runningOnYmir()) {
            return;
        }

        $this->ensureDynamoDbCacheIsConfigured();
        $this->changeStoragePath();
        $this->overrideConfigurationOptions();

        $this->configureAssetUrls();
        $this->configureLoggingRequestContext();
        $this->configureStderrLogging();
        $this->configureTrustedProxy();

        $this->registerCommands();
        $this->registerQueueWorker();

        // Run this last so that the AWS session token is set for all AWS services after we're done configuring them
        $this->addAwsSessionToken();
    }

    /**
     * Add the AWS session token to all AWS configuration using the Lambda access ID.
     */
    protected function addAwsSessionToken(): void
    {
        $credentials = $this->getAwsCredentials();

        if (empty($credentials['key']) || empty($credentials['token'])) {
            return;
        }

        collect((array) Config::get('cache.stores'))
            ->filter(fn ($store): bool => is_array($store) && isset($store['driver'], $store['key']) && 'dynamodb' === $store['driver'] && $credentials['key'] === $store['key'])
            ->each(function ($store, $name) use ($credentials): void {
                Config::set("cache.stores.{$name}.token", $credentials['token']);
            });

        collect((array) Config::get('filesystems.disks'))
            ->filter(fn ($disk): bool => is_array($disk) && isset($disk['driver'], $disk['key']) && 's3' === $disk['driver'] && $credentials['key'] === $disk['key'])
            ->each(function ($disk, $name) use ($credentials): void {
                Config::set("filesystems.disks.{$name}.token", $credentials['token']);
            });

        collect((array) Config::get('queue.connections'))
            ->filter(fn ($connection): bool => is_array($connection) && isset($connection['driver'], $connection['key']) && 'sqs' === $connection['driver'] && $credentials['key'] === $connection['key'])
            ->each(function ($connection, $name) use ($credentials): void {
                Config::set("queue.connections.{$name}.token", $credentials['token']);
            });

        $queueFailedConfig = Config::get('queue.failed');
        if (is_array($queueFailedConfig) && isset($queueFailedConfig['driver'], $queueFailedConfig['key']) && 'dynamodb' === $queueFailedConfig['driver'] && $credentials['key'] === $queueFailedConfig['key']) {
            Config::set('queue.failed.token', $credentials['token']);
        }

        if ($credentials['key'] === Config::get('services.ses.key')) {
            Config::set('services.ses.token', $credentials['token']);
        }
    }

    /**
     * Add the Ymir SQS queue connector.
     */
    protected function addQueueConnector(): void
    {
        Queue::extend('sqs', fn (): SqsConnector => new SqsConnector());
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
     * Configure logging the request context.
     */
    protected function configureLoggingRequestContext(): void
    {
        if (!Config::get('ymir.logging.request_context')) {
            return;
        }

        $this->app->rebinding('request', function (Application $app, Request $request): void {
            if (!$request->hasHeader('X-Request-ID')) {
                return;
            }

            $logManager = $app->make(LogManager::class);

            if (!is_object($logManager) || !method_exists($logManager, 'shareContext')) {
                return;
            }

            $logManager->shareContext([
                'requestId' => $request->header('X-Request-ID'),
            ]);
        });
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
     * Register the Ymir console commands.
     */
    protected function registerCommands(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            QueueWorkCommand::class,
        ]);
    }

    /**
     * Register the SQS queue worker.
     */
    protected function registerQueueWorker(): void
    {
        $this->app->singleton(Worker::class, fn (): Worker => new Worker($this->app['queue'], $this->app['events'], $this->app[ExceptionHandler::class], fn (): bool => $this->app->isDownForMaintenance()));
    }

    /**
     * Check if the application is running in a Ymir environment.
     */
    protected function runningOnYmir(): bool
    {
        return getenv('LAMBDA_TASK_ROOT') && getenv('YMIR_ENVIRONMENT');
    }
}
