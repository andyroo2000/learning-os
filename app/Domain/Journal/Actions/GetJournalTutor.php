<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Enums\JournalTutor;
use Illuminate\Support\Facades\DB;

final class GetJournalTutor
{
    public function handle(int $userId): string
    {
        return DB::table('journal_tutor_preferences')->where('user_id', $userId)->value('tutor_id')
            ?? JournalTutor::Hana->value;
    }
}
