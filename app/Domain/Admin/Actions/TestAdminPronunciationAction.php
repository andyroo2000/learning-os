<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Data\TestAdminPronunciationData;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminScriptLabAudioRendering;
use App\Domain\Admin\Services\AdminJapanesePronunciationOverrides;
use App\Domain\Admin\Services\AdminPronunciationTextPreprocessor;
use App\Domain\Content\Support\ConvoLabUserId;
use App\Support\Audio\AudioSpeechGenerationException;
use App\Support\Audio\AudioSpeechGenerator;
use Throwable;

final readonly class TestAdminPronunciationAction
{
    public function __construct(
        private AdminPronunciationTextPreprocessor $preprocessor,
        private AdminJapanesePronunciationOverrides $overrides,
        private AudioSpeechGenerator $speechGenerator,
        private PersistAdminScriptLabAudioRenderingAction $persistRendering,
    ) {}

    public function handle(
        string $actorConvoLabUserId,
        TestAdminPronunciationData $data,
    ): AdminScriptLabAudioRendering {
        $actorConvoLabUserId = ConvoLabUserId::normalize($actorConvoLabUserId);
        $preprocessedText = $data->text;

        if ($data->requiresPreprocessing()) {
            try {
                $generated = $this->preprocessor->convert($data->text, $data->format);
                $preprocessedText = $this->overrides->apply(
                    $data->text,
                    $generated,
                    $data->format === 'furigana_brackets' ? $generated : null,
                );
            } catch (Throwable $exception) {
                throw AdminMutationException::pronunciationTestUnavailable($exception);
            }
        }

        try {
            $bytes = $this->speechGenerator->generate($preprocessedText, $data->voiceId, $data->speed);
        } catch (AudioSpeechGenerationException $exception) {
            throw AdminMutationException::pronunciationTestUnavailable($exception);
        }

        $durationSeconds = round(
            ($this->legacyTextLength($preprocessedText) / (150 * $data->speed)) * 60,
            1,
        );

        try {
            return $this->persistRendering->handle(
                $actorConvoLabUserId,
                $data->text,
                $preprocessedText,
                $data->voiceId,
                $data->speed,
                $data->format,
                $durationSeconds,
                $bytes,
            );
        } catch (AdminMutationException $exception) {
            throw AdminMutationException::pronunciationTestUnavailable($exception);
        }
    }

    private function legacyTextLength(string $text): int
    {
        return (int) (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
    }
}
