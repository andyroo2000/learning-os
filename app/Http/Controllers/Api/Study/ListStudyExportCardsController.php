<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyExportCardsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Flashcards\CardResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListStudyExportCardsController extends Controller
{
    public function __invoke(Request $request, ListStudyExportCardsAction $listStudyExportCards): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return CardResource::collection(
            $listStudyExportCards->handle($user->id),
        );
    }
}
