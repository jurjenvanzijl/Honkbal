<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameLineup;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    /**
     * Snel meerdere spelers toevoegen: één per regel, "rugnummer voornaam achternaam".
     * Alleen een rugnummer mag ook (handig voor tegenstanders).
     */
    public function store(Request $request, Team $team): RedirectResponse
    {
        $request->validate(['players' => ['required', 'string', 'max:5000']], [], ['players' => 'spelers']);

        $added = 0;
        foreach (preg_split('/\R/', $request->string('players')) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $number = null;
            if (preg_match('/^#?(\d{1,3})\s*(.*)$/u', $line, $m)) {
                $number = $m[1];
                $line = trim($m[2]);
            }
            $parts = preg_split('/\s+/', $line, 2);
            $team->players()->create([
                'number' => $number,
                'first_name' => mb_substr($parts[0] !== '' ? $parts[0] : 'Speler', 0, 100),
                'last_name' => isset($parts[1]) ? mb_substr($parts[1], 0, 100) : null,
            ]);
            $added++;
        }

        return back()->with('status', "{$added} speler(s) toegevoegd.");
    }

    public function update(Request $request, Player $player): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'number' => ['nullable', 'regex:/^\d{1,3}$/'],
            'position' => ['nullable', 'string', 'max:3'],
        ], [], ['first_name' => 'voornaam', 'last_name' => 'achternaam', 'number' => 'rugnummer']);
        $data['active'] = $request->boolean('active');
        $player->update($data);

        return back()->with('status', 'Speler opgeslagen.');
    }

    public function destroy(Player $player): RedirectResponse
    {
        if (GameLineup::where('player_id', $player->id)->exists()) {
            // Spelers in een opstelling houden we voor de statistieken; zet ze op inactief.
            $player->update(['active' => false]);

            return back()->with('status', 'Speler staat in een opstelling en is op inactief gezet.');
        }
        $player->delete();

        return back()->with('status', 'Speler verwijderd.');
    }
}
