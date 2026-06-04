<?php

use App\Domain\Sync\Exceptions\StaleSyncFeedCheckpointException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(StaleSyncFeedCheckpointException::class);

        $exceptions->render(function (StaleSyncFeedCheckpointException $exception, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
                'meta' => [
                    'after_checkpoint' => $exception->afterCheckpoint(),
                    'oldest_available_checkpoint' => $exception->oldestAvailableCheckpoint(),
                    'domain' => $exception->domain(),
                    'resource_type' => $exception->resourceType(),
                    'resource_id' => $exception->resourceId(),
                    'required_action' => $exception->requiredAction(),
                ],
            ], 409);
        });
    })->create();
