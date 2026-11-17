<?php

namespace App\Support\Usage;

final class UsagePricing
{
    public static function estimate(string $provider, string $model, array $units): array
    {
        $rates = config('ai_usage.'.$provider, [])[$model] ?? null;
        if ($rates === null) {
            return ['estimated_micro_usd' => null, 'rate_snapshot' => null];
        }
        if ($provider === 'fish') {
            $rate = now('UTC')->lt(config('ai_usage.fish_launch_ends_at')) ? $rates['launch'] : $rates['standard'];
            $cost = isset($units['input_bytes']) ? $units['input_bytes'] * $rate : null;
            $rates = ['usd_per_million_bytes' => $rate];
        } else {
            $cost = self::tokens($units, $rates);
        }

        return ['estimated_micro_usd' => $cost === null ? null : (int) round($cost),
            'rate_snapshot' => json_encode($rates, JSON_THROW_ON_ERROR)];
    }

    private static function tokens(array $units, array $rates): ?float
    {
        if (! isset($units['input_tokens'], $units['output_tokens'])) {
            return null;
        }
        $cached = min($units['input_tokens'], $units['cached_input_tokens'] ?? 0);

        $writes = min($units['input_tokens'] - $cached, $units['cache_write_tokens'] ?? 0);

        // Output includes reasoning: recording reasoning separately must not bill it twice.
        return ($units['input_tokens'] - $cached - $writes) * $rates['input'] + $writes * $rates['write']
            + $cached * $rates['cached'] + $units['output_tokens'] * $rates['output'];
    }
}
