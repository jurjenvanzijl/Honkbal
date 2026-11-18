<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Scoring\GameEngine;
use App\Scoring\GameStateService;
use App\Scoring\InvalidPlayException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ScoreController extends Controller
{
    public function __construct(private GameStateService $service)
    {
    }

    public function show(Game $game): View
    {
        $game->load(['homeTeam.players', 'awayTeam.players']);

        return view('admin.score', ['game' => $game]);
    }

    public function state(Game $game): JsonResponse
    {
        return response()->json($this->service->state($game));
    }

    public function store(Request $request, Game $game): JsonResponse
    {
        if ($game->status === 'final') {
            return response()->json(['message' => 'De wedstrijd is afgelopen. Heropen hem eerst.'], 422);
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(['pitch', 'play', 'runner', 'pitcher', 'sub'])],
            'payload' => ['required', 'array'],
            'payload.pitch' => ['sometimes', Rule::in(GameEngine::PITCHES)],
            'payload.result' => ['sometimes', Rule::in(GameEngine::PLAY_RESULTS)],
            'payload.action' => ['sometimes', Rule::in(GameEngine::RUNNER_ACTIONS)],
            'payload.batter_to' => ['sometimes', 'integer', 'between:0,4'],
            'payload.runners' => ['sometimes', 'array', 'max:3'],
            'payload.runners.*' => ['integer', 'between:0,4'],
            'payload.fielders' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z\- ]*$/'],
            'payload.rbi' => ['sometimes', 'integer', 'between:0,4'],
            'payload.side' => ['sometimes', Rule::in(['home', 'away'])],
            'payload.slot' => ['sometimes', 'integer', 'between:0,20'],
            'payload.player_id' => ['sometimes', 'integer', Rule::exists('players', 'id')->whereIn('team_id', [$game->home_team_id, $game->away_team_id])],
        ]);

        // Alleen bekende velden opslaan.
        $payload = array_intersect_key($data['payload'], array_flip([
            'pitch', 'result', 'action', 'batter_to', 'runners', 'fielders', 'rbi', 'side', 'slot', 'player_id',
        ]));

        try {
            $this->service->record($game, $data['type'], $payload, $request->user()->id);
        } catch (InvalidPlayException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->service->state($game->refresh()));
    }

    public function undo(Game $game): JsonResponse
    {
        if ($game->status === 'final') {
            return response()->json(['message' => 'De wedstrijd is afgelopen. Heropen hem eerst.'], 422);
        }
        if (! $this->service->undo($game)) {
            return response()->json(['message' => 'Er is niets om ongedaan te maken.'], 422);
        }

        return response()->json($this->service->state($game->refresh()));
    }

    public function status(Request $request, Game $game): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Game::STATUSES))]]);
        $game->forceFill(['status' => $data['status']])->save();

        if ($request->expectsJson()) {
            return response()->json($this->service->state($game));
        }

        return back()->with('status', 'Status bijgewerkt: '.$game->statusLabel().'.');
    }
}
