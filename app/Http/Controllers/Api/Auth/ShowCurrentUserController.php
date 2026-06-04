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
        $user = $request->user();

        // Narrow the auth contract before the resource reads app-specific fields.
        abort_unless($user instanceof User, 401);

        return CurrentUserResource::make($user);
    }
}
