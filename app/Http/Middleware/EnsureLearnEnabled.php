<?php

namespace App\Http\Middleware;

use App\Domain\FeatureFlags\Actions\GetEffectiveFeatureFlagsAction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLearnEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user()?->id;
        abort_unless($userId && app(GetEffectiveFeatureFlagsAction::class)->enabled($userId, 'learnEnabled'), 404);

        return $next($request);
    }
}
