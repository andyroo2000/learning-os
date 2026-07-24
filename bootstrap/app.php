<?php

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Auth\Exceptions\InvalidCurrentPasswordException;
use App\Domain\Content\Exceptions\ContentGenerationCooldownException;
use App\Domain\Content\Exceptions\ContentGenerationQuotaExceededException;
use App\Domain\Japanese\Exceptions\WaniKaniApiException;
use App\Domain\Japanese\Exceptions\WaniKaniSyncInProgressException;
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
        $middleware->statefulApi();
        // Browser analytics is anonymous telemetry and sendBeacon cannot attach an XSRF header.
        $middleware->validateCsrfTokens(except: ['api/convolab/browser/tools/analytics']);
        $middleware->redirectGuestsTo(static fn (): null => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request, Throwable $exception): bool => $request->is('api/*')
                || $request->expectsJson(),
        );
        $exceptions->dontReport(AdminMutationException::class);
        $exceptions->dontReport(InvalidCurrentPasswordException::class);
        $exceptions->dontReport([
            ContentGenerationCooldownException::class,
            ContentGenerationQuotaExceededException::class,
        ]);
        $exceptions->dontReport(StaleSyncFeedCheckpointException::class);
        $exceptions->dontReport([WaniKaniApiException::class, WaniKaniSyncInProgressException::class]);

        $exceptions->render(function (WaniKaniApiException $exception, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json(['message' => $exception->getMessage()], $exception->getCode());
        });

        $exceptions->render(function (AdminMutationException $exception, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json(['message' => $exception->getMessage()], $exception->status());
        });

        $exceptions->render(function (InvalidCurrentPasswordException $exception, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'current_password' => [$exception->getMessage()],
                ],
            ], 422);
        });

        $exceptions->render(function (ContentGenerationCooldownException $exception, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'cooldown' => $exception->cooldown(),
            ], 429, ['Retry-After' => (string) $exception->remainingSeconds()]);
        });

        $exceptions->render(function (ContentGenerationQuotaExceededException $exception, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            $quota = $exception->quota();

            return response()->json([
                'message' => $exception->getMessage(),
                'quota' => $quota,
            ], 429, [
                'X-RateLimit-Limit' => (string) $quota['limit'],
                'X-RateLimit-Remaining' => (string) $quota['remaining'],
                'X-RateLimit-Reset' => $quota['resetsAt'],
            ]);
        });

        $exceptions->render(function (WaniKaniSyncInProgressException $exception, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json(['message' => $exception->getMessage()], 409);
        });

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
                    'operation' => $exception->operation(),
                    'required_action' => $exception->requiredAction(),
                ],
            ], 409);
        });
    })->create();
