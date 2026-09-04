<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\CreateContentCourseData;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Results\CreateContentCourseResult;
use App\Domain\Content\Services\ContentCourseDescriptionGenerator;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;
use Throwable;

final class FinalizeContentCourseDescriptionAction
{
    public function __construct(
        private readonly ContentCourseDescriptionGenerator $descriptionGenerator,
    ) {}

    /** @param list<string> $episodeTitles */
    public function handle(
        CreateContentCourseResult $result,
        CreateContentCourseData $data,
        array $episodeTitles,
        string $generationToken,
    ): CreateContentCourseResult {
        if (! $result->wasCreated) {
            return $result;
        }
        if ($result->course === null) {
            return $result;
        }
        $description = $this->generateDescription($data, $episodeTitles);
        $course = $this->storeGeneratedDescription(
            $result->course->id,
            $description,
            $generationToken,
        );

        return $course instanceof ContentCourse
            ? CreateContentCourseResult::created($course)
            : $result;
    }

    /** @param list<string> $episodeTitles */
    private function generateDescription(CreateContentCourseData $data, array $episodeTitles): ?string
    {
        try {
            return $this->descriptionGenerator->generate(
                $episodeTitles,
                strtoupper($data->targetLanguage),
                strtoupper($data->nativeLanguage),
            );
        } catch (Throwable $exception) {
            // Course creation remains available when optional description generation fails.
            report($exception);

            return null;
        }
    }

    private function storeGeneratedDescription(
        string $courseId,
        ?string $description,
        string $generationToken,
    ): ?ContentCourse {
        return DB::transaction(function () use ($courseId, $description, $generationToken): ?ContentCourse {
            ContentSourceLock::acquireConvoLab(DB::connection());

            $course = ContentCourse::query()->whereKey($courseId)->lockForUpdate()->first();
            if (! $course instanceof ContentCourse) {
                return null;
            }

            $storedToken = $course->description_generation_token;
            if (! is_string($storedToken) || ! hash_equals($storedToken, $generationToken)) {
                return $course;
            }

            if ($description !== null) {
                $course->description = $description;
            }
            $course->description_generation_token = null;
            $course->save();

            return $course;
        });
    }
}
