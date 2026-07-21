<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\ListAdminInviteCodesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminInviteCodesRequest;
use App\Http\Resources\Admin\AdminInviteCodeResource;
use Illuminate\Http\JsonResponse;

class ListAdminInviteCodesController extends Controller
{
    public function __invoke(
        ListAdminInviteCodesRequest $request,
        ListAdminInviteCodesAction $action,
    ): JsonResponse {
        $inviteCodes = $action->handle($request->page(), $request->limit());

        return response()
            ->json(AdminInviteCodeResource::collection($inviteCodes->items())->resolve($request))
            ->withHeaders([
                'X-Pagination-Page' => (string) $inviteCodes->currentPage(),
                'X-Pagination-Limit' => (string) $inviteCodes->perPage(),
                'X-Pagination-Total' => (string) $inviteCodes->total(),
                'X-Pagination-Pages' => (string) $inviteCodes->lastPage(),
            ]);
    }
}
