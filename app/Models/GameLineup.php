<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['side', 'batting_order', 'player_id', 'position'])]
class GameLineup extends Model
{
    public $timestamps = false;

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
