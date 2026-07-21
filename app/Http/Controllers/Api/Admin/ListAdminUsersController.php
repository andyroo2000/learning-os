<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\ListAdminUsersAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminUsersRequest;
use App\Http\Resources\Admin\AdminUserResource;
use Illuminate\Http\JsonResponse;

class ListAdminUsersController extends Controller
{
    public function __invoke(ListAdminUsersRequest $request, ListAdminUsersAction $action): JsonResponse
    {
        $users = $action->handle($request->page(), $request->limit(), $request->search());

        return response()->json([
            'users' => AdminUserResource::collection($users->items())->resolve($request),
            'pagination' => [
                'page' => $users->currentPage(),
                'limit' => $users->perPage(),
                'total' => $users->total(),
                'pages' => $users->lastPage(),
            ],
        ]);
    }
}
