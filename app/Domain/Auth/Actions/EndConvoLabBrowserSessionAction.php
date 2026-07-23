<?php

namespace App\Domain\Auth\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class EndConvoLabBrowserSessionAction
{
    public function handle(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
