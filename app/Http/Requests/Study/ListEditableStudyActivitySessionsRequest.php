<?php

namespace App\Http\Requests\Study;

use App\Http\Requests\Api\CursorPaginatedRequest;

final class ListEditableStudyActivitySessionsRequest extends CursorPaginatedRequest
{
    protected function defaultPerPage(): int
    {
        return 20;
    }

    protected function maxPerPage(): int
    {
        return 50;
    }

    protected function cursorParameters(): array
    {
        return ['started_at', 'id'];
    }
}
