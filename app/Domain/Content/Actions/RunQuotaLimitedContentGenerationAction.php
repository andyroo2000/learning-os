<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\ContentGenerationType;
use App\Domain\Content\Exceptions\ContentGenerationRejectedException;
use App\Domain\Content\Models\ContentGenerationLog;
use Throwable;

final class RunQuotaLimitedContentGenerationAction
{
    public function __construct(
        private readonly ManageContentGenerationQuotaAction $quota,
    ) {}

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $operation
     * @param  callable(TResult): ?string  $contentId
     * @return TResult
     */
    public function handle(
        string $convoLabUserId,
        ContentGenerationType $type,
        ?string $initialContentId,
        callable $operation,
        callable $contentId,
    ): mixed {
        $reservation = $this->quota->reserve($convoLabUserId, $type, $initialContentId);

        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $this->cancel(
                $reservation,
                clearCooldown: $exception instanceof ContentGenerationRejectedException,
            );

            throw $exception;
        }

        if ($result === null) {
            $this->cancel($reservation, clearCooldown: true);

            return null;
        }

        try {
            $this->quota->complete($reservation, $contentId($result));
        } catch (Throwable $exception) {
            // Content IDs are optional quota metadata. A bookkeeping update must not turn a
            // successfully queued generation into a retry that could duplicate provider spend.
            report($exception);
        }

        return $result;
    }

    private function cancel(
        ?ContentGenerationLog $reservation,
        bool $clearCooldown = false,
    ): void {
        try {
            $this->quota->cancel($reservation, $clearCooldown);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
