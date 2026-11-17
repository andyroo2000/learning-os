<?php

namespace App\Support\Usage;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ProviderUsage
{
    public static function request(string $provider, string $model, array $units, Closure $request): Response
    {
        $started = microtime(true);
        $response = null;
        try {
            return $response = $request();
        } finally {
            self::record(compact('provider', 'model', 'units'), $response, $started);
        }
    }

    private static function record(array $request, ?Response $response, float $started): void
    {
        $scope = Context::getHidden('ai_usage');
        if ($scope === null) {
            return; // Other products keep their existing behavior until explicitly instrumented.
        }
        ['provider' => $provider, 'model' => $model, 'units' => $units] = $request;
        $units = [...$units, ...self::tokenUnits($provider, $response)];
        $pricing = UsagePricing::estimate($provider, $model, $units);
        if ($response === null || ! $response->successful()) {
            $pricing['estimated_micro_usd'] = null; // A timeout is unknown, never assumed free.
        }
        $record = [...$scope, ...$units, ...$pricing,
            'id' => strtolower((string) Str::ulid()), 'provider' => $provider, 'model' => $model,
            'provider_request_id' => $response?->header('x-request-id') ?: null,
            'http_status' => $response?->status(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'created_at' => now('UTC'),
        ];
        try {
            DB::table('ai_usage_events')->insert($record);
        } catch (Throwable) {
            // Accounting must not discard a paid result; retain only content-free metrics for recovery.
            Log::warning('AI usage persistence failed', $record);
        }
    }

    private static function tokenUnits(string $provider, ?Response $response): array
    {
        if ($provider !== 'openai' || $response === null) {
            return [];
        }
        $paths = ['input_tokens' => 'usage.input_tokens', 'output_tokens' => 'usage.output_tokens',
            'cached_input_tokens' => 'usage.input_tokens_details.cached_tokens',
            'reasoning_tokens' => 'usage.output_tokens_details.reasoning_tokens'];
        $units = [];
        foreach ($paths as $column => $path) {
            $value = $response->json($path);
            $units[$column] = is_int($value) && $value >= 0 ? $value : null;
        }

        return $units;
    }
}
