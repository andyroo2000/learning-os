<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class JournalCosts extends Command
{
    protected $signature = 'journal:costs {month? : UTC month YYYY-MM} {--user= : Limit to one user ID}';

    protected $description = 'Report measured Journal API units and estimated USD costs; unknown costs remain explicit';

    public function handle(): int
    {
        $input = Validator::make(['month' => $this->argument('month') ?? now('UTC')->format('Y-m'),
            'user' => $this->option('user')], ['month' => ['required', 'date_format:Y-m'],
                'user' => ['nullable', 'integer', 'min:1']])->validate();
        $start = CarbonImmutable::createFromFormat('!Y-m', $input['month'], 'UTC');
        $query = DB::table('ai_usage_events')->where('created_at', '>=', $start)
            ->where('created_at', '<', $start->addMonth());
        if ($input['user'] !== null) {
            $query->where('user_id', (int) $input['user']);
        }
        $groups = ['user_id', 'operation', 'provider', 'model'];
        $rows = $query->select($groups)->selectRaw('COUNT(*) AS requests, COUNT(*) - COUNT(estimated_micro_usd) AS unknown_cost_requests')
            ->selectRaw('SUM(estimated_micro_usd) AS estimated_micro_usd, SUM(input_tokens) AS input_tokens, SUM(cached_input_tokens) AS cached_input_tokens, SUM(cache_write_tokens) AS cache_write_tokens')
            ->selectRaw('SUM(output_tokens) AS output_tokens, SUM(reasoning_tokens) AS reasoning_tokens, SUM(input_bytes) AS input_bytes')
            ->groupBy($groups)->orderBy('user_id')->orderBy('operation')->orderBy('provider')->orderBy('model')->get();
        $this->line(json_encode(['month_utc' => $input['month'], 'currency' => 'USD',
            'note' => 'Estimates only. Divide micro USD by 1,000,000. Failed requests and missing usage are unknown and excluded from sums. Cache hits make no provider request.',
            'groups' => $rows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
