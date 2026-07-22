<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Data\PronunciationDictionaryData;
use App\Domain\Admin\Models\AdminPronunciationDictionary;
use Illuminate\Support\Facades\DB;

class UpdateAdminPronunciationDictionaryAction
{
    public function handle(PronunciationDictionaryData $data): AdminPronunciationDictionary
    {
        return DB::transaction(function () use ($data): AdminPronunciationDictionary {
            $dictionary = AdminPronunciationDictionary::query()
                ->lockForUpdate()
                ->findOrFail('ja');

            $dictionary->keep_kanji = $data->keepKanji;
            $dictionary->force_kana = $data->forceKana;
            if ($data->verbKana !== null) {
                $dictionary->verb_kana = $data->verbKana;
            }
            $dictionary->updated_at = now();
            $dictionary->save();

            return $dictionary->refresh();
        });
    }
}
