<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Serverkant van offline scoren: idempotent opslaan met client_id en veilig ongedaan maken.
 */
class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();

        $away = Team::create(['name' => 'Uit', 'short_name' => 'UIT']);
        $home = Team::create(['name' => 'Thuis', 'short_name' => 'THU']);
        foreach ([$away, $home] as $team) {
            foreach (range(1, 9) as $n) {
                $team->players()->create(['first_name' => 'Speler'.$n, 'number' => (string) $n]);
            }
        }
        $this->game = Game::create([
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'starts_at' => now(),
            'away_pitcher_id' => $away->players()->first()->id,
            'home_pitcher_id' => $home->players()->first()->id,
        ]);
        foreach (['away' => $away, 'home' => $home] as $side => $team) {
            foreach ($team->players()->get()->values() as $i => $player) {
                $this->game->lineups()->create(['side' => $side, 'batting_order' => $i + 1, 'player_id' => $player->id]);
            }
        }
        $this->actingAs($this->user);
    }

    private function sendPitch(string $pitch, ?string $clientId = null)
    {
        return $this->postJson(route('admin.score.store', $this->game), array_filter([
            'type' => 'pitch',
            'payload' => ['pitch' => $pitch],
            'client_id' => $clientId,
        ]));
    }

    public function test_scorer_state_bevat_events_en_opstelling_voor_offline_rekenen(): void
    {
        $this->sendPitch('B', 'aaaaaaaa-0000-4000-8000-000000000001')->assertOk();

        $json = $this->getJson(route('admin.score.state', $this->game))->assertOk()->json();
        $this->assertCount(9, $json['setup']['lineups']['away']);
        $this->assertIsInt($json['setup']['pitchers']['home']);
        $this->assertSame('aaaaaaaa-0000-4000-8000-000000000001', $json['events'][0]['client_id']);
        $this->assertSame(['pitch' => 'B'], $json['events'][0]['payload']);
    }

    public function test_zelfde_client_id_twee_keer_versturen_slaat_maar_een_keer_op(): void
    {
        $id = 'bbbbbbbb-0000-4000-8000-000000000001';
        $this->sendPitch('B', $id)->assertOk()->assertJsonPath('balls', 1);
        $this->sendPitch('B', $id)->assertOk()->assertJsonPath('balls', 1);

        $this->assertSame(1, GameEvent::count());
    }

    public function test_ongedaan_maken_van_specifiek_event(): void
    {
        $this->sendPitch('B', 'cccccccc-0000-4000-8000-000000000001');
        $last = GameEvent::latest('id')->first();

        $this->deleteJson(route('admin.score.undo', $this->game), ['event_id' => $last->id])
            ->assertOk()->assertJsonPath('balls', 0);

        // Nog eens (bv. antwoord kwam niet aan): al weg, dus gewoon OK.
        $this->deleteJson(route('admin.score.undo', $this->game), ['event_id' => $last->id])->assertOk();
        $this->assertSame(0, GameEvent::count());
    }

    public function test_ongedaan_maken_geeft_conflict_als_er_nieuwere_events_zijn(): void
    {
        $this->sendPitch('B');
        $first = GameEvent::first();
        $this->sendPitch('CS'); // bv. door een tweede scorer

        $this->deleteJson(route('admin.score.undo', $this->game), ['event_id' => $first->id])->assertStatus(409);
        $this->assertSame(2, GameEvent::count());
    }

    public function test_ongeldige_client_id_wordt_geweigerd(): void
    {
        $this->sendPitch('B', '<script>')->assertStatus(422);
    }

    public function test_vers_csrf_token_ophalen(): void
    {
        $this->getJson(route('admin.csrf'))->assertOk()->assertJsonStructure(['token']);
    }

    public function test_offline_bestanden_zijn_bereikbaar(): void
    {
        foreach (['sw.js', 'manifest.webmanifest', 'js/engine.js', 'icon.svg'] as $file) {
            $this->assertFileExists(public_path($file));
        }
        $this->get(route('admin.score.show', $this->game))
            ->assertOk()
            ->assertSee('js/engine.js', false)
            ->assertSee('serviceWorker', false);
    }
}
