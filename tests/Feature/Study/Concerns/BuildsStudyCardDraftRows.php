<?php

namespace Tests\Feature\Study\Concerns;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Actions\CreateStudyCardDraftAction;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait BuildsStudyCardDraftRows
{
    /**
     * @return list<array<string, mixed>>
     */
    private function cappedDraftRowsFor(User $user): array
    {
        $now = now();
        $rows = [];

        for ($index = 0; $index < CreateStudyCardDraftAction::MAX_DRAFTS_PER_USER; $index++) {
            $rows[] = [
                'id' => strtolower((string) Str::ulid()),
                'user_id' => $user->id,
                'status' => StudyManualCardDraftStatus::Generating->value,
                'creation_kind' => StudyCardCreationKind::TextRecognition->value,
                'card_type' => CardType::Recognition->value,
                'prompt_json' => json_encode(['cueText' => '犬']),
                'answer_json' => json_encode(['meaning' => 'dog']),
                'image_placement' => StudyCardImagePlacement::None->value,
                'image_prompt' => null,
                'preview_audio_json' => null,
                'preview_audio_role' => null,
                'preview_image_json' => null,
                'error_message' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function insertCappedDraftRowsFor(User $user): array
    {
        $rows = $this->cappedDraftRowsFor($user);

        // 60 rows x 15 columns leaves headroom under SQLite's 999 bind-parameter cap.
        foreach (array_chunk($rows, 60) as $chunk) {
            DB::table('study_card_drafts')->insert($chunk);
        }

        return $rows;
    }
}
