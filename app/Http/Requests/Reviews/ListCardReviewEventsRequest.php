<?php

namespace App\Http\Requests\Reviews;

use App\Http\Requests\Api\CursorPaginatedRequest;

class ListCardReviewEventsRequest extends CursorPaginatedRequest
{
    protected function cursorParameters(): array
    {
        return ['reviewed_at', 'id'];
    }
}
