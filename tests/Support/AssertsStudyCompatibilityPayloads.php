<?php

namespace Tests\Support;

trait AssertsStudyCompatibilityPayloads
{
    /**
     * @return list<string>
     */
    protected function studyCardDraftCompatibilityPayloadKeys(): array
    {
        return [
            'id',
            'status',
            'creationKind',
            'cardType',
            'prompt',
            'answer',
            'imagePlacement',
            'imagePrompt',
            'previewAudio',
            'previewAudioRole',
            'previewImage',
            'variantGroupId',
            'variantSentenceId',
            'variantKind',
            'variantStage',
            'variantStatus',
            'variantUnlockedAt',
            'errorMessage',
            'committedCardId',
            'createdAt',
            'updatedAt',
        ];
    }

    /**
     * @return list<string>
     */
    protected function studyCardSummaryCompatibilityPayloadKeys(): array
    {
        return [
            'id',
            'noteId',
            'cardType',
            'prompt',
            'answer',
            'state',
            'variantGroupId',
            'variantSentenceId',
            'variantKind',
            'variantStage',
            'variantStatus',
            'variantUnlockedAt',
            'answerAudioSource',
            'createdAt',
            'updatedAt',
        ];
    }

    /**
     * @return list<string>
     */
    protected function studyCardSummaryStateKeys(): array
    {
        return [
            'dueAt',
            'introducedAt',
            'failedAt',
            'queueState',
            'scheduler',
            'source',
            'rawFsrs',
        ];
    }

    /**
     * @return list<string>
     */
    protected function studyCardSummarySourceKeys(): array
    {
        return [
            'noteId',
            'noteGuid',
            'cardId',
            'deckId',
            'deckName',
            'notetypeId',
            'notetypeName',
            'templateOrd',
            'templateName',
            'queue',
            'type',
            'due',
            'ivl',
            'factor',
            'reps',
            'lapses',
            'left',
            'odue',
            'odid',
        ];
    }

    /**
     * @return list<string>
     */
    protected function studyNewCardQueueItemCompatibilityPayloadKeys(): array
    {
        return [
            'id',
            'noteId',
            'cardType',
            'displayText',
            'meaning',
            'queuePosition',
            'createdAt',
            'updatedAt',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function assertStudyCardDraftCompatibilityPayloadHasShape(array $payload, string $label = 'draft payload'): void
    {
        $this->assertArrayHasKeys($this->studyCardDraftCompatibilityPayloadKeys(), $payload, $label);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function assertStudyCardDraftCompatibilityPageHasShape(array $payload): void
    {
        $this->assertArrayHasKeys(['drafts', 'total', 'limit', 'nextCursor'], $payload, 'draft list response');
        $this->assertIsArray($payload['drafts']);

        foreach ($payload['drafts'] as $index => $draft) {
            $this->assertIsArray($draft, "drafts.{$index} should be an object payload.");
            $this->assertStudyCardDraftCompatibilityPayloadHasShape($draft, "drafts.{$index}");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function assertStudyCardSummaryCompatibilityPayloadHasShape(array $payload, string $label = 'card payload'): void
    {
        $this->assertArrayHasKeys($this->studyCardSummaryCompatibilityPayloadKeys(), $payload, $label);
        $this->assertIsArray($payload['state'], "{$label}.state should be an object payload.");
        $this->assertArrayHasKeys($this->studyCardSummaryStateKeys(), $payload['state'], "{$label}.state");
        $this->assertIsArray($payload['state']['source'], "{$label}.state.source should be an object payload.");
        $this->assertArrayHasKeys(
            $this->studyCardSummarySourceKeys(),
            $payload['state']['source'],
            "{$label}.state.source",
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function assertStudyNewCardQueuePageHasShape(array $payload): void
    {
        $this->assertArrayHasKeys(['items', 'total', 'limit', 'nextCursor'], $payload, 'new card queue response');
        $this->assertIsArray($payload['items']);

        foreach ($payload['items'] as $index => $item) {
            $this->assertIsArray($item, "items.{$index} should be an object payload.");
            $this->assertArrayHasKeys(
                $this->studyNewCardQueueItemCompatibilityPayloadKeys(),
                $item,
                "items.{$index}",
            );
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $payload
     */
    private function assertArrayHasKeys(array $keys, array $payload, string $label): void
    {
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $payload, "{$label} is missing [{$key}].");
        }
    }
}
