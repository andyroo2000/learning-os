<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Data\PronunciationDictionaryData;
use App\Domain\Admin\Exceptions\AdminMutationException;
use Illuminate\Contracts\Validation\Validator;

class UpdateAdminPronunciationDictionaryRequest extends ConvoLabAdminWriteRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // Domain validation owns the legacy 400 messages and protects direct callers too.
            'keepKanji' => ['present'],
            'forceKana' => ['present'],
            'verbKana' => ['sometimes'],
        ]);
    }

    public function dictionaryData(): PronunciationDictionaryData
    {
        $data = $this->validated();

        return PronunciationDictionaryData::fromArray(array_intersect_key(
            $data,
            array_flip(['keepKanji', 'forceKana', 'verbKana']),
        ));
    }

    protected function failedValidation(Validator $validator): never
    {
        if ($validator->errors()->has('keepKanji')) {
            throw AdminMutationException::invalidPronunciationDictionary(
                'keepKanji must be an array of strings',
            );
        }
        if ($validator->errors()->has('forceKana')) {
            throw AdminMutationException::invalidPronunciationDictionary(
                'forceKana must be an object of word-to-kana mappings',
            );
        }

        parent::failedValidation($validator);
    }
}
