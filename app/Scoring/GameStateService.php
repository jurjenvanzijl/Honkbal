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
     * State voor het scoorscherm: inclusief de ruwe events en de opstelling, zodat de browser
     * de wedstrijd ook offline kan doorrekenen (public/js/engine.js).
     */
    public function scorerState(Game $game): array
    {
        $state = $this->state($game);
        $state['setup'] = $this->setup($game);
        $state['events'] = $game->events()->get(['id', 'type', 'payload', 'client_id'])
            ->map(fn (GameEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'payload' => $e->payload,
                'client_id' => $e->client_id,
            ])->all();

        return $state;
    }

    /**
     * Voegt een event toe nadat de engine het heeft gevalideerd.
     * Met een client_id is dit idempotent: een tweede keer versturen doet niets.
     *
     * @throws InvalidPlayException
     */
    public function record(Game $game, string $type, array $payload, ?int $userId = null, ?string $clientId = null): GameEvent
    {
        return DB::transaction(function () use ($game, $type, $payload, $userId, $clientId) {
            // Blokkeer de wedstrijdrij zodat twee scorers niet tegelijk schrijven.
            Game::whereKey($game->id)->lockForUpdate()->first();

            if ($clientId && $existing = $game->events()->where('client_id', $clientId)->first()) {
                return $existing;
            }

            $engine = $this->engine($game);
            $engine->apply(['type' => $type, 'payload' => $payload]);

            $event = $game->events()->create([
                'type' => $type,
                'payload' => $payload,
                'client_id' => $clientId,
                'user_id' => $userId,
            ]);

            $this->cacheSummary($game, $engine->state());

            return $event;
        });
    }

    /**
     * Maakt het laatste event ongedaan.
     *
     * Met $eventId (vanuit de offline-wachtrij) alleen als dat event nog het laatste is:
     * bestaat het niet meer, dan was het al ongedaan gemaakt (geen fout); staan er inmiddels
     * nieuwere events achter, dan is dat een conflict.
     *
     * @return bool false als er niets ongedaan te maken was
     *
     * @throws SyncConflictException
     */
    public function undo(Game $game, ?int $eventId = null): bool
    {
        return DB::transaction(function () use ($game, $eventId) {
            Game::whereKey($game->id)->lockForUpdate()->first();
            $last = $game->events()->reorder('id', 'desc')->first();

            if ($eventId !== null) {
                if (! $game->events()->whereKey($eventId)->exists()) {
                    return true;
                }
                if ($last->id !== $eventId) {
                    throw new SyncConflictException('Er zijn inmiddels nieuwere acties opgeslagen (bijvoorbeeld door een andere scorer). Ongedaan maken is niet doorgevoerd.');
                }
            }

            if (! $last) {
                return false;
            }
            $last->delete();
            $this->cacheSummary($game, $this->engine($game)->state());

            return true;
        });
    }

    /** @return array{lineups: array{away: list<int>, home: list<int>}, pitchers: array{away: ?int, home: ?int}} */
    public function setup(Game $game): array
    {
        $game->loadMissing('lineups');
        $lineups = ['away' => [], 'home' => []];
        foreach ($game->lineups as $row) {
            $lineups[$row->side][] = (int) $row->player_id;
        }

        return [
            'lineups' => $lineups,
            'pitchers' => [
                'away' => $game->away_pitcher_id ? (int) $game->away_pitcher_id : null,
                'home' => $game->home_pitcher_id ? (int) $game->home_pitcher_id : null,
            ],
        ];
    }

    private function freshEngine(Game $game, bool $public): GameEngine
    {
        $setup = $this->setup($game);

        return new GameEngine($setup['lineups'], $setup['pitchers'], $this->playerNames($game, $public));
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
