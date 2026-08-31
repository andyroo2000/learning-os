<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Support\StudyCardAudio;
use PHPUnit\Framework\TestCase;

class StudyCardAudioTest extends TestCase
{
    public function test_prompt_audio_is_the_primary_logical_card_audio(): void
    {
        $promptAudio = ['url' => '/prompt.mp3', 'source' => 'generated'];
        $answerAudio = ['url' => '/answer.mp3', 'source' => 'imported'];
        $card = $this->card(['cueAudio' => $promptAudio], ['answerAudio' => $answerAudio]);

        $this->assertSame($promptAudio, StudyCardAudio::reference($card));
        $this->assertSame('generated', StudyCardAudio::source($card));
    }

    public function test_malformed_prompt_audio_falls_through_to_valid_answer_audio(): void
    {
        $answerAudio = ['url' => '/answer.mp3', 'source' => 'imported'];

        foreach ([[], ['not-an-object']] as $malformedPromptAudio) {
            $card = $this->card(
                ['cueAudio' => $malformedPromptAudio],
                ['answerAudio' => $answerAudio],
            );

            $this->assertSame($answerAudio, StudyCardAudio::reference($card));
            $this->assertSame('imported', StudyCardAudio::source($card));
        }
    }

    public function test_it_returns_missing_when_neither_side_has_an_object_reference(): void
    {
        $card = $this->card(['cueAudio' => []], ['answerAudio' => ['not-an-object']]);

        $this->assertNull(StudyCardAudio::reference($card));
        $this->assertSame('missing', StudyCardAudio::source($card));
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @param  array<string, mixed>  $answer
     */
    private function card(array $prompt, array $answer): Card
    {
        $card = new Card;
        $card->forceFill([
            'prompt_json' => $prompt,
            'answer_json' => $answer,
            'answer_audio_source' => null,
        ]);

        return $card;
    }
}
