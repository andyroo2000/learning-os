<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Enums\JournalTutor;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalQuestion;
use App\Domain\Journal\Models\JournalReply;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class JournalQuestions
{
    /** Caller holds the owner and analysis write locks. A later choice only fills an unassigned tutor. */
    public static function record(JournalAnalysis $analysis, ?JournalReply $reply = null): JournalQuestion
    {
        $source = $reply?->id ?? 'entry';
        $question = JournalQuestion::where('analysis_id', $analysis->id)->where('source_key', $source)->first();
        if ($question === null) {
            $feedback = Validator::make($reply?->result ?? $analysis->result ?? [], [
                'question' => ['required', 'string'], 'questionReading' => ['required', 'string'],
                'questionTranslation' => ['required', 'string'],
            ], ['required' => 'Submit this entry again to prepare its question.'])->validate();
            $question = new JournalQuestion;
            $question->analysis_id = $analysis->id;
            $question->source_key = $source;
            $question->text = $feedback['question'];
            $question->reading = $feedback['questionReading'];
            $question->translation = $feedback['questionTranslation'];
        }
        if ($question->tutor === null) {
            $choice = DB::table('journal_tutor_preferences')->where('user_id', $analysis->entry->user_id)->value('tutor_id');
            // Persist the complete versioned identity, including voice, so catalog changes affect only future questions.
            $question->tutor = JournalTutor::tryFrom($choice ?? '')?->snapshot();
        }
        if (! $question->exists || $question->isDirty()) {
            $question->save();
        }

        JournalQuestionAudioState::schedule($question);

        return $question;
    }
}
