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

namespace Ymir\Bridge\Laravel\Http\Action;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Events\DiagnosingHealth as LaravelDiagnosingHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ymir\Bridge\Laravel\Events\DiagnosingHealth;

class GetHealthAction
{
    /**
     * The server parameter that the Ymir runtime sets to "1" only for Ymir health check invocations.
     */
    private const HEALTH_CHECK_PARAMETER = 'YMIR_HEALTH_CHECK';

    /**
     * The event dispatcher.
     */
    private Dispatcher $dispatcher;

    /**
     * The exception handler.
     */
    private ExceptionHandler $exceptionHandler;

    /**
     * Constructor.
     */
    public function __construct(Dispatcher $dispatcher, ExceptionHandler $exceptionHandler)
    {
        $this->dispatcher = $dispatcher;
        $this->exceptionHandler = $exceptionHandler;
    }

    /**
     * Diagnose the application health of a Ymir health check without exposing diagnostic failure details.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if ('1' !== $request->server->get(self::HEALTH_CHECK_PARAMETER)) {
            throw new NotFoundHttpException();
        }

        $statusCode = JsonResponse::HTTP_OK;

        try {
            $this->dispatcher->dispatch(class_exists(LaravelDiagnosingHealth::class) ? new LaravelDiagnosingHealth() : new DiagnosingHealth());
        } catch (\Throwable $exception) {
            $this->report($exception);

            $statusCode = JsonResponse::HTTP_INTERNAL_SERVER_ERROR;
        }

        return new JsonResponse(['status' => JsonResponse::HTTP_OK === $statusCode ? 'up' : 'down'], $statusCode);
    }

    /**
     * Report the diagnostic exception without letting a reporting failure replace the generic response.
     */
    private function report(\Throwable $exception): void
    {
        try {
            $this->exceptionHandler->report($exception);
        } catch (\Throwable $reportingException) {
        }
    }
}
