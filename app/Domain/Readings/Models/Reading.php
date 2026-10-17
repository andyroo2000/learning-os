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
        return ['document' => 'array', 'revision' => 'integer', 'page_numbers' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (Reading $reading): void {
            if ($reading->isDirty('document')) {
                $reading->title = $reading->document['title'];
                $reading->author = $reading->document['author'];
                $reading->page_numbers = array_column($reading->document['pages'], 'number');
                if ($reading->exists && ! $reading->isDirty('revision')) {
                    $reading->revision++;
                }
            }
        });
    }

    public function summary(): array
    {
        return [
            'id' => $this->id,
            'revision' => $this->revision,
            'title' => $this->title,
            'author' => $this->author,
            'pageCount' => count($this->page_numbers ?? []),
        ];
    }
}
