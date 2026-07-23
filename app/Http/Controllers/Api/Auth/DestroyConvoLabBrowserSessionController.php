<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\EndConvoLabBrowserSessionAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DestroyConvoLabBrowserSessionController extends Controller
{
    public function __invoke(Request $request, EndConvoLabBrowserSessionAction $action): Response
    {
        $action->handle($request);

        return response()->noContent();
    }
}
