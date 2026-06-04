<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\RevokeAccessTokenAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DestroyAccessTokenController extends Controller
{
    public function __invoke(Request $request, string $tokenId, RevokeAccessTokenAction $revokeAccessToken): Response
    {
        $user = $request->user();

        // Narrow the auth contract before deleting app-owned token state.
        abort_unless($user instanceof User, 401);

        $revokeAccessToken->handle($user, (int) $tokenId);

        return response()->noContent();
    }
}
