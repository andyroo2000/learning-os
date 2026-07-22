<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\SendPasswordResetLinkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendPasswordResetLinkRequest;
use Illuminate\Http\Response;

class SendPasswordResetLinkController extends Controller
{
    public function __invoke(SendPasswordResetLinkRequest $request, SendPasswordResetLinkAction $sendPasswordResetLink): Response
    {
        $sendPasswordResetLink->handle($request->validated('email'));

        // The queued job performs account lookup and mail delivery away from the public timing boundary.
        return response()->noContent();
    }
}
