<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Support\DailyAudioPracticeId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ShowDailyAudioPracticeAction
{
    public function handle(int $userId, string $practiceId): DailyAudioPractice
    {
        if (! DailyAudioPracticeId::isValid($practiceId)) {
            throw new NotFoundHttpException('Daily Audio Practice not found.');
        }

        return DailyAudioPractice::query()
            ->whereKey($practiceId)
            ->where('user_id', $userId)
            ->with(['tracks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->first() ?? throw new NotFoundHttpException('Daily Audio Practice not found.');
    }
}
