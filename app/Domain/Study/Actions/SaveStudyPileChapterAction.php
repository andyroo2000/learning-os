<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudyPileChapter;
use App\Domain\Study\Services\PersistStudyPileRecord;
use App\Domain\Study\Support\StudyPileAccess;
use App\Domain\Study\Support\StudyPileInput;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class SaveStudyPileChapterAction
{
    public function __construct(private readonly PersistStudyPileRecord $persist) {}

    public function handle(int $userId, string $pileId, string $chapterId, array $attributes): StudyPileChapter
    {
        $pileId = StudyPileAccess::id($pileId);
        $chapterId = StudyPileAccess::id($chapterId);
        $attributes = StudyPileInput::validated($attributes);

        return DB::transaction(function () use ($userId, $pileId, $chapterId, $attributes): StudyPileChapter {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            StudyPileAccess::owned($userId, $pileId);
            $chapter = StudyPileChapter::query()->find($chapterId);
            if ($chapter !== null && $chapter->pile_id !== $pileId) {
                throw (new ModelNotFoundException)->setModel(StudyPileChapter::class);
            }
            $chapter ??= new StudyPileChapter;
            $chapter->id = $chapterId;
            $chapter->pile_id = $pileId;
            $this->persist->handle($userId, $chapter, $attributes);

            return $chapter;
        });
    }
}
