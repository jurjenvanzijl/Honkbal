<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'home_team_id', 'away_team_id', 'starts_at', 'location', 'innings_planned',
    'youtube_url', 'score_delay_seconds', 'home_pitcher_id', 'away_pitcher_id',
])]
class Game extends Model
{
    public const STATUSES = ['scheduled' => 'Gepland', 'live' => 'Live', 'final' => 'Afgelopen'];

    protected static function booted(): void
    {
        static::creating(function (Game $game) {
            $game->slug ??= Str::lower(Str::random(10));
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'innings_planned' => 'integer',
            'score_delay_seconds' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function lineups(): HasMany
    {
        return $this->hasMany(GameLineup::class)->orderBy('batting_order');
    }

    public function events(): HasMany
    {
        return $this->hasMany(GameEvent::class)->orderBy('id');
    }

    public function team(string $side): Team
    {
        return $side === 'home' ? $this->homeTeam : $this->awayTeam;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Haalt het video-ID uit een YouTube-link (watch, youtu.be, live, embed, shorts).
     */
    public function youtubeId(): ?string
    {
        return self::parseYoutubeId($this->youtube_url);
    }

    public static function parseYoutubeId(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $pattern = '~(?:youtube\.com/(?:watch\?(?:.*&)?v=|live/|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~';

        return preg_match($pattern, $url, $m) ? $m[1] : null;
    }
}
