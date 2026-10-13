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

namespace Ymir\Bridge\Laravel\Tests\Integration\Http\Action;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Events\DiagnosingHealth as LaravelDiagnosingHealth;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Ymir\Bridge\Laravel\Events\DiagnosingHealth;
use Ymir\Bridge\Laravel\Tests\TestCase;

class GetHealthActionTest extends TestCase
{
    public function testAppliesApplicationGlobalMiddleware(): void
    {
        $diagnostics = $this->countPackageDiagnostics();

        $this->app->make(Kernel::class)->pushMiddleware(fn (): Response => new Response('Unauthorized', 401));

        $this->getHealth()->assertStatus(401);

        $this->assertSame(0, $diagnostics->count);
    }

    public function testDispatchesNativeAndPackageEventsOnceWhenNativeEventIsAvailable(): void
    {
        if (!class_exists(LaravelDiagnosingHealth::class)) {
            $this->markTestSkipped('The DiagnosingHealth event requires Laravel 11 or newer.');
        }

        $nativeDiagnostics = 0;

        Event::listen(LaravelDiagnosingHealth::class, function () use (&$nativeDiagnostics): void {
            ++$nativeDiagnostics;
        });

        $diagnostics = $this->countPackageDiagnostics();

        $this->getHealth()->assertOk();

        $this->assertSame(1, $nativeDiagnostics);
        $this->assertSame(1, $diagnostics->count);
    }

    public function testDispatchesPackageEventOnceWhenNativeEventIsUnavailable(): void
    {
        if (class_exists(LaravelDiagnosingHealth::class)) {
            $this->markTestSkipped('The DiagnosingHealth event is available in this version of Laravel.');
        }

        $diagnostics = $this->countPackageDiagnostics();

        $this->getHealth()->assertOk();

        $this->assertSame(1, $diagnostics->count);
    }

    public function testReturnsDownWithoutExceptionDetailsWhenDiagnosticFails(): void
    {
        $exception = new \RuntimeException('secret-database-password');

        Config::set('app.debug', true);

        $this->mock(ExceptionHandler::class)->shouldReceive('report')->once()->with($exception);

        Event::listen(DiagnosingHealth::class, function () use ($exception): void {
            throw $exception;
        });

        $this->getHealth()
            ->assertStatus(500)
            ->assertExactJson(['status' => 'down'])
            ->assertDontSee('secret-database-password');
    }

    public function testReturnsDownWithoutExceptionDetailsWhenReportingFails(): void
    {
        Config::set('app.debug', true);

        $this->app->make(ExceptionHandler::class)->reportable(function (\RuntimeException $exception): void {
            throw new \LogicException('secret-reporter-failure');
        });

        Event::listen(DiagnosingHealth::class, function (): void {
            throw new \RuntimeException('secret-database-password');
        });

        $this->getHealth()
            ->assertStatus(500)
            ->assertExactJson(['status' => 'down'])
            ->assertDontSee('secret-database-password')
            ->assertDontSee('secret-reporter-failure');
    }

    public function testReturnsNotFoundForPublicRequestAfterHealthCheck(): void
    {
        $diagnostics = $this->countPackageDiagnostics();

        $this->getHealth()->assertOk();
        $this->getHealth([])->assertNotFound();
        $this->getHealth()->assertOk();

        $this->assertSame(2, $diagnostics->count);
    }

    public function testReturnsNotFoundWithoutDiagnosticsWhenHealthCheckHeaderIsSent(): void
    {
        $this->assertNotFoundWithoutDiagnostics(['HTTP_YMIR_HEALTH_CHECK' => '1']);
    }

    public function testReturnsNotFoundWithoutDiagnosticsWhenHealthCheckParameterIsMissing(): void
    {
        $this->assertNotFoundWithoutDiagnostics([]);
    }

    public function testReturnsNotFoundWithoutDiagnosticsWhenHealthCheckParameterIsNotOne(): void
    {
        $this->assertNotFoundWithoutDiagnostics(['YMIR_HEALTH_CHECK' => 'true']);
    }

    public function testReturnsNotFoundWithoutDiagnosticsWhenHealthCheckParameterIsZero(): void
    {
        $this->assertNotFoundWithoutDiagnostics(['YMIR_HEALTH_CHECK' => '0']);
    }

    public function testReturnsNotFoundWithoutDiagnosticsWhenHealthCheckQueryParameterIsSent(): void
    {
        $this->assertNotFoundWithoutDiagnostics([], '/ymir/health?YMIR_HEALTH_CHECK=1');
    }

    public function testReturnsUpWhenDiagnosticsSucceed(): void
    {
        $diagnostics = $this->countPackageDiagnostics();

        $this->getHealth()->assertOk()->assertExactJson(['status' => 'up']);

        $this->assertSame(1, $diagnostics->count);
    }

    private function assertNotFoundWithoutDiagnostics(array $server, string $uri = '/ymir/health'): void
    {
        $diagnostics = $this->countPackageDiagnostics();

        $this->call('GET', $uri, [], [], [], $server)->assertNotFound()->assertDontSee('"status"', false);

        $this->assertSame(0, $diagnostics->count);
    }

    private function countPackageDiagnostics(): \stdClass
    {
        $diagnostics = new \stdClass();
        $diagnostics->count = 0;

        Event::listen(DiagnosingHealth::class, function () use ($diagnostics): void {
            ++$diagnostics->count;
        });

        return $diagnostics;
    }

    private function getHealth(array $server = ['YMIR_HEALTH_CHECK' => '1']): TestResponse
    {
        return $this->call('GET', '/ymir/health', [], [], [], $server);
    }
}
