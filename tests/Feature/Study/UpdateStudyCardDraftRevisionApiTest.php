<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateStudyCardDraftRevisionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_expected_revision_rejects_a_stale_autosave_and_returns_the_current_draft(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
        ]);

        $this->assertSame(0, $draft->revision);

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'expectedRevision' => 0,
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'business'],
        ])
            ->assertOk()
            ->assertJsonPath('revision', 1)
            ->assertJsonPath('answer.meaning', 'business');

        $response = $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'expectedRevision' => 0,
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'corporation'],
        ])
            ->assertConflict()
            ->assertExactJson([
                'code' => 'draft_revision_conflict',
                'message' => 'Study card draft changed since it was loaded.',
                'draft' => array_replace($this->getJson("/api/study/card-drafts/{$draft->id}")->assertOk()->json(), [
                    'revision' => 1,
                ]),
            ]);

        $this->assertSame('business', $response->json('draft.answer.meaning'));
        $this->assertSame('business', $draft->refresh()->answer_json['meaning']);
        $this->assertSame(1, $draft->revision);
    }

    public function test_expected_revision_is_optional_for_legacy_autosave_clients(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
        ]);

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'business'],
        ])
            ->assertOk()
            ->assertJsonPath('revision', 1)
            ->assertJsonPath('answer.meaning', 'business');

        $this->assertSame(1, $draft->refresh()->revision);
    }

    public function test_expected_revision_accepts_the_same_signed_integer_strings_as_validation(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'revision' => 3,
        ]);

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'expectedRevision' => '+3',
            'imagePrompt' => 'Updated by a query-form compatible client',
        ])
            ->assertOk()
            ->assertJsonPath('revision', 4)
            ->assertJsonPath('imagePrompt', 'Updated by a query-form compatible client');
    }

    public function test_identical_autosave_with_expected_revision_does_not_advance_revision(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
        ]);

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'expectedRevision' => 0,
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ])
            ->assertOk()
            ->assertJsonPath('revision', 0);

        $this->assertSame(0, $draft->refresh()->revision);
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_it_validates_expected_revision(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create();

        foreach ([-1, 1.5, 'stale', null, []] as $expectedRevision) {
            $this->patchJson("/api/study/card-drafts/{$draft->id}", [
                'expectedRevision' => $expectedRevision,
                'imagePrompt' => 'A valid mutation',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['expectedRevision']);
        }
    }
}
