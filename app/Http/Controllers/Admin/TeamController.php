<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function index(): View
    {
        return view('admin.teams.index', ['teams' => Team::withCount('players')->orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('admin.teams.form', ['team' => new Team(['color' => '#1d4ed8'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = Team::create($this->validated($request));

        return redirect()->route('admin.teams.edit', $team)->with('status', 'Team aangemaakt. Voeg nu de spelers toe.');
    }

    public function edit(Team $team): View
    {
        return view('admin.teams.form', ['team' => $team->load('players')]);
    }

    public function update(Request $request, Team $team): RedirectResponse
    {
        $team->update($this->validated($request));

        return redirect()->route('admin.teams.edit', $team)->with('status', 'Team opgeslagen.');
    }

    public function destroy(Team $team): RedirectResponse
    {
        $inUse = Game::where('home_team_id', $team->id)->orWhere('away_team_id', $team->id)->exists();
        if ($inUse) {
            return back()->withErrors(['team' => 'Dit team speelt in een of meer wedstrijden en kan niet worden verwijderd.']);
        }
        $team->delete();

        return redirect()->route('admin.teams.index')->with('status', 'Team verwijderd.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'short_name' => ['required', 'string', 'max:4'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [], [
            'name' => 'naam',
            'short_name' => 'afkorting',
            'color' => 'kleur',
        ]);
        $data['short_name'] = mb_strtoupper($data['short_name']);
        $data['show_full_names'] = $request->boolean('show_full_names');

        return $data;
    }
}
