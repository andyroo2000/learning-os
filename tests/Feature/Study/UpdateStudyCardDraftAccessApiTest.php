<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudyCardDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateStudyCardDraftAccessApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_generating_draft_edits(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->for($user)->create();

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'Generating drafts cannot be edited yet.');
    }

    public function test_it_hides_missing_and_cross_user_drafts(): void
    {
        $this->signIn();
        $otherDraft = StudyCardDraft::factory()->ready()->for(User::factory()->create())->create();

        $this->patchJson("/api/study/card-drafts/{$otherDraft->id}", [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ])->assertNotFound();

        $this->patchJson('/api/study/card-drafts/'.strtolower((string) str()->ulid()), [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ])->assertNotFound();
    }
}
