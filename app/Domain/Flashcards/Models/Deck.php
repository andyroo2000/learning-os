<?php

namespace App\Domain\Flashcards\Models;

use Database\Factories\DeckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description'])]
class Deck extends Model
{
    /** @use HasFactory<DeckFactory> */
    use HasFactory, HasUlids;

    protected static function newFactory(): DeckFactory
    {
        return DeckFactory::new();
    }

    /**
     * @return HasMany<Card, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }
}
