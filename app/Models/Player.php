<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['first_name', 'last_name', 'number', 'position', 'active'])]
class Player extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** Volledige naam, voor beheer en de scorer. */
    public function fullName(): string
    {
        $name = trim($this->first_name.' '.$this->last_name);

        return $this->number !== null && $this->number !== '' ? "#{$this->number} {$name}" : $name;
    }

    /**
     * Naam op openbare pagina's. Zonder toestemming (team-instelling) alleen voornaam + rugnummer.
     */
    public function publicName(): string
    {
        if ($this->team?->show_full_names) {
            return $this->fullName();
        }
        $number = $this->number !== null && $this->number !== '' ? "#{$this->number} " : '';

        return $number.$this->first_name;
    }
}
