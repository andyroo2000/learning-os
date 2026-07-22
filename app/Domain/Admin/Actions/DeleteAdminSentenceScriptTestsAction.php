<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\AdminSentenceScriptTest;
use App\Domain\Admin\Support\AdminSentenceScriptTestId;
use App\Domain\Content\Support\ConvoLabUserId;
use InvalidArgumentException;

final class DeleteAdminSentenceScriptTestsAction
{
    /** @param list<string> $testIds */
    public function handle(string $actorConvoLabUserId, array $testIds): int
    {
        $actorConvoLabUserId = ConvoLabUserId::normalize($actorConvoLabUserId);
        if ($testIds === [] || count($testIds) > 100) {
            throw new InvalidArgumentException('Sentence test IDs must contain between 1 and 100 items.');
        }
        foreach ($testIds as $testId) {
            if (! is_string($testId)) {
                throw new InvalidArgumentException('Sentence test ID must be a UUID.');
            }
        }

        $normalized = array_values(array_unique(array_map(
            fn (string $testId): string => AdminSentenceScriptTestId::normalize($testId),
            $testIds,
        )));

        return AdminSentenceScriptTest::query()
            ->whereKey($normalized)
            ->where('actor_convolab_user_id', $actorConvoLabUserId)
            ->delete();
    }
}
