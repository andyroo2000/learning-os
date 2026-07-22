<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\DeleteCurrentUserAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DeleteCurrentUserController extends Controller
{
    public function __invoke(Request $request, DeleteCurrentUserAction $deleteUser): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $deleteUser->handle($user);

        return response()->noContent();
    }
}
