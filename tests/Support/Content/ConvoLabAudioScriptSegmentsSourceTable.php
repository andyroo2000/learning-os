<?php

namespace Tests\Support\Content;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class ConvoLabAudioScriptSegmentsSourceTable
{
    public static function create(Builder $schema): void
    {
        $schema->create('audio_script_segments', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('scriptId');
            $table->integer('order');
            $table->text('text');
            $table->text('reading')->nullable();
            $table->text('translation');
            $table->text('imagePrompt')->nullable();
            $table->string('imageStatus');
            $table->text('imageErrorMessage')->nullable();
            $table->string('imageMediaId')->nullable();
            $table->dateTime('imageGeneratedAt')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
    }

    public static function seed(Connection $source, array $ids, string $created): void
    {
        $source->table('audio_script_segments')->insert([
            'id' => $ids['segment'], 'scriptId' => $ids['script'], 'order' => 1,
            'text' => '猫です。', 'reading' => 'ねこです。', 'translation' => 'It is a cat.',
            'imagePrompt' => 'A cat', 'imageStatus' => 'ready', 'imageErrorMessage' => null,
            'imageMediaId' => $ids['media'], 'imageGeneratedAt' => $created,
            'metadata' => json_encode(['scene' => 1]), 'createdAt' => $created, 'updatedAt' => $created,
        ]);
    }
}
