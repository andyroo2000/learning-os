<?php

namespace App\Domain\Admin\Services;

use App\Domain\Admin\Results\AdminCourseDialogueResult;
use App\Domain\Content\Services\ContentOpenAiClient;

final readonly class AdminCourseDialogueGenerator
{
    public function __construct(private ContentOpenAiClient $client) {}

    /**
     * @param  list<array{speakerName: string, voiceId: string}>  $existingVoices
     */
    public function generate(
        string $prompt,
        array $existingVoices,
        string $speaker1VoiceId,
        string $speaker2VoiceId,
    ): AdminCourseDialogueResult {
        $json = $this->client->generateJson(
            'Extract a bounded language-learning dialogue. Follow the requested JSON shape exactly.',
            $prompt,
            'Admin dialogue',
        );

        return AdminCourseDialogueResult::fromJson(
            $json,
            $existingVoices,
            $speaker1VoiceId,
            $speaker2VoiceId,
        );
    }
}
