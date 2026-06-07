<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyExportCardsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Flashcards\CardResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListStudyExportCardsController extends Controller
{
    public function __invoke(Request $request, ListStudyExportCardsAction $listStudyExportCards): AnonymousResourceCollection
    {
        $userId = AuthenticatedUser::id($request);

        return CardResource::collection(
            $listStudyExportCards->handle($userId),
        );
    }
}
