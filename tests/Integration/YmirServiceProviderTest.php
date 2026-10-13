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

namespace Ymir\Bridge\Laravel\Tests\Integration;

use Illuminate\Auth\GenericUser;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Foundation\Events\DiagnosingHealth as LaravelDiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Ymir\Bridge\Laravel\Console\Commands\QueueWorkCommand;
use Ymir\Bridge\Laravel\Events\DiagnosingHealth;
use Ymir\Bridge\Laravel\Queue\SqsQueue;
use Ymir\Bridge\Laravel\Queue\Worker;
use Ymir\Bridge\Laravel\YmirServiceProvider;
use Ymir\Bridge\Monolog\Formatter\CloudWatchFormatter;

class YmirServiceProviderTest extends TestCase
{
    private const ENVIRONMENT = [
        'AWS_ACCESS_KEY_ID' => 'ACCESS_KEY',
        'AWS_SESSION_TOKEN' => 'SESSION_TOKEN',
        'LAMBDA_TASK_ROOT' => '/var/task',
        'YMIR_ASSETS_URL' => 'https://assets.example.com',
        'YMIR_CACHE_TABLE' => 'cache',
        'YMIR_ENVIRONMENT' => 'testing',
    ];

    private const HEALTH_CHECK_SERVER_VARIABLES = ['YMIR_HEALTH_CHECK' => '1'];

    private array $previousEnvironment = [];

    private array $previousServerVariables = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENVIRONMENT as $name => $value) {
            $this->previousEnvironment[$name] = getenv($name);

            putenv(sprintf('%s=%s', $name, $value));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            putenv(false === $value ? $name : sprintf('%s=%s', $name, $value));
        }

        foreach ($this->previousServerVariables as $name => $value) {
            if (null === $value) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $value;
            }
        }

        parent::tearDown();
    }

    public function testAddsAwsSessionTokenToDynamoDbCacheUsingLambdaAccessKey(): void
    {
        Config::set('cache.stores', [
            'test' => [
                'driver' => 'dynamodb',
                'key' => 'ACCESS_KEY',
            ],
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('ACCESS_KEY', Config::get('cache.stores.test.key'));
        $this->assertSame('SESSION_TOKEN', Config::get('cache.stores.test.token'));
    }

    public function testAddsAwsSessionTokenToDynamoDbFailedQueueUsingLambdaAccessKey(): void
    {
        Config::set('queue.failed', [
            'driver' => 'dynamodb',
            'key' => 'ACCESS_KEY',
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('ACCESS_KEY', Config::get('queue.failed.key'));
        $this->assertSame('SESSION_TOKEN', Config::get('queue.failed.token'));
    }

    public function testAddsAwsSessionTokenToS3FilesystemDisksUsingLambdaAccessKey(): void
    {
        Config::set('filesystems.disks', [
            'test' => [
                'driver' => 's3',
                'key' => 'ACCESS_KEY',
            ],
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('ACCESS_KEY', Config::get('filesystems.disks.test.key'));
        $this->assertSame('SESSION_TOKEN', Config::get('filesystems.disks.test.token'));
    }

    public function testAddsAwsSessionTokenToSesWhenUsingLambdaAccessKey(): void
    {
        Config::set('services.ses', [
            'key' => 'ACCESS_KEY',
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('ACCESS_KEY', Config::get('services.ses.key'));
        $this->assertSame('SESSION_TOKEN', Config::get('services.ses.token'));
    }

    public function testAddsAwsSessionTokenToSqsQueueConnectionsUsingLambdaAccessKey(): void
    {
        Config::set('queue.connections', [
            'test' => [
                'driver' => 'sqs',
                'key' => 'ACCESS_KEY',
            ],
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('ACCESS_KEY', Config::get('queue.connections.test.key'));
        $this->assertSame('SESSION_TOKEN', Config::get('queue.connections.test.token'));
    }

    public function testAddsDynamoDbCacheConfigurationIfItsMissing(): void
    {
        Config::set('cache.stores.dynamodb', []);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame([
            'driver' => 'dynamodb',
            'table' => 'cache',
            'key' => 'ACCESS_KEY',
            'secret' => null,
            'token' => 'SESSION_TOKEN',
            'region' => 'us-east-1',
        ], Config::get('cache.stores.dynamodb'));
    }

    public function testChangesStoragePathWhenInYmirEnvironment(): void
    {
        $this->app->register(YmirServiceProvider::class);

        $storagePathProperty = new \ReflectionProperty($this->app, 'storagePath');
        $storagePathProperty->setAccessible(true);

        $this->assertSame('/tmp/storage', $storagePathProperty->getValue($this->app));
        $this->assertSame('/tmp/storage', $this->app['path.storage']);
        $this->assertSame('/tmp/storage/framework/views', Config::get('view.compiled'));
        $this->assertSame('/tmp/storage/framework/cache', Config::get('cache.stores.file.path'));
    }

    public function testConfiguresAssetUrlsWhenEnvironmentVariableIsSetAndConfigIsEmpty(): void
    {
        Config::set('app.asset_url', null);
        Config::set('app.mix_url', null);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('https://assets.example.com', Config::get('app.asset_url'));
        $this->assertSame('https://assets.example.com', Config::get('app.mix_url'));
    }

    public function testConfiguresLoggingRequestContextWhenEnabled(): void
    {
        if (!method_exists(LogManager::class, 'shareContext')) {
            $this->markTestSkipped('LogManager::shareContext is not available in this version of Laravel.');
        }

        Config::set('ymir.logging.request_context', true);

        $logManager = $this->mock(LogManager::class);
        $logManager->shouldReceive('shareContext')
            ->once()
            ->with(['requestId' => 'test-request-id']);

        $this->app->register(YmirServiceProvider::class);

        $request = Request::create('/', 'GET');
        $request->headers->set('X-Request-ID', 'test-request-id');

        $this->app->instance('request', $request);
    }

    public function testConfiguresLogsWhenFormatterClassDoesNotExist(): void
    {
        Config::set('logging.channels.stderr', ['driver' => 'stderr', 'formatter' => 'NonExistentClass']);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame(CloudWatchFormatter::class, Config::get('logging.channels.stderr.formatter'));
    }

    public function testConfiguresLogsWhenStderrChannelExistsAndNoFormatterIsConfigured(): void
    {
        Config::set('logging.channels.stderr', ['driver' => 'stderr']);
        Config::set('logging.channels.stderr.formatter', null);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame(CloudWatchFormatter::class, Config::get('logging.channels.stderr.formatter'));
    }

    public function testConfiguresTrustedProxiesWhenNoneAreConfigured(): void
    {
        Config::set('trustedproxy.proxies', null);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame(['0.0.0.0/0', '2000:0:0:0:0:0:0:0/3'], Config::get('trustedproxy.proxies'));
    }

    public function testDoesNotAddAwsSessionTokenToDynamoDbCacheNotUsingLambdaAccessKey(): void
    {
        Config::set('cache.stores', [
            'test' => [
                'driver' => 'dynamodb',
                'key' => 'OTHER_KEY',
            ],
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('OTHER_KEY', Config::get('cache.stores.test.key'));
        $this->assertNull(Config::get('cache.stores.test.token'));
    }

    public function testDoesNotAddAwsSessionTokenToDynamoDbCacheWhenAccessKeyIsMissing(): void
    {
        putenv('AWS_ACCESS_KEY_ID');

        Config::set('cache.stores', [
            'test' => [
                'driver' => 'dynamodb',
                'key' => 'ACCESS_KEY',
            ],
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('ACCESS_KEY', Config::get('cache.stores.test.key'));
        $this->assertNull(Config::get('cache.stores.test.token'));
    }

    public function testDoesNotAddAwsSessionTokenToDynamoDbCacheWhenNotInYmirEnvironment(): void
    {
        putenv('YMIR_ENVIRONMENT');
        Config::set('cache.stores', [
            'test' => [
                'driver' => 'dynamodb',
                'key' => 'ACCESS_KEY',
            ],
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('ACCESS_KEY', Config::get('cache.stores.test.key'));
        $this->assertNull(Config::get('cache.stores.test.token'));
    }

    public function testDoesNotAddAwsSessionTokenToDynamoDbCacheWhenSessionTokenIsMissing(): void
    {
        putenv('AWS_SESSION_TOKEN');

        Config::set('cache.stores', [
            'test' => [
                'driver' => 'dynamodb',
                'key' => 'ACCESS_KEY',
            ],
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('ACCESS_KEY', Config::get('cache.stores.test.key'));
        $this->assertNull(Config::get('cache.stores.test.token'));
    }

    public function testDoesNotAddAwsSessionTokenToDynamoDbFailedQueueNotUsingLambdaAccessKey(): void
    {
        Config::set('queue.failed', [
            'driver' => 'dynamodb',
            'key' => 'OTHER_KEY',
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('OTHER_KEY', Config::get('queue.failed.key'));
        $this->assertNull(Config::get('queue.failed.token'));
    }

    public function testDoesNotAddAwsSessionTokenToS3FilesystemDisksNotUsingLambdaAccessKey(): void
    {
        Config::set('filesystems.disks', [
            'test' => [
                'driver' => 's3',
                'key' => 'OTHER_KEY',
            ],
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('OTHER_KEY', Config::get('filesystems.disks.test.key'));
        $this->assertNull(Config::get('filesystems.disks.test.token'));
    }

    public function testDoesNotAddAwsSessionTokenToSesWhenAccessKeyIsMissing(): void
    {
        putenv('AWS_ACCESS_KEY_ID');

        Config::set('services.ses', [
            'key' => 'ACCESS_KEY',
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('ACCESS_KEY', Config::get('services.ses.key'));
        $this->assertNull(Config::get('services.ses.token'));
    }

    public function testDoesNotAddAwsSessionTokenToSesWhenNotInYmirEnvironment(): void
    {
        putenv('YMIR_ENVIRONMENT');
        Config::set('services.ses', [
            'key' => 'ACCESS_KEY',
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('ACCESS_KEY', Config::get('services.ses.key'));
        $this->assertNull(Config::get('services.ses.token'));
    }

    public function testDoesNotAddAwsSessionTokenToSesWhenNotUsingLambdaAccessKey(): void
    {
        Config::set('services.ses', [
            'key' => 'OTHER_KEY',
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('OTHER_KEY', Config::get('services.ses.key'));
        $this->assertNull(Config::get('services.ses.token'));
    }

    public function testDoesNotAddAwsSessionTokenToSesWhenSessionTokenIsMissing(): void
    {
        putenv('AWS_SESSION_TOKEN');

        Config::set('services.ses', [
            'key' => 'ACCESS_KEY',
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('ACCESS_KEY', Config::get('services.ses.key'));
        $this->assertNull(Config::get('services.ses.token'));
    }

    public function testDoesNotAddAwsSessionTokenToSqsQueueConnectionsNotUsingLambdaAccessKey(): void
    {
        Config::set('queue.connections', [
            'test' => [
                'driver' => 'sqs',
                'key' => 'OTHER_KEY',
            ],
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('OTHER_KEY', Config::get('queue.connections.test.key'));
        $this->assertNull(Config::get('queue.connections.test.token'));
    }

    public function testDoesNotAddDynamoDbCacheConfigurationWhenDynamoDbCacheIsConfigured(): void
    {
        Config::set('cache.stores.dynamodb', [
            'driver' => 'dynamodb',
            'table' => 'other_cache',
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame([
            'driver' => 'dynamodb',
            'table' => 'other_cache',
        ], Config::get('cache.stores.dynamodb'));
    }

    public function testDoesNotAddDynamoDbCacheConfigurationWhenNotInYmirEnvironment(): void
    {
        putenv('YMIR_ENVIRONMENT');
        Config::set('cache.stores.dynamodb', []);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame([], Config::get('cache.stores.dynamodb'));
    }

    public function testDoesNotAddDynamoDbCacheConfigurationWhenTableEnvironmentVariableIsMissing(): void
    {
        putenv('YMIR_CACHE_TABLE');
        Config::set('cache.stores.dynamodb', []);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame([], Config::get('cache.stores.dynamodb'));
    }

    public function testDoesNotChangeStoragePathWhenNotInYmirEnvironment(): void
    {
        putenv('YMIR_ENVIRONMENT');

        $this->app->register(YmirServiceProvider::class);

        $storagePathProperty = new \ReflectionProperty($this->app, 'storagePath');
        $storagePathProperty->setAccessible(true);

        $this->assertNull($storagePathProperty->getValue($this->app));
        $this->assertNotSame('/tmp/storage', $this->app['path.storage']);
        $this->assertNotSame('/tmp/storage/framework/views', Config::get('view.compiled'));
        $this->assertNotSame('/tmp/storage/framework/cache', Config::get('cache.stores.file.path'));
    }

    public function testDoesNotConfigureAssetUrlsWhenEnvironmentVariableIsNotSetAndConfigIsEmpty(): void
    {
        putenv('YMIR_ASSETS_URL');
        Config::set('app.asset_url', null);
        Config::set('app.mix_url', null);

        $this->app->register(YmirServiceProvider::class);

        $this->assertNull(Config::get('app.asset_url'));
        $this->assertNull(Config::get('app.mix_url'));
    }

    public function testDoesNotConfigureAssetUrlsWhenEnvironmentVariableIsSetAndConfigIsNotEmpty(): void
    {
        Config::set('app.asset_url', 'https://other-assets.example.com');
        Config::set('app.mix_url', 'https://other-assets.example.com');

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('https://other-assets.example.com', Config::get('app.asset_url'));
        $this->assertSame('https://other-assets.example.com', Config::get('app.mix_url'));
    }

    public function testDoesNotConfigureAssetUrlsWhenNotInYmirEnvironment(): void
    {
        putenv('YMIR_ENVIRONMENT');
        Config::set('app.asset_url', null);
        Config::set('app.mix_url', null);

        $this->app->register(YmirServiceProvider::class);

        $this->assertNull(Config::get('app.asset_url'));
        $this->assertNull(Config::get('app.mix_url'));
    }

    public function testDoesNotConfigureLoggingRequestContextWhenDisabled(): void
    {
        if (!method_exists(LogManager::class, 'shareContext')) {
            $this->markTestSkipped('LogManager::shareContext is not available in this version of Laravel.');
        }

        Config::set('ymir.logging.request_context', false);

        $logManager = $this->mock(LogManager::class);
        $logManager->shouldNotReceive('shareContext');

        $this->app->register(YmirServiceProvider::class);

        $request = Request::create('/', 'GET');
        $request->headers->set('X-Request-ID', 'test-request-id');

        $this->app->instance('request', $request);
    }

    public function testDoesNotConfigureLogsWhenFormatterIsAlreadyConfigured(): void
    {
        Config::set('logging.channels.stderr', ['driver' => 'stderr', 'formatter' => 'stdClass']);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('stdClass', Config::get('logging.channels.stderr.formatter'));
    }

    public function testDoesNotConfigureLogsWhenNotInYmirEnvironment(): void
    {
        putenv('YMIR_ENVIRONMENT');
        Config::set('logging.channels.stderr', ['driver' => 'stderr']);

        $this->app->register(YmirServiceProvider::class);

        $this->assertNull(Config::get('logging.channels.stderr.formatter'));
    }

    public function testDoesNotConfigureLogsWhenStderrChannelIsMissing(): void
    {
        Config::set('logging.channels', []);

        $this->app->register(YmirServiceProvider::class);

        $this->assertNull(Config::get('logging.channels.stderr.formatter'));
    }

    public function testDoesNotConfigureTrustedProxiesWhenNotInYmirEnvironment(): void
    {
        putenv('YMIR_ENVIRONMENT');
        Config::set('trustedproxy.proxies', null);

        $this->app->register(YmirServiceProvider::class);

        $this->assertNull(Config::get('trustedproxy.proxies'));
    }

    public function testDoesNotConfigureTrustedProxiesWhenTheyAreConfigured(): void
    {
        Config::set('trustedproxy.proxies', ['0.0.0.0/0']);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame(['0.0.0.0/0'], Config::get('trustedproxy.proxies'));
    }

    public function testDoesNotForwardNativeDiagnosingHealthWhenHealthEndpointIsDisabled(): void
    {
        if (!class_exists(LaravelDiagnosingHealth::class)) {
            $this->markTestSkipped('The DiagnosingHealth event requires Laravel 11 or newer.');
        }

        Config::set('ymir.health.enabled', false);

        $diagnostics = 0;

        Event::listen(DiagnosingHealth::class, function () use (&$diagnostics): void {
            ++$diagnostics;
        });

        $this->app->register(YmirServiceProvider::class);

        Event::dispatch(new LaravelDiagnosingHealth());

        $this->assertSame(0, $diagnostics);
    }

    public function testDoesNotOverrideRedisClientConfigurationOptionsWhenNotInYmirEnvironment(): void
    {
        putenv('YMIR_ENVIRONMENT');
        Config::set('database.redis.client', 'not-relay');

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('not-relay', Config::get('database.redis.client'));
    }

    public function testDoesNotOverrideSessionDriverConfigurationOptionsWhenNotInYmirEnvironment(): void
    {
        putenv('YMIR_ENVIRONMENT');
        Config::set('session.driver', 'file');

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('file', Config::get('session.driver'));
    }

    public function testDoesNotRegisterHealthEndpointWhenDisabled(): void
    {
        Config::set('ymir.health.enabled', false);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame(0, $this->countHealthEndpointRoutes());
        $this->get('/ymir/health')->assertNotFound();
    }

    public function testDoesNotRegisterHealthEndpointWhenDisabledByEnvironmentVariable(): void
    {
        $this->setServerVariable('YMIR_HEALTH_ENABLED', 'false');

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame(0, $this->countHealthEndpointRoutes());
    }

    public function testDoesNotRegisterHealthEndpointWhenEnabledOptionIsFalseString(): void
    {
        Config::set('ymir.health.enabled', 'false');

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame(0, $this->countHealthEndpointRoutes());
    }

    public function testDoesNotRegisterHealthEndpointWhenEnabledOptionIsOffString(): void
    {
        Config::set('ymir.health.enabled', 'off');

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame(0, $this->countHealthEndpointRoutes());
        $this->get('/ymir/health')->assertNotFound();
    }

    public function testDoesNotRegisterQueueWorkCommandWhenNotInYmirEnvironment(): void
    {
        putenv('YMIR_ENVIRONMENT');

        $this->app->register(YmirServiceProvider::class);

        $this->assertArrayNotHasKey('ymir:queue:work', Artisan::all());
    }

    public function testDoesNotRegisterQueueWorkerWhenNotInYmirEnvironment(): void
    {
        putenv('YMIR_ENVIRONMENT');

        $this->app->register(YmirServiceProvider::class);

        $this->assertFalse($this->app->bound(Worker::class));
    }

    public function testDoesNotReplaceApplicationDomainHealthEndpointRouteRegisteredBeforeProvider(): void
    {
        Config::set('ymir.health.enabled', true);

        $this->registerApplicationDomainHealthEndpointRoute();

        $this->app->register(YmirServiceProvider::class);

        $this->assertApplicationDomainHealthEndpointRouteIsPreserved();
    }

    public function testDoesNotReplaceApplicationHealthEndpointRouteRegisteredBeforeProvider(): void
    {
        Config::set('ymir.health.enabled', true);

        Route::get('ymir/health', fn (): string => 'application');

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame(1, $this->countHealthEndpointRoutes());
        $this->get('/ymir/health')->assertOk()->assertSeeText('application');
    }

    public function testDoesNotShareRequestContextWhenHeaderIsMissing(): void
    {
        if (!method_exists(LogManager::class, 'shareContext')) {
            $this->markTestSkipped('LogManager::shareContext is not available in this version of Laravel.');
        }

        Config::set('ymir.logging.request_context', true);

        $logManager = $this->mock(LogManager::class);
        $logManager->shouldNotReceive('shareContext');

        $this->app->register(YmirServiceProvider::class);

        $request = Request::create('/', 'GET');

        $this->app->instance('request', $request);
    }

    public function testDoesNotShareRequestContextWhenShareContextMethodIsMissing(): void
    {
        $this->expectNotToPerformAssertions();

        Config::set('ymir.logging.request_context', true);

        $this->app->instance(LogManager::class, new \stdClass());

        $this->app->register(YmirServiceProvider::class);

        $request = Request::create('/', 'GET');
        $request->headers->set('X-Request-ID', 'test-request-id');

        $this->app->instance('request', $request);
    }

    public function testForwardsNativeDiagnosingHealthToPackageEventOnceWhenHealthEndpointIsEnabled(): void
    {
        if (!class_exists(LaravelDiagnosingHealth::class)) {
            $this->markTestSkipped('The DiagnosingHealth event requires Laravel 11 or newer.');
        }

        Config::set('ymir.health.enabled', true);

        $diagnostics = 0;

        Event::listen(DiagnosingHealth::class, function () use (&$diagnostics): void {
            ++$diagnostics;
        });

        $this->app->register(YmirServiceProvider::class);

        Event::dispatch(new LaravelDiagnosingHealth());

        $this->assertSame(1, $diagnostics);
    }

    public function testOverridesRedisClientConfigurationOptionsWhenInYmirEnvironment(): void
    {
        Config::set('database.redis.client', 'not-relay');

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('relay', Config::get('database.redis.client'));
    }

    public function testOverridesSessionDriverConfigurationOptionsWhenInYmirEnvironment(): void
    {
        Config::set('session.driver', 'file');

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('cookie', Config::get('session.driver'));
    }

    public function testPreservesApplicationDomainHealthEndpointRouteRegisteredAfterProvider(): void
    {
        Config::set('ymir.health.enabled', true);

        $this->app->register(YmirServiceProvider::class);

        $this->registerApplicationDomainHealthEndpointRoute();

        $this->assertApplicationDomainHealthEndpointRouteIsPreserved();
    }

    public function testPreservesApplicationHealthEndpointRouteRegisteredAfterProvider(): void
    {
        Config::set('ymir.health.enabled', true);

        $this->app->register(YmirServiceProvider::class);

        Route::get('ymir/health', fn (): string => 'application');

        $this->assertSame(1, $this->countHealthEndpointRoutes());
        $this->get('/ymir/health')->assertOk()->assertSeeText('application');
    }

    public function testRegistersHealthEndpointAndDiagnosticsOnceWhenProviderIsRegisteredTwice(): void
    {
        Config::set('ymir.health.enabled', true);

        $diagnostics = 0;

        Event::listen(DiagnosingHealth::class, function () use (&$diagnostics): void {
            ++$diagnostics;
        });

        $this->app->register(YmirServiceProvider::class);
        $this->app->register(YmirServiceProvider::class);

        $this->withServerVariables(self::HEALTH_CHECK_SERVER_VARIABLES)->get('/ymir/health')->assertOk();

        $this->assertSame(1, $this->countHealthEndpointRoutes());
        $this->assertSame(1, $diagnostics);
    }

    public function testRegistersHealthEndpointByDefault(): void
    {
        $this->app->register(YmirServiceProvider::class);

        $this->assertSame(1, $this->countHealthEndpointRoutes());
        $this->get('/ymir/health')->assertNotFound();
        $this->withServerVariables(self::HEALTH_CHECK_SERVER_VARIABLES)->get('/ymir/health')->assertOk()->assertExactJson(['status' => 'up']);
    }

    public function testRegistersHealthEndpointWhenEnabledOptionIsYesString(): void
    {
        Config::set('ymir.health.enabled', 'yes');

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame(1, $this->countHealthEndpointRoutes());
        $this->withServerVariables(self::HEALTH_CHECK_SERVER_VARIABLES)->get('/ymir/health')->assertOk();
    }

    public function testRegistersHealthEndpointWhenEnabledOutsideYmirEnvironment(): void
    {
        putenv('YMIR_ENVIRONMENT');
        Config::set('ymir.health.enabled', true);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame(1, $this->countHealthEndpointRoutes());
        $this->withServerVariables(self::HEALTH_CHECK_SERVER_VARIABLES)->get('/ymir/health')->assertOk()->assertExactJson(['status' => 'up']);
    }

    public function testRegistersQueueWorkCommandWhenInYmirEnvironment(): void
    {
        $this->app->register(YmirServiceProvider::class);

        $this->assertArrayHasKey('ymir:queue:work', Artisan::all());
        $this->assertInstanceOf(QueueWorkCommand::class, Artisan::all()['ymir:queue:work']);
    }

    public function testRegistersQueueWorkerWhenInYmirEnvironment(): void
    {
        $this->app->register(YmirServiceProvider::class);

        $this->assertTrue($this->app->bound(Worker::class));
        $this->assertInstanceOf(Worker::class, $this->app->make(Worker::class));
    }

    public function testRejectsHealthEndpointEnabledOptionWhenValueIsAnArray(): void
    {
        $this->assertInvalidHealthEndpointEnabledOptionThrowsException([true]);
    }

    public function testRejectsHealthEndpointEnabledOptionWhenValueIsInvalidString(): void
    {
        $this->assertInvalidHealthEndpointEnabledOptionThrowsException('enabled');
    }

    public function testSqsQueueConnectionDoesNotUseYmirSqsQueueWhenNotInYmirEnvironment(): void
    {
        putenv('YMIR_ENVIRONMENT');

        Config::set('queue.connections.sqs', [
            'driver' => 'sqs',
            'key' => 'key',
            'secret' => 'secret',
            'queue' => 'https://sqs.us-east-1.amazonaws.com/123456789012/queue',
            'region' => 'us-east-1',
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertNotInstanceOf(SqsQueue::class, Queue::connection('sqs'));
    }

    public function testSqsQueueConnectionUsesYmirSqsQueueWhenInYmirEnvironment(): void
    {
        Config::set('queue.connections.sqs', [
            'driver' => 'sqs',
            'key' => 'key',
            'secret' => 'secret',
            'queue' => 'https://sqs.us-east-1.amazonaws.com/123456789012/queue',
            'region' => 'us-east-1',
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertInstanceOf(SqsQueue::class, Queue::connection('sqs'));
    }

    private function assertApplicationDomainHealthEndpointRouteIsPreserved(): void
    {
        $this->withServerVariables(self::HEALTH_CHECK_SERVER_VARIABLES);

        $this->get('http://admin.example.com/ymir/health')->assertStatus(401);
        $this->get('http://www.example.com/ymir/health')->assertOk()->assertExactJson(['status' => 'up']);

        $this->actingAs(new GenericUser(['id' => 1]));

        $this->get('http://admin.example.com/ymir/health')->assertOk()->assertSeeText('application');
    }

    private function assertInvalidHealthEndpointEnabledOptionThrowsException($enabled): void
    {
        Config::set('ymir.health.enabled', $enabled);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The "ymir.health.enabled" configuration option must be a boolean value');

        $this->app->register(YmirServiceProvider::class);
    }

    private function countHealthEndpointRoutes(): int
    {
        return collect($this->app->make('router')->getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $route): bool => 'ymir/health' === $route->uri())
            ->count();
    }

    private function registerApplicationDomainHealthEndpointRoute(): void
    {
        Route::domain('admin.example.com')
            ->get('ymir/health', fn (): string => 'application')
            ->middleware(AuthenticateWithBasicAuth::class);
    }

    private function setServerVariable(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->previousServerVariables)) {
            $this->previousServerVariables[$name] = $_SERVER[$name] ?? null;
        }

        $_SERVER[$name] = $value;
    }
}
