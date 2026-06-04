<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\ListAccessTokensAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\AccessTokenResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListAccessTokensController extends Controller
{
    public function __invoke(Request $request, ListAccessTokensAction $listAccessTokens): AnonymousResourceCollection
    {
        $user = $request->user();

        // Narrow the auth contract before querying app-owned token state.
        abort_unless($user instanceof User, 401);

        return AccessTokenResource::collection($listAccessTokens->handle($user));
    }
}
