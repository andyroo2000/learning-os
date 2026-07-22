<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Data\SynthesizeAdminScriptLabLineData;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminScriptLabAudioRendering;
use App\Domain\Content\Support\ConvoLabUserId;
use App\Support\Audio\AudioSpeechGenerationException;
use App\Support\Audio\AudioSpeechGenerator;

final readonly class SynthesizeAdminScriptLabLineAction
{
    public function __construct(
        private AudioSpeechGenerator $speechGenerator,
        private PersistAdminScriptLabAudioRenderingAction $persistRendering,
    ) {}

    public function handle(
        string $actorConvoLabUserId,
        SynthesizeAdminScriptLabLineData $data,
    ): AdminScriptLabAudioRendering {
        $actorConvoLabUserId = ConvoLabUserId::normalize($actorConvoLabUserId);

        try {
            $bytes = $this->speechGenerator->generate($data->text, $data->voiceId, $data->speed);
        } catch (AudioSpeechGenerationException $exception) {
            throw AdminMutationException::lineSynthesisUnavailable($exception);
        }

        return $this->persistRendering->handle(
            $actorConvoLabUserId,
            $data->text,
            $data->text,
            $data->voiceId,
            $data->speed,
            null,
            null,
            $bytes,
        );
    }
}
