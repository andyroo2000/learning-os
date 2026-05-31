<?php

namespace App\Http\Requests\Flashcards;

use App\Domain\Flashcards\Actions\ListDeckCardsAction;
use App\Http\Requests\Api\CursorPaginatedRequest;

class ListDeckCardsRequest extends CursorPaginatedRequest
{
    protected function maxPerPage(): int
    {
        return ListDeckCardsAction::MAX_PAGE_SIZE;
    }
}
