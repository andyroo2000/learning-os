<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\AdminPronunciationDictionary;

class GetAdminPronunciationDictionaryAction
{
    public function handle(): AdminPronunciationDictionary
    {
        return AdminPronunciationDictionary::query()->findOrFail('ja');
    }
}
