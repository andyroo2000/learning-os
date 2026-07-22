<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminSentenceScriptTest;
use App\Domain\Admin\Support\AdminSentenceScriptTestId;
use App\Domain\Content\Support\ConvoLabUserId;
use InvalidArgumentException;

final class ShowAdminSentenceScriptTestAction
{
    public function handle(string $actorConvoLabUserId, string $testId): AdminSentenceScriptTest
    {
        $actorConvoLabUserId = ConvoLabUserId::normalize($actorConvoLabUserId);
        try {
            $testId = AdminSentenceScriptTestId::normalize($testId);
        } catch (InvalidArgumentException) {
            throw AdminMutationException::sentenceScriptTestNotFound();
        }

        $test = AdminSentenceScriptTest::query()
            ->whereKey($testId)
            ->where('actor_convolab_user_id', $actorConvoLabUserId)
            ->first();
        if (! $test instanceof AdminSentenceScriptTest) {
            throw AdminMutationException::sentenceScriptTestNotFound();
        }

        return $test;
    }
}
