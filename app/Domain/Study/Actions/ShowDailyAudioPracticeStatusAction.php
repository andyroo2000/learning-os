<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Support\DailyAudioPracticeId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ShowDailyAudioPracticeStatusAction
{
    public function handle(int $userId, string $practiceId): DailyAudioPractice
    {
        if (! DailyAudioPracticeId::isValid($practiceId)) {
            throw new NotFoundHttpException('Daily Audio Practice not found.');
        }

        return DailyAudioPractice::query()
            ->select(['id', 'user_id', 'status'])
            ->whereKey($practiceId)
            ->where('user_id', $userId)
            ->with(['tracks' => fn ($query) => $query
                ->select([
                    'id',
                    'practice_id',
                    'mode',
                    'status',
                    'sort_order',
                    'audio_url',
                    'approx_duration_seconds',
                ])
                ->orderBy('sort_order')
                ->orderBy('id')])
            ->first() ?? throw new NotFoundHttpException('Daily Audio Practice not found.');
    }
}
