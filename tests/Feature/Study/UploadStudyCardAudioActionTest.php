<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Exceptions\CardContentRevisionConflictException;
use App\Domain\Study\Actions\UploadStudyCardAudioAction;
use App\Domain\Study\Exceptions\StudyCardAudioValidationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Study\RegenerateStudyCardAnswerAudioTestCase;

class UploadStudyCardAudioActionTest extends RegenerateStudyCardAnswerAudioTestCase
{
    public function test_direct_conversion_still_rejects_cloze_before_storage(): void
    {
        $card = $this->studyCardFor($this->signIn(), ['card_type' => CardType::Cloze]);

        try {
            resolve(UploadStudyCardAudioAction::class)->handle($card, UploadedFile::fake()->create('unused.wav'));
            $this->fail('Conversion must reject a cloze card.');
        } catch (StudyCardAudioValidationException $exception) {
            $this->assertSame('audio', $exception->field());
        }

        $this->assertSame([], Storage::disk('media')->allFiles());
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_direct_replacement_checks_the_expected_revision_before_storage(): void
    {
        $card = $this->studyCardFor($this->signIn(), ['card_type' => CardType::Cloze]);
        $card->update(['back_text' => 'Changed']);

        try {
            resolve(UploadStudyCardAudioAction::class)->handle($card, UploadedFile::fake()->create('unused.wav'), true, 0);
            $this->fail('A stale content revision must be rejected.');
        } catch (CardContentRevisionConflictException $exception) {
            $this->assertSame($card->id, $exception->card->id);
        }

        $this->assertSame([], Storage::disk('media')->allFiles());
        $this->assertDatabaseCount('media_assets', 0);
    }
}
