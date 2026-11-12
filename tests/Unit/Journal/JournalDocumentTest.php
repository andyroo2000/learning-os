<?php

namespace Tests\Unit\Journal;

use App\Domain\Journal\Support\JournalDocument;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class JournalDocumentTest extends TestCase
{
    public function test_corrections_default_on_optional_default_off_and_toggles_are_reversible(): void
    {
        $blocks = $this->blocks();
        $this->assertSame('映画を見ました。よかった。', JournalDocument::render($blocks)->text);
        $blocks = JournalDocument::toggle($blocks, 'root:1', true);
        $this->assertSame('映画を見ました。とてもよかった。', JournalDocument::render($blocks)->text);
        $blocks = JournalDocument::toggle($blocks, 'root:0', false);
        $this->assertSame('映画を見ますた。とてもよかった。', JournalDocument::render($blocks)->text);
        $blocks = JournalDocument::toggle($blocks, 'root:0', true);
        $this->assertSame('映画[えいが]を見[み]ました。とてもよかった。', JournalDocument::render($blocks)->reading);
    }

    public function test_manual_edit_preserves_unaffected_suggestions_and_readings(): void
    {
        $blocks = $this->blocks();
        $plan = JournalDocument::editPlan($blocks, '映画を見ました。楽しかった。');
        $this->assertSame('楽しかった。', $plan['text']);
        $edited = JournalDocument::finishEdit($plan, '楽[たの]しかった。');
        $this->assertSame('映画を見ました。楽しかった。', JournalDocument::render($edited)->text);
        $this->assertSame('映画[えいが]を見[み]ました。楽[たの]しかった。', JournalDocument::render($edited)->reading);
        $rejected = JournalDocument::toggle($edited, 'root:0', false);
        $this->assertSame('映画を見ますた。楽しかった。', JournalDocument::render($rejected)->text);
        $this->expectException(ValidationException::class);
        JournalDocument::toggle($edited, 'root:1', true);
    }

    public function test_insertion_at_span_boundary_keeps_both_adjacent_suggestions(): void
    {
        $plan = JournalDocument::editPlan($this->blocks(), '映画を見ました。でも、よかった。');
        $this->assertSame('でも、', $plan['text']);
        $blocks = JournalDocument::finishEdit($plan, 'でも、');
        $blocks = JournalDocument::toggle($blocks, 'root:1', true);
        $this->assertSame('映画を見ました。でも、とてもよかった。', JournalDocument::render($blocks)->text);
    }

    public function test_edit_inside_a_span_expands_to_its_boundary(): void
    {
        $plan = JournalDocument::editPlan($this->blocks(), '映画を見ませんでした。よかった。');
        $this->assertSame('映画を見ませんでした。', $plan['text']);
        $edited = JournalDocument::finishEdit($plan, '映画[えいが]を見[み]ませんでした。');
        $this->assertSame('映画を見ませんでした。よかった。', JournalDocument::render($edited)->text);
        $this->assertSame('root:1', $edited[1]['id']);
    }

    public function test_legacy_feedback_and_replies_can_be_edited_without_inventing_replacements(): void
    {
        $legacy = ['correctedText' => 'はい。', 'correctedReading' => 'はい。'];
        $blocks = JournalDocument::fromFeedback($legacy, 'root');
        $blocks = JournalDocument::append($blocks, $this->feedback(), 'reply');
        $this->assertSame("はい。\n\n映画を見ました。よかった。", JournalDocument::render($blocks)->text);
        $this->assertSame('reply:0', $blocks[2]['id']);
    }

    public function test_deletion_no_op_and_repeated_edits_do_not_lose_text(): void
    {
        $blocks = JournalDocument::finishEdit(JournalDocument::editPlan($this->blocks(), 'よかった。'), '');
        $this->assertSame('よかった。', JournalDocument::render($blocks)->text);
        $blocks = JournalDocument::finishEdit(JournalDocument::editPlan($blocks, 'よかった。'), '');
        $blocks = JournalDocument::finishEdit(JournalDocument::editPlan($blocks, 'すごくよかった。'), 'すごく');
        $this->assertSame('すごくよかった。', JournalDocument::render($blocks)->text);
    }

    public function test_empty_or_oversized_entries_are_rejected(): void
    {
        foreach ([' ', str_repeat('あ', 30001)] as $text) {
            try {
                JournalDocument::editPlan($this->blocks(), $text);
                $this->fail('Expected invalid document rejection.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('text', $exception->errors());
            }
        }
    }

    private function blocks(): array
    {
        return JournalDocument::fromFeedback($this->feedback(), 'root');
    }

    private function feedback(): array
    {
        return ['editVersion' => 1, 'segments' => [
            ['text' => '映画を見ますた。', 'reading' => '映画[えいが]を見[み]ますた。', 'kind' => 'correction',
                'replacement' => '映画を見ました。', 'replacementReading' => '映画[えいが]を見[み]ました。'],
            ['text' => 'よかった。', 'reading' => 'よかった。', 'kind' => 'optional',
                'replacement' => 'とてもよかった。', 'replacementReading' => 'とてもよかった。'],
        ]];
    }
}
