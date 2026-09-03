<?php

namespace App\Support\Rehearsal;

use App\Domain\Flashcards\Enums\CardStudyStatus;

class ConvoLabReviewState
{
    public static function before(object $review): ?string
    {
        $rawPayload = self::jsonObject($review->rawPayloadJson);
        $schedulerState = self::jsonObject($review->stateBeforeJson);
        $queueState = $rawPayload['beforeQueueState'] ?? null;

        if (! self::hasRestorableState($rawPayload, $schedulerState, $queueState)) {
            return null;
        }

        return json_encode([
            'study_status' => $queueState,
            'new_queue_position' => null,
            'scheduler_state' => $schedulerState,
            'due_at' => $rawPayload['beforeDueAt'],
            'introduced_at' => $rawPayload['beforeIntroducedAt'],
            // Older Convo Lab-native reviews omitted this optional key; its undo path restores null.
            'failed_at' => $rawPayload['beforeFailedAt'] ?? null,
            'last_reviewed_at' => $rawPayload['beforeLastReviewedAt'],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>|null  $rawPayload
     * @param  array<string, mixed>|null  $schedulerState
     */
    private static function hasRestorableState(?array $rawPayload, ?array $schedulerState, mixed $queueState): bool
    {
        if (! self::isRestorableQueueState($queueState)) {
            return false;
        }

        if ($schedulerState === null) {
            return false;
        }

        return self::hasValidSnapshotFields($rawPayload);
    }

    private static function isRestorableQueueState(mixed $queueState): bool
    {
        if (! is_string($queueState)) {
            return false;
        }

        $status = CardStudyStatus::tryFrom($queueState);

        return $status !== null && $status !== CardStudyStatus::New;
    }

    /**
     * @param  array<string, mixed>|null  $rawPayload
     */
    private static function hasValidSnapshotFields(?array $rawPayload): bool
    {
        if ($rawPayload === null) {
            return false;
        }

        foreach (['beforeDueAt', 'beforeIntroducedAt', 'beforeLastReviewedAt'] as $key) {
            if (! array_key_exists($key, $rawPayload)) {
                return false;
            }

            if (! self::isNullableString($rawPayload[$key])) {
                return false;
            }
        }

        return self::isNullableString($rawPayload['beforeFailedAt'] ?? null);
    }

    private static function isNullableString(mixed $value): bool
    {
        return is_string($value) || $value === null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function jsonObject(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
