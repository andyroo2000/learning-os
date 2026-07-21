<?php

namespace App\Domain\Content\Services;

use App\Domain\Content\Results\ContentCourseScriptUnit;
use App\Domain\Content\Support\ContentCourseAudio;
use App\Domain\Content\Support\ContentCourseVoiceNormalizer;
use App\Support\Audio\AudioTrackAssembler;
use App\Support\Audio\AudioTrackAssemblyResult;

final readonly class ContentCourseAudioAssembler
{
    public function __construct(
        private AudioTrackAssembler $assembler,
        private ContentCourseVoiceNormalizer $voices,
    ) {}

    /** @param list<ContentCourseScriptUnit> $units */
    public function assemble(string $courseId, int $revision, array $units): AudioTrackAssemblyResult
    {
        return $this->assembler->assemble(
            array_map($this->voices->normalize(...), $units),
            (string) config('content_courses.audio_disk'),
            ContentCourseAudio::storagePath($courseId, $revision),
            'learning-os-content-course',
            'Course audio',
        );
    }
}
