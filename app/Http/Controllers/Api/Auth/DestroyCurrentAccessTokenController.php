<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\RevokeCurrentAccessTokenAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DestroyCurrentAccessTokenController extends Controller
{
    public function __invoke(Request $request, RevokeCurrentAccessTokenAction $revokeCurrentAccessToken): Response
    {
        $user = $request->user();

        // Narrow the auth contract before reading Sanctum token state from the app user model.
        abort_unless($user instanceof User, 401);

        $revokeCurrentAccessToken->handle($user);

        return response()->noContent();
    }
}
