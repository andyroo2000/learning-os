<?php

use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const QUEUE_INDEX_NAME = 'cards_new_lane_queue_idx';

    public function up(): void
    {
        Schema::create('card_introduction_cohorts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_kind', 32);
            $table->string('label', 120)->nullable();
            $table->string('source_reference', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at', 'id'], 'card_intro_cohorts_user_created_idx');
            $table->unique(
                ['user_id', 'source_kind', 'source_reference'],
                'card_intro_cohorts_source_ref_unique',
            );
        });

        Schema::table('cards', function (Blueprint $table): void {
            $table->foreignUlid('introduction_cohort_id')
                ->nullable()
                ->after('deck_id')
                ->constrained('card_introduction_cohorts')
                ->nullOnDelete();
            $table->string('selection_policy', 32)
                ->default(CardSelectionPolicy::Standard->value)
                ->after('introduction_cohort_id');
            $table->timestamp('priority_until')->nullable()->after('selection_policy');

            $table->index(
                [
                    'deck_id',
                    'deleted_at',
                    'study_status',
                    'selection_policy',
                    'priority_until',
                    'new_queue_position',
                    'id',
                ],
                self::QUEUE_INDEX_NAME,
            );
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropIndex(self::QUEUE_INDEX_NAME);
            $table->dropConstrainedForeignId('introduction_cohort_id');
            $table->dropColumn(['selection_policy', 'priority_until']);
        });

        Schema::dropIfExists('card_introduction_cohorts');
    }
};
