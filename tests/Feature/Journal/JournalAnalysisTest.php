<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_snapshot_and_result_survive_later_entry_edits(): void
    {
        $analysis = JournalAnalysis::factory()->create(['source_body' => "  映画を見た。🍿\n"]);
        $analysis->entry->body = '別の文章。';
        $analysis->entry->save();
        $analysis->status = JournalAnalysisStatus::Ready;
        $analysis->result = ['corrected' => '映画[えいが]を見[み]た。'];
        $analysis->completed_at = '2026-11-12 16:00:00.123456';
        $analysis->save();
        $saved = $analysis->fresh();

        $this->assertSame("  映画を見た。🍿\n", $saved->source_body);
        $this->assertSame(JournalAnalysisStatus::Ready, $saved->status);
        $this->assertSame($analysis->result, $saved->result);
        $this->assertSame('123456', $saved->completed_at->format('u'));
    }

    #[DataProvider('immutableFields')]
    public function test_source_identity_cannot_be_changed(string $field, mixed $value): void
    {
        $analysis = JournalAnalysis::factory()->create();
        $analysis->{$field} = $value;
        $this->expectException(LogicException::class);
        $analysis->save();
    }

    public static function immutableFields(): array
    {
        return [['entry_id', '01arZ3ndektsv4rrffq69g5fav'], ['source_version', 2], ['source_body', '書き換える。']];
    }

    public function test_one_analysis_per_entry_version_is_enforced_by_database(): void
    {
        $analysis = JournalAnalysis::factory()->create();
        $this->expectException(UniqueConstraintViolationException::class);
        JournalAnalysis::factory()->create(['entry_id' => $analysis->entry_id]);
    }

    public function test_later_versions_are_distinct_and_account_deletion_cascades_to_all_results(): void
    {
        $analysis = JournalAnalysis::factory()->create();
        JournalAnalysis::factory()->create(['entry_id' => $analysis->entry_id, 'source_version' => 2]);
        $this->assertDatabaseCount('journal_analyses', 2);
        $analysis->entry->delete();
        $this->assertDatabaseCount('journal_analyses', 2);
        JournalEntry::withTrashed()->findOrFail($analysis->entry_id)->user->delete();
        $this->assertDatabaseCount('journal_analyses', 0);
    }

    public function test_untrusted_input_cannot_mass_assign_generated_results(): void
    {
        $this->expectException(MassAssignmentException::class);
        (new JournalAnalysis)->fill(['result' => ['corrected' => 'untrusted']]);
    }
}
