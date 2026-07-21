<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentAudioScriptInput;
use App\Domain\Content\Support\ContentEpisodeId;
use App\Domain\Content\Support\ConvoLabUserId;
use InvalidArgumentException;

final readonly class UpdateContentAudioScriptData
{
    /**
     * @param  list<array{text: string, reading: string|null, translation: string, imagePrompt: string|null}>  $segments
     */
    private function __construct(
        public int $userId,
        public string $convoLabUserId,
        public string $episodeId,
        public ?string $title,
        public ?string $voiceId,
        public array $segments,
    ) {}

    public static function fromInput(
        int $userId,
        string $convoLabUserId,
        string $episodeId,
        ?string $title,
        ?string $voiceId,
        array $segments,
    ): self {
        if ($userId <= 0) {
            throw new InvalidArgumentException('User ID must be a positive integer.');
        }
        if ($title !== null) {
            if (trim($title) === '') {
                throw new InvalidArgumentException('Script title must not be blank when provided.');
            }
            if (mb_strlen(trim($title)) > ContentAudioScriptInput::MAX_TITLE_CHARACTERS) {
                throw new InvalidArgumentException(
                    'Script title must contain at most '.ContentAudioScriptInput::MAX_TITLE_CHARACTERS.' characters.',
                );
            }
        }
        if (count($segments) > ContentAudioScriptInput::MAX_SEGMENTS) {
            throw new InvalidArgumentException(
                'Scripts may contain at most '.ContentAudioScriptInput::MAX_SEGMENTS.' segments.',
            );
        }
        if (! array_is_list($segments)) {
            throw new InvalidArgumentException('Script segments must be a list.');
        }

        $normalizedSegments = [];
        foreach ($segments as $index => $segment) {
            if (! is_array($segment)) {
                throw new InvalidArgumentException('Each script segment must be an object.');
            }
            $normalizedSegments[] = ContentAudioScriptInput::segment($segment, $index + 1);
        }

        return new self(
            $userId,
            ConvoLabUserId::normalize($convoLabUserId),
            ContentEpisodeId::normalize($episodeId),
            $title === null ? null : ContentAudioScriptInput::title($title, 'Japanese Script'),
            $voiceId === null ? null : ContentAudioScriptInput::voiceId($voiceId),
            $normalizedSegments,
        );
    }
}
