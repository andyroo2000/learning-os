<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\ConvoLabCurrentUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowConvoLabBrowserCurrentUserController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('web');
        $account = $user->adminUserProjection()->firstOrFail();

        return response()->json(
            ConvoLabCurrentUserResource::make($account)->resolve($request),
        );
    }
}
