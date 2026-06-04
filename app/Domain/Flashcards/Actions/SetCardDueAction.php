<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Results\UpdateCardResult;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\DateTime\StrictIsoDateTime;
use DateTimeZone;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SetCardDueAction
{
    public const MAX_FUTURE_YEARS = 10;

    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(
        Card $card,
        string $mode,
        ?string $dueAt = null,
        ?string $timeZone = null,
        ?Carbon $now = null,
    ): UpdateCardResult {
        $now ??= now();
        $dueAt = $this->resolveDueAt(
            mode: $mode,
            dueAt: $dueAt,
            timeZone: $timeZone,
            now: $now,
        );

        return DB::transaction(function () use ($card, $dueAt): UpdateCardResult {
            $nextStudyStatus = $this->restoredStudyStatus($card);

            if (($card->study_status ?? CardStudyStatus::New) !== $nextStudyStatus) {
                $card->study_status = $nextStudyStatus;
            }

            if ($card->new_queue_position !== null) {
                $card->new_queue_position = null;
            }

            if ($card->due_at === null || ! $card->due_at->equalTo($dueAt)) {
                $card->due_at = $dueAt;
            }

            if (! $card->isDirty(['study_status', 'new_queue_position', 'due_at'])) {
                return UpdateCardResult::unchanged($card);
            }

            $card->saveOrFail();

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $card->ownerUserId(),
                    domain: CardSyncPayload::DOMAIN,
                    resourceType: CardSyncPayload::RESOURCE_TYPE,
                    resourceId: $card->id,
                    operation: SyncFeedOperation::Update->value,
                    payload: CardSyncPayload::fromCard($card),
                ),
            );

            return UpdateCardResult::updated($card);
        });
    }

    private function resolveDueAt(string $mode, ?string $dueAt, ?string $timeZone, Carbon $now): Carbon
    {
        return match ($this->normalizeMode($mode)) {
            'now' => $now->copy(),
            'tomorrow' => $this->tomorrowDueAt($timeZone, $now),
            'custom_date' => $this->customDueAt($dueAt, $now),
        };
    }

    private function normalizeMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));

        if (! in_array($normalized, ['now', 'tomorrow', 'custom_date'], strict: true)) {
            throw new InvalidArgumentException('Set-due mode must be one of: now, tomorrow, custom_date.');
        }

        return $normalized;
    }

    private function tomorrowDueAt(?string $timeZone, Carbon $now): Carbon
    {
        $resolvedTimeZone = trim($timeZone ?? '');

        if ($resolvedTimeZone === '') {
            throw new InvalidArgumentException('time_zone must be a valid IANA timezone for tomorrow.');
        }

        try {
            new DateTimeZone($resolvedTimeZone);
        } catch (Exception) {
            throw new InvalidArgumentException('time_zone must be a valid IANA timezone for tomorrow.');
        }

        return $now->copy()
            ->setTimezone($resolvedTimeZone)
            ->addDay()
            ->startOfDay()
            ->setTime(9, 0)
            ->setTimezone('UTC');
    }

    private function customDueAt(?string $dueAt, Carbon $now): Carbon
    {
        if ($dueAt === null || ! StrictIsoDateTime::matches($dueAt)) {
            throw new InvalidArgumentException('due_at must be a valid ISO-8601 datetime for custom_date.');
        }

        try {
            $resolvedDueAt = Carbon::parse($dueAt);
        } catch (Exception) {
            throw new InvalidArgumentException('due_at must be a valid ISO-8601 datetime for custom_date.');
        }

        if ($resolvedDueAt->greaterThan($now->copy()->addYears(self::MAX_FUTURE_YEARS))) {
            throw new InvalidArgumentException('due_at must be within 10 years.');
        }

        return $resolvedDueAt;
    }

    private function restoredStudyStatus(Card $card): CardStudyStatus
    {
        $currentStatus = $card->study_status ?? CardStudyStatus::New;

        return in_array($currentStatus, [
            CardStudyStatus::New,
            CardStudyStatus::Suspended,
            CardStudyStatus::Buried,
        ], strict: true)
            ? CardStudyStatus::Review
            : $currentStatus;
    }
}
