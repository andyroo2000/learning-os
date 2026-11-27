<?php

namespace App\Domain\Learn\Actions;

use App\Domain\Learn\Models\LearnSession;
use App\Domain\Learn\Services\GenerateLearnFeedback;
use App\Domain\Learn\Support\LearnCatalog;
use App\Domain\Learn\Support\LearnNavigation;
use App\Domain\Learn\Support\LearnProgress;
use Throwable;

final class RespondToLearnSession
{
    public function __construct(private LearnSessionWrites $writes, private GenerateLearnFeedback $tutor, private LearnCatalog $catalog) {}

    public function handle(LearnSession $session, array $input, string $tutor): LearnSession
    {
        if ($session->last_request_id === $input['requestId']) {
            return $session;
        }
        $this->writes->claim($session, $input);
        try {
            $state = $this->transition($session, $input, $tutor);

            return $this->writes->complete($session, $input, $state);
        } finally {
            $this->writes->release($session, $input['requestId']);
        }
    }

    private function transition(LearnSession $session, array $input, string $tutor): array
    {
        $lesson = $this->catalog->find($session->lesson_id);
        $state = $session->state;
        $stages = ['understand' => 'check', 'ready' => 'ready', 'workbook' => 'exercise', 'reflect' => 'complete'];
        if (isset($stages[$input['action']])) {
            return LearnNavigation::go($state, $stages[$input['action']], $lesson);
        }
        if ($input['action'] === 'previous') {
            return LearnProgress::previous($state);
        }
        if ($input['action'] === 'next') {
            return LearnProgress::advance($state, $lesson);
        }
        if ($input['action'] === 'review') {
            return LearnProgress::review($state);
        }
        if ($input['action'] === 'restart') {
            return LearnProgress::initial();
        }
        abort_if($input['action'] === 'answer' && ! $this->catalog->prompt($lesson, $state), 422, 'There is no question to answer yet.');
        abort_if(count($state['turns']) >= 160, 422, 'This session is full. Restart the lesson to continue.');
        try {
            $feedback = $this->tutor->handle($lesson, $state, $input, $tutor);
        } catch (Throwable $error) {
            report($error);
            abort(503, 'The tutor could not respond. Your answer has not been submitted; please try again.');
        }

        return LearnProgress::reply($state, $input, [...$feedback, 'prompt' => $this->catalog->prompt($lesson, $state)]);
    }
}
