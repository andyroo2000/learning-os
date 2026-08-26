<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Enums\CardSourceKind;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Japanese\Models\WaniKaniConnection;
use App\Domain\Study\Enums\AutomaticStudyVocabImportStatus;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\CardIntroductionCohort;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Models\StudyVocabVariantGroup;
use App\Domain\Study\Services\StudyVocabBundleGenerator;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class CommitAutomaticStudyVocabBundleAction
{
    public function __construct(
        private readonly CreateStudyCardFromDraftAction $createCardFromDraft,
        private readonly PrepareStudyCardAnswerAudioAction $prepareAnswerAudio,
        private readonly DeleteStudyCardDraftAction $deleteDraft,
    ) {}

    public function handle(string $groupId): int
    {
        $canonicalGroupId = CanonicalUlid::normalize($groupId);
        if (! Str::isUlid($canonicalGroupId)) {
            return 0;
        }

        $group = DB::transaction(function () use ($canonicalGroupId): ?StudyVocabVariantGroup {
            $group = StudyVocabVariantGroup::query()
                ->whereKey($canonicalGroupId)
                ->lockForUpdate()
                ->first();

            if ($group === null || $group->wanikani_subject_id === null) {
                return null;
            }
            if ($group->automatic_import_status === AutomaticStudyVocabImportStatus::Imported) {
                return $group;
            }
            if ($group->target_reading === null || $group->target_meaning === null) {
                throw new RuntimeException('Automatic study vocab bundle generation is incomplete.');
            }

            $cohort = CardIntroductionCohort::query()
                ->where('user_id', $group->user_id)
                ->where('source_kind', CardSourceKind::WaniKani->value)
                ->where('source_reference', (string) $group->wanikani_subject_id)
                ->first();
            if ($cohort === null) {
                $cohort = new CardIntroductionCohort;
                $cohort->user_id = $group->user_id;
                $cohort->source_kind = CardSourceKind::WaniKani;
                $cohort->source_reference = (string) $group->wanikani_subject_id;
                $cohort->label = mb_substr($group->target_word, 0, CardIntroductionCohort::MAX_LABEL_LENGTH);
                $cohort->save();
            }

            $group->automatic_import_status = AutomaticStudyVocabImportStatus::Generating;
            $group->automatic_import_error = null;
            $group->save();

            return $group;
        });

        if ($group === null
            || $group->automatic_import_status === AutomaticStudyVocabImportStatus::Imported) {
            return 0;
        }

        $drafts = StudyCardDraft::query()
            ->where('user_id', $group->user_id)
            ->where('variant_group_id', $group->id)
            ->orderBy('variant_stage')
            ->orderBy('variant_sentence_id')
            ->get();
        $cohort = CardIntroductionCohort::query()
            ->where('user_id', $group->user_id)
            ->where('source_kind', CardSourceKind::WaniKani->value)
            ->where('source_reference', (string) $group->wanikani_subject_id)
            ->sole();
        $processed = 0;

        foreach ($drafts as $draft) {
            if ($draft->status !== StudyManualCardDraftStatus::Ready) {
                throw new RuntimeException('Automatic study vocab bundle has a draft that is not ready.');
            }

            $card = $this->createCardFromDraft->handle(
                $group->user_id,
                $draft->id,
                $draft->id,
                $cohort->id,
                CardSelectionPolicy::Sprinkled,
                $draft->variant_stage === 1 ? $cohort->created_at->copy()->addWeek() : null,
            )->card;

            if ($this->needsListeningAudio($draft)) {
                $this->prepareAnswerAudio->handle($card);
            }

            $this->deleteDraft->handle($group->user_id, $draft->id);
            $processed++;
        }

        DB::transaction(function () use ($canonicalGroupId, $group): void {
            $lockedGroup = StudyVocabVariantGroup::query()
                ->whereKey($canonicalGroupId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedGroup->automatic_import_status === AutomaticStudyVocabImportStatus::Imported) {
                return;
            }

            $cardCount = Card::query()
                ->ownedByActiveDeck($group->user_id)
                ->where('cards.variant_group_id', $group->id)
                ->count();
            if ($cardCount !== StudyVocabBundleGenerator::TRANSFER_DRAFT_COUNT) {
                throw new RuntimeException('Automatic study vocab bundle did not commit every expected card.');
            }

            $importedAt = now();
            $lockedGroup->automatic_import_status = AutomaticStudyVocabImportStatus::Imported;
            $lockedGroup->automatic_import_error = null;
            $lockedGroup->automatic_imported_at = $importedAt;
            $lockedGroup->save();

            WaniKaniConnection::query()
                ->where('user_id', $group->user_id)
                ->update(['transfer_bridge_last_imported_at' => $importedAt]);
        });

        return $processed;
    }

    private function needsListeningAudio(StudyCardDraft $draft): bool
    {
        return in_array($draft->variant_kind, [
            VocabVariantKind::SentenceAudioRecognition->value,
            VocabVariantKind::WordAudioRecognition->value,
        ], true);
    }
}
