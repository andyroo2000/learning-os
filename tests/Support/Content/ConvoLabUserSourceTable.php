<?php

namespace Tests\Support\Content;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class ConvoLabUserSourceTable
{
    public static function create(Builder $schema): void
    {
        $schema->create('User', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('email');
        });
    }

    public static function seed(Connection $source, array $ids, string $created): void
    {
        $source->table('User')->insert(['id' => $ids['user'], 'email' => 'Ada@Example.com']);
    }
}
