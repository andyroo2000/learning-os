<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\UpdateStudyCardDraftAction;
use App\Domain\Study\Data\UpdateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftRevisionConflictException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateStudyCardDraftRevisionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_direct_autosave_is_a_readback_for_generating_drafts(): void
    {
        $draft = StudyCardDraft::factory()->create([
            'status' => StudyManualCardDraftStatus::Generating,
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
        ]);
        $this->assertNotNull($draft->updated_at);
        $originalUpdatedAt = $draft->updated_at->toJSON();

        $updated = app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput());

        $this->assertSame($draft->id, $updated->id);
        $this->assertSame(StudyManualCardDraftStatus::Generating, $updated->status);
        $this->assertSame(['cueText' => '会社'], $updated->prompt_json);
        $this->assertSame(['meaning' => 'company'], $updated->answer_json);
        $this->assertNotNull($updated->updated_at);
        $this->assertSame($originalUpdatedAt, $updated->updated_at->toJSON());
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_it_rejects_a_stale_expected_revision_before_mutating_the_locked_draft(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'revision' => 4,
            'image_prompt' => 'Current prompt',
        ]);

        try {
            app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
                expectedRevision: 3,
                hasImagePrompt: true,
                imagePrompt: 'Stale prompt',
            ));
            $this->fail('Expected a stale draft revision to be rejected.');
        } catch (StudyCardDraftRevisionConflictException $e) {
            $this->assertSame($draft->id, $e->draft->id);
            $this->assertSame(4, $e->draft->revision);
            $this->assertSame('Study card draft changed since it was loaded.', $e->getMessage());
        }

        $this->assertSame('Current prompt', $draft->refresh()->image_prompt);
        $this->assertSame(4, $draft->revision);
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_it_only_updates_present_fields(): void
    {
        $draft = StudyCardDraft::factory()->failed()->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['expression' => '会社', 'meaning' => 'company'],
            'image_placement' => StudyCardImagePlacement::Both,
            'image_prompt' => 'Keep this',
        ]);

        $updated = app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
            hasAnswer: true,
            answerJson: ['expression' => '会社', 'meaning' => 'business'],
            hasPrompt: true,
            promptJson: ['cueText' => '会社'],
        ));

        $updated->refresh();

        $this->assertSame(['cueText' => '会社'], $updated->prompt_json);
        $this->assertSame(['expression' => '会社', 'meaning' => 'business'], $updated->answer_json);
        $this->assertSame(StudyCardImagePlacement::Both, $updated->image_placement);
        $this->assertSame('Keep this', $updated->image_prompt);
        $this->assertSame(StudyManualCardDraftStatus::Error, $updated->status);
    }
}
