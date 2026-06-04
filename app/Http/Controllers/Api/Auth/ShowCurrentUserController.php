<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\CurrentUserResource;
use App\Models\User;
use Illuminate\Http\Request;

class ShowCurrentUserController extends Controller
{
    public function __invoke(Request $request): CurrentUserResource
    {
        /** @var User $user */
        $user = $request->user();

        abort_if($user === null, 401);

        return CurrentUserResource::make($user);
    }
}
