<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Japanese\Actions\DisconnectWaniKaniAction;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DisconnectWaniKaniController extends Controller
{
    public function __invoke(Request $request, DisconnectWaniKaniAction $disconnect): Response
    {
        $disconnect->handle(AuthenticatedUser::id($request));

        return response()->noContent();
    }
}
