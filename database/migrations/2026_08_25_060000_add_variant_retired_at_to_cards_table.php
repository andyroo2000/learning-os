<?php

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->timestamp('variant_retired_at')->nullable()->after('variant_unlocked_at');
        });

        // Preserve families graduated before the dedicated signal existed. Every
        // suspended/locked stage below an available final stage is retired unless a
        // later locked stage is still active (not suspended).
        DB::table('cards')
            ->whereNotNull('variant_group_id')
            ->where('variant_status', VocabVariantStatus::Locked->value)
            ->where('study_status', CardStudyStatus::Suspended->value)
            ->whereNull('deleted_at')
            ->whereExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('cards as available_later_card')
                    ->join(
                        'decks as available_later_deck',
                        'available_later_deck.id',
                        '=',
                        'available_later_card.deck_id',
                    )
                    ->join('decks as current_available_deck', function ($join): void {
                        $join->on('current_available_deck.id', '=', 'cards.deck_id');
                    })
                    ->whereColumn('available_later_card.variant_group_id', 'cards.variant_group_id')
                    ->whereColumn('available_later_deck.user_id', 'current_available_deck.user_id')
                    ->whereColumn('available_later_card.variant_stage', '>', 'cards.variant_stage')
                    ->where('available_later_card.variant_status', VocabVariantStatus::Available->value)
                    ->whereNull('available_later_deck.deleted_at')
                    ->whereNull('current_available_deck.deleted_at')
                    ->whereNull('available_later_card.deleted_at');
            })
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('cards as locked_later_card')
                    ->join(
                        'decks as locked_later_deck',
                        'locked_later_deck.id',
                        '=',
                        'locked_later_card.deck_id',
                    )
                    ->join('decks as current_locked_deck', function ($join): void {
                        $join->on('current_locked_deck.id', '=', 'cards.deck_id');
                    })
                    ->whereColumn('locked_later_card.variant_group_id', 'cards.variant_group_id')
                    ->whereColumn('locked_later_deck.user_id', 'current_locked_deck.user_id')
                    ->whereColumn('locked_later_card.variant_stage', '>', 'cards.variant_stage')
                    ->where('locked_later_card.variant_status', VocabVariantStatus::Locked->value)
                    ->where(function ($query): void {
                        $query
                            ->whereNull('locked_later_card.study_status')
                            ->orWhere(
                                'locked_later_card.study_status',
                                '!=',
                                CardStudyStatus::Suspended->value,
                            );
                    })
                    ->whereNull('locked_later_deck.deleted_at')
                    ->whereNull('current_locked_deck.deleted_at')
                    ->whereNull('locked_later_card.deleted_at');
            })
            ->update(['variant_retired_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropColumn('variant_retired_at');
        });
    }
};
