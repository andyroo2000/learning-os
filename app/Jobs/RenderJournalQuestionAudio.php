<?php

namespace App\Jobs;

use App\Domain\Journal\Actions\GenerateJournalQuestionAudio;
use App\Domain\Journal\Support\JournalQuestionAudioState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RenderJournalQuestionAudio implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 85;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $questionId, public readonly string $token)
    {
        $this->onQueue('default');
    }

    public function handle(GenerateJournalQuestionAudio $generate): void
    {
        $generate->handle($this->questionId, $this->token);
    }

    public function failed(?Throwable $exception): void
    {
        JournalQuestionAudioState::fail($this->questionId, $this->token);
    }
}
