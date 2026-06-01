<?php

namespace App\Providers;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Policies\CardPolicy;
use App\Policies\DeckPolicy;
use App\Policies\MediaAssetPolicy;
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
        Gate::policy(Deck::class, DeckPolicy::class);
        Gate::policy(MediaAsset::class, MediaAssetPolicy::class);
    }
}
