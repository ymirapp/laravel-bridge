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

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class HealthEndpointLifecycleTest extends TestCase
{
    private const APPLICATION_PATH = __DIR__.'/../Fixtures/HealthApplication';

    private const HEALTH_CHECK = '1';

    private string $bootstrap = 'legacy';

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/ymir-health-'.uniqid();

        (new Filesystem())->ensureDirectoryExists($this->path.'/storage/framework/views');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->path);
    }

    public function testCachedConfigurationAndRoutesControlHealthEndpointWithLegacyBootstrap(): void
    {
        $this->assertCachedConfigurationAndRoutesControlHealthEndpoint();
    }

    public function testCachedConfigurationAndRoutesControlHealthEndpointWithModernBootstrap(): void
    {
        $this->useModernBootstrap();

        $this->assertCachedConfigurationAndRoutesControlHealthEndpoint();
    }

    public function testCachedNativeHealthRouteRunsPackageDiagnosticsOnlyWhenEnabled(): void
    {
        $this->useModernBootstrap();

        $this->cache(null);
        $this->assertResponse('/up', 200, 1);

        $this->cache(true);
        $this->assertResponse('/up', 200, 1);

        $this->cache(false);
        $this->assertResponse('/up', 200, 0);
    }

    public function testHealthEndpointAppliesLegacyApplicationMaintenancePolicy(): void
    {
        $this->artisan(['down'], true);

        $this->assertResponse('/ymir/health', 503, 0, true, null, self::HEALTH_CHECK);
    }

    public function testHealthEndpointAppliesModernApplicationMaintenancePolicy(): void
    {
        $this->useModernBootstrap();

        $this->artisan(['down'], true);

        $this->assertResponse('/ymir/health', 503, 0, true, null, self::HEALTH_CHECK);

        $nativeResponse = $this->request('/up', true);
        $disabledNativeResponse = $this->request('/up', false);

        $this->assertSame($disabledNativeResponse['status'], $nativeResponse['status']);
        $this->assertSame(200 === $nativeResponse['status'] ? 1 : 0, $nativeResponse['diagnostics']);
    }

    public function testHealthEndpointsReturnServerErrorWhenPackageDiagnosticFailsWithModernBootstrap(): void
    {
        $this->useModernBootstrap();

        $response = $this->request('/ymir/health', true, ['YMIR_FIXTURE_DIAGNOSTIC_FAILS' => '1'], self::HEALTH_CHECK);

        $this->assertSame(500, $response['status']);
        $this->assertSame('{"status":"down"}', $response['content']);

        $response = $this->request('/up', true, ['YMIR_FIXTURE_DIAGNOSTIC_FAILS' => '1']);

        $this->assertSame(500, $response['status']);
        $this->assertStringNotContainsString('secret-database-password', $response['content']);
    }

    private function artisan(array $arguments, ?bool $enabled): void
    {
        $process = $this->process(array_merge([self::APPLICATION_PATH.'/artisan'], $arguments), $enabled);

        $this->assertSame(0, $process->run(), $process->getOutput().$process->getErrorOutput());
    }

    private function assertCachedConfigurationAndRoutesControlHealthEndpoint(): void
    {
        $this->cache(null);
        $this->assertSame('{"status":"up"}', $this->assertResponse('/ymir/health', 200, 1, false, true, self::HEALTH_CHECK)['content']);
        $this->assertResponse('/ymir/health', 404, 0, false, true);
        $this->assertResponse('/ymir/health', 404, 0, false, true, '0');
        $this->assertResponse('https://admin.example.com/ymir/health', 302, 0, false, true, self::HEALTH_CHECK);

        $this->cache(true);
        $this->assertResponse('/ymir/health', 200, 1, false, true, self::HEALTH_CHECK);

        $this->cache(false);
        $this->assertResponse('/ymir/health', 404, 0, true, true, self::HEALTH_CHECK);

        $this->artisan(['config:clear'], false);
        $this->artisan(['route:clear'], false);
        $this->assertResponse('/ymir/health', 200, 1, null, false, self::HEALTH_CHECK);
        $this->assertResponse('/ymir/health', 404, 0, false, false, self::HEALTH_CHECK);
    }

    private function assertResponse(string $uri, int $status, int $diagnostics, ?bool $enabled = false, ?bool $cached = null, ?string $healthCheck = null): array
    {
        $response = $this->request($uri, $enabled, [], $healthCheck);

        $this->assertSame($status, $response['status']);
        $this->assertSame($diagnostics, $response['diagnostics']);

        if (null !== $cached) {
            $this->assertSame($cached, $response['configurationIsCached']);
            $this->assertSame($cached, $response['routesAreCached']);
        }

        return $response;
    }

    private function cache(?bool $enabled): void
    {
        $this->artisan(['config:cache'], $enabled);
        $this->artisan(['route:cache'], $enabled);
    }

    private function process(array $arguments, ?bool $enabled, array $environment = []): Process
    {
        return new Process(array_merge([PHP_BINARY], $arguments), null, array_merge([
            'APP_CONFIG_CACHE' => $this->path.'/config.php',
            'APP_PACKAGES_CACHE' => $this->path.'/packages.php',
            'APP_ROUTES_CACHE' => $this->path.'/routes.php',
            'APP_SERVICES_CACHE' => $this->path.'/services.php',
            'LAMBDA_TASK_ROOT' => false,
            'YMIR_ENVIRONMENT' => false,
            'YMIR_FIXTURE_BOOTSTRAP' => $this->bootstrap,
            'YMIR_FIXTURE_STORAGE_PATH' => $this->path.'/storage',
            'YMIR_HEALTH_ENABLED' => null !== $enabled ? var_export($enabled, true) : false,
        ], $environment));
    }

    private function request(string $uri, ?bool $enabled, array $environment = [], ?string $healthCheck = null): array
    {
        $process = $this->process(array_merge([self::APPLICATION_PATH.'/request.php', $uri], null !== $healthCheck ? [$healthCheck] : []), $enabled, $environment);

        $this->assertSame(0, $process->run(), $process->getOutput().$process->getErrorOutput());

        $response = json_decode($process->getOutput(), true);

        $this->assertIsArray($response, $process->getOutput().$process->getErrorOutput());

        return $response;
    }

    private function useModernBootstrap(): void
    {
        if (!class_exists(ApplicationBuilder::class)) {
            $this->markTestSkipped('The modern application bootstrap requires Laravel 11 or newer.');
        }

        $this->bootstrap = 'modern';
    }
}
