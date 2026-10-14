<?php

namespace App\Domain\Study\Services;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Actions\DiscardGeneratedStudyMediaAction;
use App\Domain\Study\Actions\PersistGeneratedStudyMediaAction;
use App\Domain\Study\Results\GeneratedStudyMediaResult;
use App\Domain\Study\Support\StudyMediaGenerationRateLimiter;

final class StudyGeneratedSpeechMedia
{
    public function __construct(
        private readonly FishAudioSpeechGenerator $speech,
        private readonly PersistGeneratedStudyMediaAction $persist,
        private readonly DiscardGeneratedStudyMediaAction $discard,
        private readonly StudyMediaGenerationRateLimiter $rateLimiter,
    ) {}

    public function generate(int $userId, string $text, string $voiceId): GeneratedStudyMediaResult
    {
        $this->rateLimiter->consume($userId);

        return $this->persist->handle(
            userId: $userId,
            bytes: $this->speech->generate($text, $voiceId),
            mediaKind: 'audio',
            mimeType: 'audio/mpeg',
            extension: 'mp3',
        );
    }

    public function discard(MediaAsset $media): void
    {
        $this->discard->handle($media);
    }

    public function discardIfUnreferenced(MediaAsset $media): void
    {
        $this->discard->handleIfUnreferenced($media);
    }
}
