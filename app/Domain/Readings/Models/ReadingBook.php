<?php

namespace App\Domain\Readings\Models;

use Illuminate\Database\Eloquent\Model;

final class ReadingBook extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    public function summary(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'revision' => $this->revision,
            'createdAt' => $this->created_at->toISOString()];
    }
}
