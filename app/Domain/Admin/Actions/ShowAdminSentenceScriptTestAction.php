<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminSentenceScriptTest;
use App\Domain\Admin\Support\AdminSentenceScriptTestId;
use InvalidArgumentException;

final class ShowAdminSentenceScriptTestAction
{
    public function handle(string $testId): AdminSentenceScriptTest
    {
        try {
            $testId = AdminSentenceScriptTestId::normalize($testId);
        } catch (InvalidArgumentException) {
            throw AdminMutationException::sentenceScriptTestNotFound();
        }

        $test = AdminSentenceScriptTest::query()->find($testId);
        if (! $test instanceof AdminSentenceScriptTest) {
            throw AdminMutationException::sentenceScriptTestNotFound();
        }

        return $test;
    }
}
