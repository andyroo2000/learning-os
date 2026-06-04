<?php

namespace App\Domain\Study\Results;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Support\Collection;

class StartStudySessionResult
{
    /**
     * @param  array<string, mixed>  $overview
     * @param  Collection<int, Card>  $cards
     */
    public function __construct(
        public readonly array $overview,
        public readonly Collection $cards,
    ) {}
}
