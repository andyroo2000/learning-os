<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Models\StudyVocabVariantGroup;
use App\Domain\Study\Services\StudyVocabBundleGenerator;
use App\Domain\Study\Support\StudyCardPayloadShapeValidator;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ProcessStudyVocabBundleDraftsAction
{
    public function __construct(
        private readonly StudyVocabBundleGenerator $generator,
        private readonly RecordStudyCardDraftSyncEntryAction $recordStudyCardDraftSyncEntry,
    ) {}

    public function handle(string $groupId): ?int
    {
        $canonicalGroupId = CanonicalUlid::normalize($groupId);
        if (! Str::isUlid($canonicalGroupId)) {
            return null;
        }

        $group = StudyVocabVariantGroup::query()->find($canonicalGroupId);
        if ($group === null) {
            return null;
        }

        $hasGeneratingDrafts = StudyCardDraft::query()
            ->where('variant_group_id', $group->id)
            ->where('user_id', $group->user_id)
            ->where('status', StudyManualCardDraftStatus::Generating)
            ->exists();
        if (! $hasGeneratingDrafts) {
            return 0;
        }

        // Provider calls stay outside the transaction so row locks are held only for persistence.
        $generated = $this->generator->generate($group);

        return DB::transaction(function () use ($canonicalGroupId, $generated): int {
            $group = StudyVocabVariantGroup::query()
                ->whereKey($canonicalGroupId)
                ->lockForUpdate()
                ->first();
            if ($group === null) {
                return 0;
            }

            $sentences = $group->sentences()
                ->orderBy('ordinal')
                ->lockForUpdate()
                ->get();
            $drafts = StudyCardDraft::query()
                ->where('variant_group_id', $group->id)
                ->where('user_id', $group->user_id)
                ->lockForUpdate()
                ->get();
            $generatingDrafts = $drafts->where('status', StudyManualCardDraftStatus::Generating);

            if ($generatingDrafts->isEmpty()) {
                return 0;
            }
            if ($drafts->count() !== StudyVocabBundleGenerator::DRAFT_COUNT || $sentences->count() !== 3) {
                throw new RuntimeException('Queued study vocab bundle placeholders no longer match the generated bundle.');
            }
            if ($generatingDrafts->count() !== $drafts->count()) {
                throw new RuntimeException('Queued study vocab bundle drafts are not in one consistent generating state.');
            }

            $group->target_word = $generated['targetWord'];
            $group->target_reading = $generated['targetReading'];
            $group->target_meaning = $generated['targetMeaning'];
            $group->save();

            $sentenceIdsByOrdinal = [];
            foreach ($generated['sentences'] as $generatedSentence) {
                $sentence = $sentences->firstWhere('ordinal', $generatedSentence['ordinal']);
                if ($sentence === null) {
                    throw new RuntimeException('Queued study vocab sentence placeholder was not found.');
                }

                $sentence->sentence_jp = $generatedSentence['sentenceJp'];
                $sentence->sentence_reading = $generatedSentence['sentenceReading'];
                $sentence->sentence_en = $generatedSentence['sentenceEn'];
                $sentence->notes = $generatedSentence['notes'];
                $sentence->save();
                $sentenceIdsByOrdinal[$sentence->ordinal] = $sentence->id;
            }

            $draftsByKey = $drafts->keyBy(
                static fn (StudyCardDraft $draft): string => self::draftKey(
                    $draft->variant_stage,
                    $draft->variant_sentence_id,
                ),
            );
            if ($draftsByKey->count() !== $drafts->count()) {
                throw new RuntimeException('Queued study vocab bundle has duplicate draft placeholders.');
            }

            $updated = 0;
            $seenKeys = [];
            foreach ($generated['variants'] as $variant) {
                $sentenceId = $variant['sentenceOrdinal'] === null
                    ? null
                    : ($sentenceIdsByOrdinal[$variant['sentenceOrdinal']] ?? null);
                $key = self::draftKey($variant['variantStage'], $sentenceId);

                if (isset($seenKeys[$key])) {
                    throw new RuntimeException('Generated study vocab bundle has duplicate variants.');
                }
                $seenKeys[$key] = true;

                /** @var StudyCardDraft|null $draft */
                $draft = $draftsByKey->get($key);
                if ($draft === null) {
                    throw new RuntimeException('Generated study vocab variant has no queued draft placeholder.');
                }

                StudyCardPayloadShapeValidator::assertDraftPayloadsAreValid(
                    $variant['prompt'],
                    $variant['answer'],
                );
                $draft->status = StudyManualCardDraftStatus::Ready;
                $draft->creation_kind = $variant['creationKind'];
                $draft->card_type = $variant['cardType'];
                $draft->prompt_json = $variant['prompt'];
                $draft->answer_json = $variant['answer'];
                $draft->image_placement = $variant['imagePlacement'];
                $draft->image_prompt = $variant['imagePrompt'];
                $draft->preview_audio_json = null;
                $draft->preview_audio_role = null;
                $draft->preview_image_json = null;
                $draft->variant_kind = $variant['variantKind']->value;
                $draft->variant_status = $variant['variantStatus']->value;
                $draft->variant_unlocked_at = $variant['variantStatus']->value === 'available'
                    ? now()
                    : null;
                $draft->error_message = null;
                $draft->save();
                $this->recordStudyCardDraftSyncEntry->handle($draft, SyncFeedOperation::Update);
                $updated++;
            }

            return $updated;
        });
    }

    private static function draftKey(?int $stage, ?string $sentenceId): string
    {
        return ($stage === null ? 'none' : (string) $stage).':'.($sentenceId ?? 'word');
    }
}
