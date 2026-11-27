<?php

namespace App\Http\Middleware;

use App\Domain\FeatureFlags\Actions\GetFeatureFlagsAction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLearnEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user()?->adminUserProjection()->where('role', 'admin')->exists();
        abort_unless($admin || app(GetFeatureFlagsAction::class)->handle()->learnEnabled, 404);

        return $next($request);
    }
}
