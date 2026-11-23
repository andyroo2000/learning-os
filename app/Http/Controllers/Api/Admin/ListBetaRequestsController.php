<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Models\ConvoLabBetaRequest;
use App\Http\Requests\Admin\ListBetaRequestsRequest;
use App\Http\Resources\Admin\BetaRequestResource;
use Illuminate\Http\JsonResponse;

final class ListBetaRequestsController
{
    public function __invoke(ListBetaRequestsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $status = $data['status'] ?? 'pending';
        $query = ConvoLabBetaRequest::query()->with('inviteCode');
        if ($status === 'pending') {
            $query->whereNull('invitation_sent_at');
        } elseif ($status === 'invited') {
            $query->whereNotNull('invitation_sent_at');
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(25, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'requests' => BetaRequestResource::collection($page->items()),
            'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }
}
