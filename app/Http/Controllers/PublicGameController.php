<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Scoring\GameStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class PublicGameController extends Controller
{
    public function __construct(private GameStateService $service)
    {
    }

    public function index(): View
    {
        $games = Game::with(['homeTeam', 'awayTeam'])
            ->where('starts_at', '>=', now()->subDays(14))
            ->orderByRaw("CASE status WHEN 'live' THEN 0 WHEN 'scheduled' THEN 1 ELSE 2 END")
            ->orderBy('starts_at')
            ->get();

        return view('public.index', ['games' => $games]);
    }

    public function show(string $slug): View
    {
        $game = $this->find($slug);

        return view('public.game', [
            'game' => $game,
            'youtubeId' => $game->youtubeId(),
        ]);
    }

    /**
     * Openbare stand, met vertraging zodat het scorebord gelijk loopt met de stream.
     */
    public function state(string $slug): JsonResponse
    {
        $game = $this->find($slug);
        $until = $game->score_delay_seconds > 0 ? now()->subSeconds($game->score_delay_seconds) : null;

        return $this->json($this->service->state($game, public: true, until: $until));
    }

    public function overlay(string $slug): View
    {
        return view('public.overlay', ['game' => $this->find($slug)]);
    }

    /**
     * Overlay zonder vertraging: die wordt in de stream zelf gemonteerd en loopt dus al gelijk.
     */
    public function overlayState(string $slug): JsonResponse
    {
        $state = $this->service->state($this->find($slug), public: true);

        return $this->json(array_intersect_key($state, array_flip([
            'inning', 'half', 'outs', 'balls', 'strikes', 'bases', 'score', 'game',
        ])));
    }

    private function find(string $slug): Game
    {
        return Game::with(['homeTeam', 'awayTeam'])->where('slug', $slug)->firstOrFail();
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store');
    }
}
