<?php

namespace App\Domain\Readings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Reading extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['document' => 'array'];
    }

    public function summary(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->document['title'],
            'author' => $this->document['author'],
            'pageCount' => count($this->document['pages']),
        ];
    }
}
