<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class StudyCardDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_study_card_drafts_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('study_card_drafts', [
            'id',
            'user_id',
            'status',
            'creation_kind',
            'card_type',
            'prompt_json',
            'answer_json',
            'image_placement',
            'image_prompt',
            'preview_audio_json',
            'preview_audio_role',
            'preview_image_json',
            'error_message',
            'committed_card_id',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_it_casts_draft_fields(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'creation_kind' => StudyCardCreationKind::ProductionImage,
            'prompt_json' => ['cueImage' => ['id' => 'image-1']],
            'answer_json' => ['answerText' => '犬'],
            'image_placement' => StudyCardImagePlacement::Prompt,
            'preview_image_json' => [
                'id' => 'image-1',
                'filename' => 'inu.png',
                'url' => '/api/study/media/image-1',
                'mediaKind' => 'image',
                'source' => 'generated',
            ],
        ]);

        $draft->refresh();

        $this->assertSame(StudyManualCardDraftStatus::Ready, $draft->status);
        $this->assertSame(StudyCardCreationKind::ProductionImage, $draft->creation_kind);
        $this->assertSame(CardType::Production, $draft->card_type);
        $this->assertSame(['cueImage' => ['id' => 'image-1']], $draft->prompt_json);
        $this->assertSame(['answerText' => '犬'], $draft->answer_json);
        $this->assertSame(StudyCardImagePlacement::Prompt, $draft->image_placement);
        $this->assertSame(StudyCardAudioRole::Answer, $draft->preview_audio_role);
        $this->assertSame('image-1', $draft->preview_image_json['id']);
    }

    public function test_process_owned_fields_are_not_mass_assignable(): void
    {
        $draft = new StudyCardDraft;

        $this->assertFalse($draft->isFillable('user_id'));
        $this->assertFalse($draft->isFillable('card_type'));
        $this->assertFalse($draft->isFillable('status'));
        $this->assertFalse($draft->isFillable('error_message'));
        $this->assertFalse($draft->isFillable('committed_card_id'));
    }

    public function test_it_derives_card_type_from_creation_kind_on_create(): void
    {
        $draft = StudyCardDraft::factory()->create([
            'creation_kind' => StudyCardCreationKind::ProductionText,
            'card_type' => CardType::Recognition,
        ]);

        $this->assertSame(CardType::Production, $draft->refresh()->card_type);
    }

    public function test_it_derives_card_type_from_creation_kind_on_update(): void
    {
        $draft = StudyCardDraft::factory()->create();

        $draft->creation_kind = StudyCardCreationKind::ProductionText;
        $draft->save();

        $this->assertSame(CardType::Production, $draft->refresh()->card_type);
    }

    public function test_it_throws_if_creation_kind_is_missing_on_create(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study card draft creation kind must be set before saving.');

        StudyCardDraft::factory()->create([
            'creation_kind' => null,
        ]);
    }

    public function test_it_does_not_rewrite_card_type_when_creation_kind_is_unchanged(): void
    {
        $draft = StudyCardDraft::factory()->failed()->create();

        $draft->error_message = 'Still failed.';
        $draft->save();

        $this->assertTrue($draft->wasChanged('error_message'));
        $this->assertFalse($draft->wasChanged('card_type'));
    }

    public function test_it_prevents_owner_mutation(): void
    {
        $draft = StudyCardDraft::factory()->create();
        $otherUser = User::factory()->create();

        $draft->user_id = $otherUser->id;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study card draft owner cannot be changed.');

        $draft->save();
    }
}
