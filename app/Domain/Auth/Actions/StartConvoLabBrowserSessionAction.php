<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class StartConvoLabBrowserSessionAction
{
    public function handle(Request $request, AdminUserProjection $account): void
    {
        $user = $account->user()->firstOrFail();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
    }
}
