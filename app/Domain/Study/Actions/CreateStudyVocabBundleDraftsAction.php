<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Data\CreateStudyCardDraftData;
use App\Domain\Study\Data\CreateStudyVocabBundleData;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Models\StudyVocabVariantGroup;
use App\Domain\Study\Models\StudyVocabVariantSentence;
use App\Domain\Study\Results\CreateStudyVocabBundleDraftsResult;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Support\Facades\DB;

class CreateStudyVocabBundleDraftsAction
{
    private const DEFAULT_VOICE_ID = 'fishaudio:abb4362e736f40b7b5716f4fafcafa9f';

    public function __construct(
        private readonly CreateStudyCardDraftAction $createStudyCardDraft,
    ) {}

    /**
     * @param  null|callable(string): void  $afterCommit
     */
    public function handle(
        CreateStudyVocabBundleData $data,
        ?callable $afterCommit = null,
    ): CreateStudyVocabBundleDraftsResult {
        return DB::transaction(function () use ($afterCommit, $data): CreateStudyVocabBundleDraftsResult {
            $group = new StudyVocabVariantGroup;
            $group->user_id = $data->userId;
            $group->target_word = $data->targetWord;
            $group->source_sentence = $data->sourceSentence;
            $group->source_context = $data->context;
            $group->include_learner_context = $data->includeLearnerContext;
            $group->save();

            $sentences = collect([0, 1, 2])->map(function (int $ordinal) use ($data, $group): StudyVocabVariantSentence {
                $placeholder = $ordinal === 0 && $data->sourceSentence !== null
                    ? $data->sourceSentence
                    : 'Generating sentence '.($ordinal + 1)." for {$data->targetWord}";

                $sentence = new StudyVocabVariantSentence;
                $sentence->user_id = $data->userId;
                $sentence->variant_group_id = $group->id;
                $sentence->ordinal = $ordinal;
                $sentence->sentence_jp = $placeholder;
                $sentence->sentence_en = '';
                $sentence->save();

                return $sentence;
            });

            $drafts = collect($this->placeholderVariants($data->targetWord))
                ->map(function (array $variant) use ($data, $group, $sentences) {
                    $sentence = $variant['sentenceOrdinal'] === null
                        ? null
                        : $sentences->firstWhere('ordinal', $variant['sentenceOrdinal']);

                    return $this->createStudyCardDraft->handle(CreateStudyCardDraftData::fromInput(
                        userId: $data->userId,
                        creationKind: $variant['creationKind'],
                        cardType: $variant['cardType'],
                        promptJson: $variant['prompt'],
                        answerJson: $variant['answer'],
                        imagePlacement: StudyCardImagePlacement::None,
                        variantGroupId: $group->id,
                        variantSentenceId: $sentence?->id,
                        variantKind: $variant['variantKind'],
                        variantStage: $variant['variantStage'],
                        variantStatus: $variant['variantStatus'],
                        variantUnlockedAt: $variant['variantStatus'] === VocabVariantStatus::Available
                            ? now()
                            : null,
                    ));
                });

            if ($afterCommit !== null) {
                DB::afterCommit(static fn () => $afterCommit($group->id));
            }

            return new CreateStudyVocabBundleDraftsResult($group, $drafts);
        });
    }

    /** @return list<array<string, mixed>> */
    private function placeholderVariants(string $targetWord): array
    {
        $variants = [];

        foreach ([0, 1, 2] as $ordinal) {
            $label = 'Generating sentence '.($ordinal + 1)." for {$targetWord}";
            $variants[] = [
                'creationKind' => StudyCardCreationKind::AudioRecognition,
                'cardType' => CardType::Recognition,
                'prompt' => [],
                'answer' => $this->placeholderAnswer($label),
                'variantKind' => VocabVariantKind::SentenceAudioRecognition,
                'variantStage' => 1,
                'variantStatus' => VocabVariantStatus::Available,
                'sentenceOrdinal' => $ordinal,
            ];
        }
        foreach ([0, 1, 2] as $ordinal) {
            $label = 'Generating sentence '.($ordinal + 1)." for {$targetWord}";
            $variants[] = [
                'creationKind' => StudyCardCreationKind::TextRecognition,
                'cardType' => CardType::Recognition,
                'prompt' => ['cueText' => $label],
                'answer' => $this->placeholderAnswer($label),
                'variantKind' => VocabVariantKind::SentenceTextRecognition,
                'variantStage' => 2,
                'variantStatus' => VocabVariantStatus::Locked,
                'sentenceOrdinal' => $ordinal,
            ];
        }
        $variants[] = [
            'creationKind' => StudyCardCreationKind::AudioRecognition,
            'cardType' => CardType::Recognition,
            'prompt' => [],
            'answer' => $this->placeholderAnswer($targetWord),
            'variantKind' => VocabVariantKind::WordAudioRecognition,
            'variantStage' => 3,
            'variantStatus' => VocabVariantStatus::Locked,
            'sentenceOrdinal' => null,
        ];
        $variants[] = [
            'creationKind' => StudyCardCreationKind::TextRecognition,
            'cardType' => CardType::Recognition,
            'prompt' => ['cueText' => $targetWord],
            'answer' => $this->placeholderAnswer($targetWord),
            'variantKind' => VocabVariantKind::WordTextRecognition,
            'variantStage' => 4,
            'variantStatus' => VocabVariantStatus::Locked,
            'sentenceOrdinal' => null,
        ];
        foreach ([0, 1, 2] as $ordinal) {
            $label = 'Generating sentence '.($ordinal + 1)." for {$targetWord}";
            $variants[] = [
                'creationKind' => StudyCardCreationKind::Cloze,
                'cardType' => CardType::Cloze,
                'prompt' => ['clozeText' => $label, 'clozeHint' => ''],
                'answer' => [
                    'restoredText' => $label,
                    'meaning' => '',
                    'answerAudioVoiceId' => self::DEFAULT_VOICE_ID,
                ],
                'variantKind' => VocabVariantKind::SentenceCloze,
                'variantStage' => 5,
                'variantStatus' => VocabVariantStatus::Locked,
                'sentenceOrdinal' => $ordinal,
            ];
        }

        return $variants;
    }

    /** @return array<string, string> */
    private function placeholderAnswer(string $expression): array
    {
        return [
            'expression' => $expression,
            'meaning' => '',
            'answerAudioVoiceId' => self::DEFAULT_VOICE_ID,
        ];
    }
}
