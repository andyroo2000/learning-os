<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminScriptLabAudioRendering;
use App\Domain\Admin\Support\AdminScriptLabAudio;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class PersistAdminScriptLabAudioRenderingAction
{
    public function handle(
        string $actorConvoLabUserId,
        string $originalText,
        string $synthesizedText,
        string $voiceId,
        float $speed,
        ?string $format,
        ?float $durationSeconds,
        string $audioBytes,
    ): AdminScriptLabAudioRendering {
        $actorConvoLabUserId = ConvoLabUserId::normalize($actorConvoLabUserId);
        $renderingId = (string) Str::uuid();
        $path = AdminScriptLabAudio::storagePath($actorConvoLabUserId, $renderingId);
        $disk = Storage::disk((string) config('content_courses.audio_disk'));
        $stored = false;

        try {
            if (! $disk->put($path, $audioBytes)) {
                throw AdminMutationException::lineSynthesisUnavailable();
            }
            $stored = true;

            return AdminScriptLabAudioRendering::query()->forceCreate([
                'id' => $renderingId,
                'actor_convolab_user_id' => $actorConvoLabUserId,
                'original_text' => $originalText,
                'synthesized_text' => $synthesizedText,
                'voice_id' => $voiceId,
                'speed' => $speed,
                'format' => $format,
                'duration_seconds' => $durationSeconds,
                'audio_storage_path' => $path,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            if ($stored) {
                try {
                    $disk->delete($path);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            if ($exception instanceof AdminMutationException) {
                throw $exception;
            }

            throw AdminMutationException::lineSynthesisUnavailable($exception);
        }
    }
}
