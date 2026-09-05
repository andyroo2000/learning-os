<?php

namespace Tests\Support\Content;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class ConvoLabAudioScriptsSourceTable
{
    public static function create(Builder $schema): void
    {
        $schema->create('audio_scripts', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('episodeId');
            $table->string('status');
            $table->string('imageStatus');
            $table->text('imageErrorMessage')->nullable();
            $table->string('voiceId');
            $table->string('voiceProvider');
            $table->json('generationMetadataJson')->nullable();
            $table->text('errorMessage')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
    }

    public static function seed(Connection $source, array $ids, string $created): void
    {
        $source->table('audio_scripts')->insert([
            'id' => $ids['script'], 'episodeId' => $ids['scriptEpisode'], 'status' => 'ready',
            'imageStatus' => 'ready', 'imageErrorMessage' => null, 'voiceId' => 'ja-JP-Neural2-B',
            'voiceProvider' => 'google', 'generationMetadataJson' => json_encode(['model' => 'test']),
            'errorMessage' => null, 'createdAt' => $created, 'updatedAt' => $created,
        ]);
    }
}
