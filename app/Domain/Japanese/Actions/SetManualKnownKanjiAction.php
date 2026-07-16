<?php

namespace App\Domain\Japanese\Actions;

use App\Domain\Japanese\Models\JapaneseKnowledgeProfile;
use App\Domain\Japanese\Models\UserKnownKanji;
use Illuminate\Support\Facades\DB;

final class SetManualKnownKanjiAction
{
    public function handle(int $userId, string $character, bool $known): bool
    {
        return DB::transaction(function () use ($userId, $character, $known): bool {
            $profile = JapaneseKnowledgeProfile::lockForUser($userId);
            $row = UserKnownKanji::query()
                ->where('user_id', $userId)
                ->where('character', $character)
                ->lockForUpdate()
                ->first();

            $wasKnown = $row?->isEffectivelyKnown() ?? false;

            if ($known) {
                $row ??= new UserKnownKanji;
                $row->user_id = $userId;
                $row->character = $character;
                $row->manually_added_at ??= now();
                $row->save();
            } elseif ($row !== null) {
                $row->manually_added_at = null;
                if ($row->wanikani_passed_at === null) {
                    $row->delete();
                } else {
                    $row->save();
                }
            }

            $isKnown = $known || ($row?->wanikani_passed_at !== null);
            if ($wasKnown !== $isKnown) {
                $profile->increment('knowledge_version');
            }

            return $isKnown;
        });
    }
}
