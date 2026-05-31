<?php

namespace App\Http\Requests\Flashcards;

use App\Domain\Flashcards\Actions\ListDecksAction;
use App\Http\Requests\Api\CursorPaginatedRequest;

class ListDecksRequest extends CursorPaginatedRequest
{
    protected function maxPerPage(): int
    {
        return ListDecksAction::MAX_PAGE_SIZE;
    }
}
