<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Models\JournalEntry;
use App\Support\Usage\ProviderUsage;
use App\Support\Usage\UsageScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

final class JournalUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_billed_tokens_without_content_or_double_counting_reasoning(): void
    {
        $entry = JournalEntry::factory()->create();
        Http::fake(['provider.test/*' => Http::response(['usage' => [
            'input_tokens' => 2000, 'input_tokens_details' => ['cached_tokens' => 1000],
            'output_tokens' => 1500, 'output_tokens_details' => ['reasoning_tokens' => 1000],
        ], 'output_text' => 'PRIVATE JOURNAL CONTENT'], 200, ['x-request-id' => 'request-1'])]);
        UsageScope::run($entry->user_id, $entry->id, 'journal.correction',
            fn () => ProviderUsage::request('openai', 'gpt-6.1-sol', [], fn () => Http::post('https://provider.test/responses')));
        $record = DB::table('ai_usage_events')->sole();
        $this->assertSame(17100, $record->estimated_micro_usd);
        $this->assertSame(1000, $record->reasoning_tokens);
        $this->assertSame(1000, $record->cached_input_tokens);
        $this->assertSame('request-1', $record->provider_request_id);
        $this->assertSame($entry->user_id, $record->user_id);
        $this->assertStringNotContainsString('PRIVATE', json_encode($record));
        $this->assertNull(Context::getHidden('ai_usage'));
        $entry->delete();
        $this->assertDatabaseCount('ai_usage_events', 1);
        $entry->user->delete();
        $this->assertDatabaseCount('ai_usage_events', 0);
    }

    public function test_unknown_usage_and_transport_failure_are_not_reported_as_free(): void
    {
        $entry = JournalEntry::factory()->create();
        Http::fake(['provider.test/*' => Http::response(['status' => 'completed'])]);
        UsageScope::run($entry->user_id, $entry->id, 'journal.correction',
            fn () => ProviderUsage::request('openai', 'gpt-6.1-sol', [], fn () => Http::post('https://provider.test/responses')));
        try {
            UsageScope::run($entry->user_id, $entry->id, 'journal.audio',
                fn () => ProviderUsage::request('fish', 'drama-3', ['input_bytes' => 9], fn () => throw new RuntimeException('timeout')));
            $this->fail('Expected original transport failure');
        } catch (RuntimeException $error) {
            $this->assertSame('timeout', $error->getMessage());
        }
        $this->assertDatabaseCount('ai_usage_events', 2);
        $this->assertSame(2, DB::table('ai_usage_events')->whereNull('estimated_micro_usd')->count());
        $this->assertNull(Context::getHidden('ai_usage'));
    }

    public function test_tracking_failure_preserves_paid_result_and_logs_recoverable_metrics_only(): void
    {
        Http::fake(['provider.test/*' => Http::response('ID3audio')]);
        Log::spy();
        // A deleted owner intentionally makes the FK reject the accounting write.
        $result = UsageScope::run(999999, '01jx0000000000000000000000', 'journal.audio',
            fn () => ProviderUsage::request('fish', 'drama-3', ['input_bytes' => 12], fn () => Http::post('https://provider.test/tts')));
        $this->assertSame('ID3audio', $result->body());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $record) => $message === 'AI usage persistence failed' && $record['input_bytes'] === 12 && ! str_contains(json_encode($record), 'ID3audio'));
    }

    public function test_unscoped_requests_do_not_leak_the_previous_journal_owner(): void
    {
        Http::fake(['provider.test/*' => Http::response('ID3audio')]);
        ProviderUsage::request('fish', 'drama-3', ['input_bytes' => 12], fn () => Http::post('https://provider.test/tts'));
        $this->assertDatabaseCount('ai_usage_events', 0);
    }
}
