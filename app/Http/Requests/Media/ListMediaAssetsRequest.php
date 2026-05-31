<?php

namespace App\Http\Requests\Media;

use App\Domain\Media\Actions\ListMediaAssetsAction;
use App\Http\Requests\Api\CursorPaginatedRequest;

class ListMediaAssetsRequest extends CursorPaginatedRequest
{
    protected function maxPerPage(): int
    {
        return ListMediaAssetsAction::MAX_PAGE_SIZE;
    }
}
