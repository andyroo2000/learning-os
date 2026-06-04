<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Results\UpdateCardResult;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class PerformCardStudyAction
{
    public function __construct(
        private readonly SetCardDueAction $setCardDue,
        private readonly UpdateCardStudyStatusAction $updateCardStudyStatus,
    ) {}

    public function handle(
        Card $card,
        string $action,
        ?string $mode = null,
        ?string $dueAt = null,
        ?string $timeZone = null,
        ?Carbon $now = null,
    ): UpdateCardResult {
        return match ($this->normalizeAction($action)) {
            'set_due' => $this->setCardDue->handle(
                card: $card,
                mode: $mode ?? '',
                dueAt: $dueAt,
                timeZone: $timeZone,
                now: $now,
            ),
            'suspend' => $this->updateCardStudyStatus->handle($card, CardStudyStatus::Suspended),
            'forget' => $this->updateCardStudyStatus->handle($card, CardStudyStatus::New),
            'unsuspend' => $this->unsuspend($card, $now),
        };
    }

    private function normalizeAction(string $action): string
    {
        $normalized = strtolower(trim($action));

        if (! in_array($normalized, ['set_due', 'suspend', 'unsuspend', 'forget'], strict: true)) {
            throw new InvalidArgumentException('Card action must be one of: set_due, suspend, unsuspend, forget.');
        }

        return $normalized;
    }

    private function unsuspend(Card $card, ?Carbon $now): UpdateCardResult
    {
        $currentStatus = $card->study_status ?? CardStudyStatus::New;

        if ($currentStatus === CardStudyStatus::New) {
            return $this->updateCardStudyStatus->handle($card, CardStudyStatus::New);
        }

        if ($card->due_at === null) {
            return $this->setCardDue->handle(
                card: $card,
                mode: 'now',
                now: $now,
            );
        }

        return $this->setCardDue->handle(
            card: $card,
            mode: 'custom_date',
            dueAt: $card->due_at->toJSON(),
            now: $now,
        );
    }
}
