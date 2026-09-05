<?php

namespace Tests\Support\Content;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class ConvoLabSpeakerSourceTable
{
    public static function create(Builder $schema): void
    {
        $schema->create('Speaker', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('dialogueId');
            $table->string('name');
            $table->string('voiceId');
            $table->string('voiceProvider')->nullable();
            $table->string('proficiency');
            $table->string('tone');
            $table->string('gender')->nullable();
            $table->string('color')->nullable();
            $table->text('avatarUrl')->nullable();
        });
    }

    public static function seed(Connection $source, array $ids, string $created): void
    {
        $source->table('Speaker')->insert([
            'id' => $ids['speaker'], 'dialogueId' => $ids['dialogue'], 'name' => 'Aki',
            'voiceId' => 'ja-JP-Neural2-B', 'voiceProvider' => 'google',
            'proficiency' => 'beginner', 'tone' => 'polite', 'gender' => 'female',
            'color' => 'cyan', 'avatarUrl' => '/api/avatars/voices/aki.jpg',
        ]);
    }
}
