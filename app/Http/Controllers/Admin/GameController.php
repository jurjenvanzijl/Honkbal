<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class GameController extends Controller
{
    public const MAX_LINEUP = 12;

    public const POSITIONS = ['P', 'C', '1B', '2B', '3B', 'SS', 'LF', 'CF', 'RF', 'DH', 'EH'];

    public function index(): View
    {
        return view('admin.dashboard', [
            'games' => Game::with(['homeTeam', 'awayTeam'])->orderByDesc('starts_at')->limit(50)->get(),
            'teamCount' => Team::count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.games.form', [
            'game' => new Game(['starts_at' => now()->setTime(14, 0), 'innings_planned' => 9, 'score_delay_seconds' => 0]),
            'teams' => Team::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $game = Game::create($this->validated($request));

        return redirect()->route('admin.games.lineup', $game)->with('status', 'Wedstrijd aangemaakt. Vul nu de opstellingen in.');
    }

    public function edit(Game $game): View
    {
        return view('admin.games.form', ['game' => $game, 'teams' => Team::orderBy('name')->get()]);
    }

    public function update(Request $request, Game $game): RedirectResponse
    {
        $data = $this->validated($request);
        if ($game->events()->exists()) {
            // Teams wisselen na de eerste pitch zou de hele wedstrijd ongeldig maken.
            unset($data['home_team_id'], $data['away_team_id']);
        }
        $game->update($data);

        return redirect()->route('admin.games.edit', $game)->with('status', 'Wedstrijd opgeslagen.');
    }

    public function destroy(Game $game): RedirectResponse
    {
        $game->delete();

        return redirect()->route('admin.dashboard')->with('status', 'Wedstrijd verwijderd.');
    }

    public function lineup(Game $game): View
    {
        $game->load(['homeTeam.players', 'awayTeam.players', 'lineups']);

        return view('admin.games.lineup', [
            'game' => $game,
            'locked' => $game->events()->exists(),
            'positions' => self::POSITIONS,
            'max' => self::MAX_LINEUP,
        ]);
    }

    public function updateLineup(Request $request, Game $game): RedirectResponse
    {
        if ($game->events()->exists()) {
            return back()->withErrors(['lineup' => 'De wedstrijd is al begonnen. Gebruik wissels in het scoorscherm.']);
        }

        $rules = [];
        foreach (['home', 'away'] as $side) {
            $teamId = $side === 'home' ? $game->home_team_id : $game->away_team_id;
            $playerRule = Rule::exists('players', 'id')->where('team_id', $teamId);
            $rules["lineup.$side"] = ['array', 'max:'.self::MAX_LINEUP];
            $rules["lineup.$side.*.player_id"] = ['nullable', 'integer', $playerRule];
            $rules["lineup.$side.*.position"] = ['nullable', Rule::in(self::POSITIONS)];
            $rules["{$side}_pitcher_id"] = ['nullable', 'integer', $playerRule];
        }
        $data = $request->validate($rules, [], [
            'home_pitcher_id' => 'werper thuisteam',
            'away_pitcher_id' => 'werper uitteam',
        ]);

        DB::transaction(function () use ($game, $data) {
            $game->lineups()->delete();
            foreach (['home', 'away'] as $side) {
                $order = 1;
                $seen = [];
                foreach ($data['lineup'][$side] ?? [] as $row) {
                    $playerId = $row['player_id'] ?? null;
                    if (! $playerId || isset($seen[$playerId])) {
                        continue; // lege of dubbele regels overslaan
                    }
                    $seen[$playerId] = true;
                    $game->lineups()->create([
                        'side' => $side,
                        'batting_order' => $order++,
                        'player_id' => $playerId,
                        'position' => $row['position'] ?? null,
                    ]);
                }
            }
            $game->update([
                'home_pitcher_id' => $data['home_pitcher_id'] ?? null,
                'away_pitcher_id' => $data['away_pitcher_id'] ?? null,
            ]);
        });

        return redirect()->route('admin.games.lineup', $game)->with('status', 'Opstellingen opgeslagen.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'home_team_id' => ['required', 'integer', 'exists:teams,id'],
            'away_team_id' => ['required', 'integer', 'exists:teams,id', 'different:home_team_id'],
            'starts_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:150'],
            'innings_planned' => ['required', 'integer', 'min:1', 'max:12'],
            'youtube_url' => ['nullable', 'url:https', 'max:255', function ($attribute, $value, $fail) {
                if ($value && ! Game::parseYoutubeId($value)) {
                    $fail('Dit lijkt geen geldige YouTube-link.');
                }
            }],
            'score_delay_seconds' => ['required', 'integer', 'min:0', 'max:120'],
        ], [
            'away_team_id.different' => 'Thuis- en uitteam moeten verschillen.',
        ], [
            'home_team_id' => 'thuisteam',
            'away_team_id' => 'uitteam',
            'starts_at' => 'aanvang',
            'location' => 'locatie',
            'innings_planned' => 'aantal innings',
            'youtube_url' => 'YouTube-link',
            'score_delay_seconds' => 'vertraging',
        ]);

        return $data;
    }
}
