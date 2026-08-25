<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\AutomaticStudyVocabImportStatus;
use App\Domain\Study\Models\StudyVocabVariantGroup;
use Illuminate\Support\Facades\DB;

final class MarkAutomaticStudyVocabImportFailedAction
{
    public function handle(string $groupId, string $message): void
    {
        DB::transaction(function () use ($groupId, $message): void {
            $group = StudyVocabVariantGroup::query()
                ->whereKey($groupId)
                ->whereNotNull('wanikani_subject_id')
                ->lockForUpdate()
                ->first();

            if ($group === null
                || $group->automatic_import_status === AutomaticStudyVocabImportStatus::Imported) {
                return;
            }

            $group->automatic_import_status = AutomaticStudyVocabImportStatus::Error;
            $group->automatic_import_error = mb_substr(trim($message), 0, 2000);
            $group->save();
        });
    }
}
