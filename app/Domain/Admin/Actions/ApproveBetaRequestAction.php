<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\ConvoLabBetaRequest;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;

final class ApproveBetaRequestAction
{
    public function __construct(private readonly CreateAdminInviteCodeAction $createInvite) {}

    public function handle(string $id, string $actor): ConvoLabBetaRequest
    {
        $id = ConvoLabUserId::normalize($id);
        $actor = ConvoLabUserId::normalize($actor);

        return DB::transaction(function () use ($id, $actor): ConvoLabBetaRequest {
            $request = ConvoLabBetaRequest::query()->lockForUpdate()->findOrFail($id);
            if ($request->approved_at === null) {
                $request->invite_code_id = $this->createInvite->handle(null)->id;
                $request->approved_by = $actor;
                $request->approved_at = now();
                $request->save();
            }

            return $request;
        });
    }
}
