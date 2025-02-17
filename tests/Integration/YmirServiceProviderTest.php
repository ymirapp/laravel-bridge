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

use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase;
use Ymir\Bridge\Laravel\YmirServiceProvider;

/**
 * @covers \Ymir\Bridge\Laravel\YmirServiceProvider
 */
class YmirServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        putenv('AWS_ACCESS_KEY_ID=ACCESS_KEY');
        putenv('AWS_SESSION_TOKEN=SESSION_TOKEN');
        putenv('LAMBDA_TASK_ROOT=/var/task');
        putenv('YMIR_ASSETS_URL=https://assets.example.com');
        putenv('YMIR_CACHE_TABLE=cache');
        putenv('YMIR_ENVIRONMENT=testing');
    }

    public function testAddsAwsSessionTokenToDynamoDbCacheUsingLambdaAccessKey()
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

    public function testAddsAwsSessionTokenToSesWhenUsingLambdaAccessKey()
    {
        Config::set('services.ses', [
            'key' => 'ACCESS_KEY',
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('ACCESS_KEY', Config::get('services.ses.key'));
        $this->assertSame('SESSION_TOKEN', Config::get('services.ses.token'));
    }

    public function testAddsDynamoDbCacheConfigurationIfItsMissing()
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

    public function testChangesStoragePathWhenInYmirEnvironment()
    {
        $this->app->register(YmirServiceProvider::class);

        $storagePathProperty = new \ReflectionProperty($this->app, 'storagePath');
        $storagePathProperty->setAccessible(true);

        $this->assertSame('/tmp/storage', $storagePathProperty->getValue($this->app));
        $this->assertSame('/tmp/storage', $this->app['path.storage']);
        $this->assertSame('/tmp/storage/framework/views', Config::get('view.compiled'));
        $this->assertSame('/tmp/storage/framework/cache', Config::get('cache.stores.file.path'));
    }

    public function testConfiguresAssetUrlsWhenEnvironmentVariableIsSetAndConfigIsEmpty()
    {
        Config::set('app.asset_url', null);
        Config::set('app.mix_url', null);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('https://assets.example.com', Config::get('app.asset_url'));
        $this->assertSame('https://assets.example.com', Config::get('app.mix_url'));
    }

    public function testConfiguresTrustedProxiesWhenNoneAreConfigured()
    {
        Config::set('trustedproxy.proxies', null);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame(['0.0.0.0/0', '2000:0:0:0:0:0:0:0/3'], Config::get('trustedproxy.proxies'));
    }

    public function testDoesNotAddAwsSessionTokenToDynamoDbCacheNotUsingLambdaAccessKey()
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

    public function testDoesNotAddAwsSessionTokenToDynamoDbCacheWhenAccessKeyIsMissing()
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

    public function testDoesNotAddAwsSessionTokenToDynamoDbCacheWhenNotInYmirEnvironment()
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

    public function testDoesNotAddAwsSessionTokenToDynamoDbCacheWhenSessionTokenIsMissing()
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

    public function testDoesNotAddAwsSessionTokenToSesWhenAccessKeyIsMissing()
    {
        putenv('AWS_ACCESS_KEY_ID');

        Config::set('services.ses', [
            'key' => 'ACCESS_KEY',
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('ACCESS_KEY', Config::get('services.ses.key'));
        $this->assertNull(Config::get('services.ses.token'));
    }

    public function testDoesNotAddAwsSessionTokenToSesWhenNotInYmirEnvironment()
    {
        putenv('YMIR_ENVIRONMENT');
        Config::set('services.ses', [
            'key' => 'ACCESS_KEY',
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('ACCESS_KEY', Config::get('services.ses.key'));
        $this->assertNull(Config::get('services.ses.token'));
    }

    public function testDoesNotAddAwsSessionTokenToSesWhenNotUsingLambdaAccessKey()
    {
        Config::set('services.ses', [
            'key' => 'OTHER_KEY',
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('OTHER_KEY', Config::get('services.ses.key'));
        $this->assertNull(Config::get('services.ses.token'));
    }

    public function testDoesNotAddAwsSessionTokenToSesWhenSessionTokenIsMissing()
    {
        putenv('AWS_SESSION_TOKEN');

        Config::set('services.ses', [
            'key' => 'ACCESS_KEY',
        ]);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('ACCESS_KEY', Config::get('services.ses.key'));
        $this->assertNull(Config::get('services.ses.token'));
    }

    public function testDoesNotAddDynamoDbCacheConfigurationWhenDynamoDbCacheIsConfigured()
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

    public function testDoesNotAddDynamoDbCacheConfigurationWhenNotInYmirEnvironment()
    {
        putenv('YMIR_ENVIRONMENT');
        Config::set('cache.stores.dynamodb', []);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame([], Config::get('cache.stores.dynamodb'));
    }

    public function testDoesNotAddDynamoDbCacheConfigurationWhenTableEnvironmentVariableIsMissing()
    {
        putenv('YMIR_CACHE_TABLE');
        Config::set('cache.stores.dynamodb', []);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame([], Config::get('cache.stores.dynamodb'));
    }

    public function testDoesNotChangeStoragePathWhenNotInYmirEnvironment()
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

    public function testDoesNotConfigureAssetUrlsWhenEnvironmentVariableIsNotSetAndConfigIsEmpty()
    {
        putenv('YMIR_ASSETS_URL');
        Config::set('app.asset_url', null);
        Config::set('app.mix_url', null);

        $this->app->register(YmirServiceProvider::class);

        $this->assertNull(Config::get('app.asset_url'));
        $this->assertNull(Config::get('app.mix_url'));
    }

    public function testDoesNotConfigureAssetUrlsWhenEnvironmentVariableIsSetAndConfigIsNotEmpty()
    {
        Config::set('app.asset_url', 'https://other-assets.example.com');
        Config::set('app.mix_url', 'https://other-assets.example.com');

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('https://other-assets.example.com', Config::get('app.asset_url'));
        $this->assertSame('https://other-assets.example.com', Config::get('app.mix_url'));
    }

    public function testDoesNotConfigureAssetUrlsWhenNotInYmirEnvironment()
    {
        putenv('YMIR_ENVIRONMENT');
        Config::set('app.asset_url', null);
        Config::set('app.mix_url', null);

        $this->app->register(YmirServiceProvider::class);

        $this->assertNull(Config::get('app.asset_url'));
        $this->assertNull(Config::get('app.mix_url'));
    }

    public function testDoesNotConfigureTrustedProxiesWhenNotInYmirEnvironment()
    {
        putenv('YMIR_ENVIRONMENT');
        Config::set('trustedproxy.proxies', null);

        $this->app->register(YmirServiceProvider::class);

        $this->assertNull(Config::get('trustedproxy.proxies'));
    }

    public function testDoesNotConfigureTrustedProxiesWhenTheyAreConfigured()
    {
        Config::set('trustedproxy.proxies', ['0.0.0.0/0']);

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame(['0.0.0.0/0'], Config::get('trustedproxy.proxies'));
    }

    public function testDoesNotOverrideRedisClientConfigurationOptionsWhenNotInYmirEnvironment()
    {
        putenv('YMIR_ENVIRONMENT');
        Config::set('database.redis.client', 'not-relay');

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('not-relay', Config::get('database.redis.client'));
    }

    public function testDoesNotOverrideSessionDriverConfigurationOptionsWhenNotInYmirEnvironment()
    {
        putenv('YMIR_ENVIRONMENT');
        Config::set('session.driver', 'file');

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('file', Config::get('session.driver'));
    }

    public function testOverridesRedisClientConfigurationOptionsWhenInYmirEnvironment()
    {
        Config::set('database.redis.client', 'not-relay');

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('relay', Config::get('database.redis.client'));
    }

    public function testOverridesSessionDriverConfigurationOptionsWhenInYmirEnvironment()
    {
        Config::set('session.driver', 'file');

        $this->app->register(YmirServiceProvider::class);

        $this->assertSame('cookie', Config::get('session.driver'));
    }
}
