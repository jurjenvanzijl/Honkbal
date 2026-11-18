<?php

namespace App\Scoring;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Koppelt de database aan de GameEngine: bouwt de state op uit de opgeslagen events,
 * voegt nieuwe events toe en maakt het laatste event ongedaan.
 */
class GameStateService
{
    /**
     * @param  bool  $public  openbare namen (privacy-instelling van het team) gebruiken
     * @param  Carbon|null  $until  alleen events tot dit moment (voor vertraging t.o.v. de stream)
     */
    public function engine(Game $game, bool $public = false, ?Carbon $until = null): GameEngine
    {
        $engine = $this->freshEngine($game, $public);

        $query = $game->events();
        if ($until) {
            $query->where('created_at', '<=', $until);
        }
        foreach ($query->get() as $event) {
            $engine->apply(['id' => $event->id, 'type' => $event->type, 'payload' => $event->payload]);
        }

        return $engine;
    }

    public function state(Game $game, bool $public = false, ?Carbon $until = null): array
    {
        $state = $this->engine($game, $public, $until)->state();
        $state['game'] = [
            'status' => $game->status,
            'status_label' => $game->statusLabel(),
            'innings_planned' => $game->innings_planned,
            'teams' => [
                'away' => $this->teamInfo($game, 'away'),
                'home' => $this->teamInfo($game, 'home'),
            ],
        ];
        $state['players'] = $this->playerNames($game, $public);
        $state['player_sides'] = Player::whereIn('team_id', [$game->home_team_id, $game->away_team_id])
            ->pluck('team_id', 'id')
            ->map(fn ($teamId) => (int) $teamId === (int) $game->home_team_id ? 'home' : 'away')
            ->all();

        return $state;
    }

    /**
     * Voegt een event toe nadat de engine het heeft gevalideerd.
     *
     * @throws InvalidPlayException
     */
    public function record(Game $game, string $type, array $payload, ?int $userId = null): GameEvent
    {
        return DB::transaction(function () use ($game, $type, $payload, $userId) {
            // Blokkeer de wedstrijdrij zodat twee scorers niet tegelijk schrijven.
            Game::whereKey($game->id)->lockForUpdate()->first();

            $engine = $this->engine($game);
            $engine->apply(['type' => $type, 'payload' => $payload]);

            $event = $game->events()->create([
                'type' => $type,
                'payload' => $payload,
                'user_id' => $userId,
            ]);

            $this->cacheSummary($game, $engine->state());

            return $event;
        });
    }

    public function undo(Game $game): bool
    {
        return DB::transaction(function () use ($game) {
            Game::whereKey($game->id)->lockForUpdate()->first();
            $last = $game->events()->reorder('id', 'desc')->first();
            if (! $last) {
                return false;
            }
            $last->delete();
            $this->cacheSummary($game, $this->engine($game)->state());

            return true;
        });
    }

    private function freshEngine(Game $game, bool $public): GameEngine
    {
        $game->loadMissing('lineups');
        $lineups = ['away' => [], 'home' => []];
        foreach ($game->lineups as $row) {
            $lineups[$row->side][] = $row->player_id;
        }

        return new GameEngine(
            $lineups,
            ['away' => $game->away_pitcher_id, 'home' => $game->home_pitcher_id],
            $this->playerNames($game, $public),
        );
    }

    /** @return array<int,string> */
    private function playerNames(Game $game, bool $public): array
    {
        return Player::with('team')
            ->whereIn('team_id', [$game->home_team_id, $game->away_team_id])
            ->get()
            ->mapWithKeys(fn (Player $p) => [$p->id => $public ? $p->publicName() : $p->fullName()])
            ->all();
    }

    private function teamInfo(Game $game, string $side): array
    {
        $team = $game->team($side);

        return ['name' => $team->name, 'short_name' => $team->short_name, 'color' => $team->color];
    }

    private function cacheSummary(Game $game, array $state): void
    {
        $game->forceFill([
            'home_score' => $state['score']['home'],
            'away_score' => $state['score']['away'],
            'inning_label' => ($state['half'] === 'top' ? '▲' : '▼').' '.$state['inning'],
            'status' => $game->status === 'scheduled' ? 'live' : $game->status,
        ])->save();
    }
}
