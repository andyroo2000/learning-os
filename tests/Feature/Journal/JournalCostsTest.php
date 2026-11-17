<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Models\JournalEntry;
use App\Support\Usage\ProviderUsage;
use App\Support\Usage\UsageScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class JournalCostsTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_filters_utc_month_and_owner_and_keeps_unknown_costs_visible(): void
    {
        $first = JournalEntry::factory()->create();
        $second = JournalEntry::factory()->create();
        Http::fake(['provider.test/*' => Http::response('ID3')]);
        foreach ([$first, $second] as $entry) {
            $this->travelTo(now('UTC')->setDate(2026, 11, 30), fn () => UsageScope::run($entry->user_id, $entry->id, 'journal.audio',
                fn () => ProviderUsage::request('fish', 'drama-3', ['input_bytes' => 100], fn () => Http::post('https://provider.test/tts'))));
        }
        $this->travelTo(now('UTC')->setDate(2026, 12, 2), fn () => UsageScope::run($first->user_id, $first->id, 'journal.audio',
            fn () => ProviderUsage::request('fish', 'drama-3', ['input_bytes' => 200], fn () => Http::post('https://provider.test/tts'))));
        $this->travelTo(now('UTC')->setDate(2026, 11, 30), fn () => UsageScope::run($first->user_id, $first->id, 'journal.correction',
            fn () => ProviderUsage::request('openai', 'gpt-6.1-sol', [], fn () => Http::post('https://provider.test/response'))));
        $this->assertSame(0, Artisan::call('journal:costs', ['month' => '2026-11', '--user' => $first->user_id]));
        $groups = collect(json_decode(Artisan::output(), true)['groups'])->keyBy('operation');
        $this->assertCount(2, $groups);
        $this->assertSame(1, $groups['journal.audio']['requests']);
        $this->assertSame(1000, $groups['journal.audio']['estimated_micro_usd']);
        $this->assertSame(1, $groups['journal.correction']['unknown_cost_requests']);
        $this->assertNull($groups['journal.correction']['estimated_micro_usd']);
    }
}
