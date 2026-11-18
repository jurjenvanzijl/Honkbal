<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demodata voor lokaal testen. Alle namen zijn verzonnen.
 * Gebruik: php artisan migrate --seed  (inloggen: demo@example.com / demo-wachtwoord)
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo Scorer', 'password' => 'demo-wachtwoord'],
        );

        $home = Team::create(['name' => 'Demo Thuis', 'short_name' => 'DTH', 'color' => '#0b2545']);
        $away = Team::create(['name' => 'Demo Uit', 'short_name' => 'DUI', 'color' => '#b3001b']);

        $names = ['Anna', 'Bram', 'Chris', 'Daan', 'Eva', 'Finn', 'Gijs', 'Hugo', 'Iris', 'Jens', 'Kim'];
        foreach ([$home, $away] as $t => $team) {
            foreach ($names as $i => $name) {
                $team->players()->create(['first_name' => $name, 'last_name' => 'Test', 'number' => (string) ($i + 1 + $t * 20)]);
            }
        }

        $game = Game::create([
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'starts_at' => now()->addDay()->setTime(14, 0),
            'location' => 'Demoveld',
            'innings_planned' => 9,
        ]);

        $positions = ['CF', 'SS', '2B', '1B', '3B', 'LF', 'RF', 'C', 'P'];
        foreach (['home' => $home, 'away' => $away] as $side => $team) {
            $players = $team->players()->get();
            foreach ($positions as $i => $position) {
                $game->lineups()->create([
                    'side' => $side, 'batting_order' => $i + 1, 'player_id' => $players[$i]->id, 'position' => $position,
                ]);
            }
            $game->{$side.'_pitcher_id'} = $players[8]->id;
        }
        $game->save();
    }
}
