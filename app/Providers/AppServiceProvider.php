<?php

namespace App\Providers;

use App\Domain\Flashcards\Models\Card;
use App\Policies\CardPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keep policy wiring explicit while the API ownership model is still being shaped.
        Gate::policy(Card::class, CardPolicy::class);
    }
}
