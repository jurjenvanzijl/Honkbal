<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'short_name', 'color', 'show_full_names'])]
class Team extends Model
{
    protected function casts(): array
    {
        return ['show_full_names' => 'boolean'];
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class)->orderByRaw('number + 0')->orderBy('first_name');
    }
}
