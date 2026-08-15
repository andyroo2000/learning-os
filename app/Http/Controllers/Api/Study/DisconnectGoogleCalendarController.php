<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Calendar\Actions\DisconnectGoogleCalendarAction;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DisconnectGoogleCalendarController extends Controller
{
    public function __invoke(Request $request, DisconnectGoogleCalendarAction $disconnect): Response
    {
        $disconnect->handle(AuthenticatedUser::id($request));

        return response()->noContent();
    }
}
