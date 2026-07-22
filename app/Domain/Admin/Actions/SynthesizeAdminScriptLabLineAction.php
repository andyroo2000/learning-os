<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Data\SynthesizeAdminScriptLabLineData;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminScriptLabAudioRendering;
use App\Domain\Admin\Support\AdminScriptLabAudio;
use App\Domain\Content\Support\ConvoLabUserId;
use App\Support\Audio\AudioSpeechGenerationException;
use App\Support\Audio\AudioSpeechGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class SynthesizeAdminScriptLabLineAction
{
    public function __construct(private AudioSpeechGenerator $speechGenerator) {}

    public function handle(
        string $actorConvoLabUserId,
        SynthesizeAdminScriptLabLineData $data,
    ): AdminScriptLabAudioRendering {
        $actorConvoLabUserId = ConvoLabUserId::normalize($actorConvoLabUserId);

        try {
            $bytes = $this->speechGenerator->generate($data->text, $data->voiceId, $data->speed);
        } catch (AudioSpeechGenerationException $exception) {
            throw AdminMutationException::courseLineSynthesisUnavailable($exception);
        }

        $renderingId = (string) Str::uuid();
        $path = AdminScriptLabAudio::storagePath($actorConvoLabUserId, $renderingId);
        $disk = Storage::disk((string) config('content_courses.audio_disk'));
        $stored = false;

        try {
            if (! $disk->put($path, $bytes)) {
                throw AdminMutationException::courseLineSynthesisUnavailable();
            }
            $stored = true;

            return DB::transaction(fn (): AdminScriptLabAudioRendering => AdminScriptLabAudioRendering::query()->forceCreate([
                'id' => $renderingId,
                'actor_convolab_user_id' => $actorConvoLabUserId,
                'original_text' => $data->text,
                'synthesized_text' => $data->text,
                'voice_id' => $data->voiceId,
                'speed' => $data->speed,
                'format' => null,
                'duration_seconds' => null,
                'audio_storage_path' => $path,
                'created_at' => now(),
            ]),
            );
        } catch (Throwable $exception) {
            if ($stored) {
                try {
                    $disk->delete($path);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }
}
