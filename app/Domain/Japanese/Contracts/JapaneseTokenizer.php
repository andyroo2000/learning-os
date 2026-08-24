<?php

namespace App\Domain\Japanese\Contracts;

interface JapaneseTokenizer
{
    /**
     * @param  list<string>  $texts
     * @return list<list<array{surface: string, base: string, partOfSpeech: string, partOfSpeechSubtype: string, conjugationType: string, conjugationForm: string}>>
     */
    public function tokenize(array $texts): array;

    public function hadFailure(): bool;
}
