<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Enums\JournalTutor;
use App\Domain\Journal\Support\JournalWriteTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SaveJournalTutor
{
    public function handle(int $userId, array $input): void
    {
        $data = Validator::make($input, ['tutorId' => ['required', 'string', Rule::enum(JournalTutor::class)]])->validate();
        JournalWriteTransaction::run($userId, fn () => DB::table('journal_tutor_preferences')
            ->updateOrInsert(['user_id' => $userId], ['tutor_id' => $data['tutorId']]));
    }
}
