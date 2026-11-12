<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalReply;
use App\Domain\Journal\Support\JournalReplyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class JournalReplyContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_chained_context_uses_the_previous_expanded_diary(): void
    {
        $parent = JournalReply::factory()->create(['status' => JournalAnalysisStatus::Ready,
            'result' => ['entryText' => '先の日記', 'entryReading' => '先[さき]の日記[にっき]', 'question' => '何があった？']]);
        $next = JournalReply::factory()->create(['analysis_id' => $parent->analysis_id, 'parent_id' => $parent->id]);
        $this->assertSame(['diary' => '先の日記', 'reading' => '先[さき]の日記[にっき]', 'question' => '何があった？'], JournalReplyContext::forReply($next, $parent->analysis));
    }

    public function test_unready_parent_does_not_fall_back_to_the_original_diary(): void
    {
        $parent = JournalReply::factory()->create();
        $next = JournalReply::factory()->create(['analysis_id' => $parent->analysis_id, 'parent_id' => $parent->id]);
        $this->expectException(RuntimeException::class);
        JournalReplyContext::forReply($next, $parent->analysis);
    }

    public function test_expanded_diary_has_a_bounded_size(): void
    {
        $this->expectException(RuntimeException::class);
        JournalReplyContext::append(['diary' => str_repeat('あ', 30000)], ['correctedText' => 'あ']);
    }
}
