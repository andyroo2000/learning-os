<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('readings', function (Blueprint $table): void {
            $table->string('title', 200)->default('');
            $table->string('author', 200)->default('');
            $table->json('page_numbers')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->index(['user_id', 'created_at', 'id']);
        });
        DB::table('readings')->orderBy('id')->chunkById(50, function ($rows): void {
            foreach ($rows as $row) {
                $document = json_decode($row->document, true, 512, JSON_THROW_ON_ERROR);
                DB::table('readings')->where('id', $row->id)->update(['title' => $document['title'], 'author' => $document['author'],
                    'page_numbers' => json_encode(array_column($document['pages'], 'number'), JSON_THROW_ON_ERROR)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('readings', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'created_at', 'id']);
            $table->dropColumn(['title', 'author', 'page_numbers', 'revision']);
        });
    }
};
