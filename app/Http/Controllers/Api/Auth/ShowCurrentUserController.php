<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\CurrentUserResource;
use Illuminate\Http\Request;

class ShowCurrentUserController extends Controller
{
    public function __invoke(Request $request): CurrentUserResource
    {
        return CurrentUserResource::make($request->user());
    }
}
