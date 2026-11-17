<?php

namespace Tests\Unit\Journal;

use App\Support\Usage\UsagePricing;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class JournalUsagePricingTest extends TestCase
{
    public function test_japanese_bytes_use_the_promotion_and_then_regular_rate(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 07:59:59', 'UTC'), function (): void {
            $cost = UsagePricing::estimate('fish', 'drama-3', ['input_bytes' => strlen('日本語')]);
            $this->assertSame(90, $cost['estimated_micro_usd']);
        });
        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'UTC'), function (): void {
            $cost = UsagePricing::estimate('fish', 'drama-3', ['input_bytes' => strlen('日本語')]);
            $this->assertSame(450, $cost['estimated_micro_usd']);
            $this->assertSame(['usd_per_million_bytes' => 50], json_decode($cost['rate_snapshot'], true));
        });
    }

    public function test_unknown_models_and_missing_usage_remain_unknown(): void
    {
        $this->assertNull(UsagePricing::estimate('openai', 'future-model', ['input_tokens' => 10, 'output_tokens' => 20])['estimated_micro_usd']);
        $this->assertNull(UsagePricing::estimate('openai', 'gpt-6.1-sol', [])['estimated_micro_usd']);
    }
}
