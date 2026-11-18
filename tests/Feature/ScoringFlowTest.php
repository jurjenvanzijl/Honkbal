<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScoringFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** Maakt twee teams met 9 spelers en een wedstrijd met opstelling. */
    private function gameWithLineups(): Game
    {
        $away = Team::create(['name' => 'Uitploeg', 'short_name' => 'UIT', 'color' => '#aa0000']);
        $home = Team::create(['name' => 'Thuisploeg', 'short_name' => 'THU', 'color' => '#0000aa', 'show_full_names' => false]);

        $this->actingAs($this->user)
            ->post(route('admin.players.store', $away), ['players' => implode("\n", array_map(fn ($i) => "$i Uit$i Achter", range(1, 9)))])
            ->assertRedirect();
        $this->actingAs($this->user)
            ->post(route('admin.players.store', $home), ['players' => implode("\n", array_map(fn ($i) => "$i Thuis$i Achternaam", range(1, 9)))])
            ->assertRedirect();

        $this->actingAs($this->user)->post(route('admin.games.store'), [
            'away_team_id' => $away->id,
            'home_team_id' => $home->id,
            'starts_at' => '2026-11-21 14:00',
            'innings_planned' => 9,
            'score_delay_seconds' => 0,
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ])->assertRedirect();

        $game = Game::firstOrFail();
        $lineup = fn (Team $t) => $t->players()->get()->map(fn ($p) => ['player_id' => $p->id, 'position' => null])->all();

        $this->actingAs($this->user)->put(route('admin.games.lineup.update', $game), [
            'lineup' => ['away' => $lineup($away), 'home' => $lineup($home)],
            'away_pitcher_id' => $away->players()->first()->id,
            'home_pitcher_id' => $home->players()->first()->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        return $game->refresh();
    }

    public function test_beheer_vereist_inloggen(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->postJson(route('admin.score.store', 1), [])->assertUnauthorized();
    }

    public function test_inloggen(): void
    {
        $this->post('/inloggen', ['email' => $this->user->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_spelers_snel_toevoegen_met_alleen_rugnummer(): void
    {
        $team = Team::create(['name' => 'Tegenstander', 'short_name' => 'TEG']);
        $this->actingAs($this->user)
            ->post(route('admin.players.store', $team), ['players' => "12\n#7 Voornaam Achternaam\n\n"])
            ->assertRedirect();

        $players = $team->players()->get();
        $this->assertCount(2, $players);
        $this->assertSame('7', $players[0]->number);
        $this->assertSame('Achternaam', $players[0]->last_name);
        $this->assertSame('12', $players[1]->number);
    }

    public function test_volledige_flow_scoren_en_ongedaan_maken(): void
    {
        $game = $this->gameWithLineups();
        $this->actingAs($this->user);

        $this->getJson(route('admin.score.state', $game))->assertOk()->assertJsonPath('inning', 1);

        foreach (['B', 'B', 'B', 'B'] as $pitch) {
            $this->postJson(route('admin.score.store', $game), ['type' => 'pitch', 'payload' => ['pitch' => $pitch]])->assertOk();
        }
        $this->postJson(route('admin.score.store', $game), [
            'type' => 'play',
            'payload' => ['result' => 'HR', 'batter_to' => 4, 'runners' => ['1' => 4], 'fielders' => '7'],
        ])->assertOk()
            ->assertJsonPath('score.away', 2)
            ->assertJsonPath('game.status', 'live');

        $this->assertSame(2, $game->refresh()->away_score);
        $this->assertSame('live', $game->status);

        $this->deleteJson(route('admin.score.undo', $game))->assertOk()->assertJsonPath('score.away', 0);
        $this->assertSame(4, GameEvent::count());
    }

    public function test_ongeldige_actie_geeft_nette_foutmelding(): void
    {
        $game = $this->gameWithLineups();

        $this->actingAs($this->user)
            ->postJson(route('admin.score.store', $game), ['type' => 'runner', 'payload' => ['action' => 'SB', 'runners' => ['1' => 2]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Er staat geen loper op het 1e honk.');

        $this->assertSame(0, GameEvent::count());
    }

    public function test_afgelopen_wedstrijd_accepteert_geen_events(): void
    {
        $game = $this->gameWithLineups();
        $this->actingAs($this->user)->postJson(route('admin.score.status', $game), ['status' => 'final'])->assertOk();

        $this->actingAs($this->user)
            ->postJson(route('admin.score.store', $game), ['type' => 'pitch', 'payload' => ['pitch' => 'B']])
            ->assertStatus(422);
    }

    public function test_opstelling_ligt_vast_na_eerste_pitch(): void
    {
        $game = $this->gameWithLineups();
        $this->actingAs($this->user)->postJson(route('admin.score.store', $game), ['type' => 'pitch', 'payload' => ['pitch' => 'B']]);

        $this->actingAs($this->user)
            ->put(route('admin.games.lineup.update', $game), ['lineup' => ['away' => [], 'home' => []]])
            ->assertSessionHasErrors('lineup');
        $this->assertSame(18, $game->lineups()->count());
    }

    public function test_openbare_pagina_en_privacy_van_namen(): void
    {
        $game = $this->gameWithLineups();

        $this->get(route('home'))->assertOk()->assertSee('Thuisploeg');
        $this->get(route('games.public', $game->slug))
            ->assertOk()
            ->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ', false);

        $json = $this->getJson(route('games.public.state', $game->slug))->assertOk()->json();
        $homePlayer = $game->homeTeam->players()->first();
        // Thuisteam heeft show_full_names = false: geen achternaam.
        $this->assertSame('#1 Thuis1', $json['players'][$homePlayer->id]);

        $this->get(route('games.overlay', $game->slug))->assertOk();
        $this->getJson(route('games.overlay.state', $game->slug))->assertOk()->assertJsonMissingPath('players');
    }

    public function test_vertraging_verbergt_recente_acties_op_openbare_stand(): void
    {
        $game = $this->gameWithLineups();
        $game->update(['score_delay_seconds' => 30]);

        $this->actingAs($this->user)->postJson(route('admin.score.store', $game), [
            'type' => 'play', 'payload' => ['result' => 'HR', 'batter_to' => 4, 'runners' => []],
        ])->assertOk();

        $this->getJson(route('games.public.state', $game->slug))->assertJsonPath('score.away', 0);
        $this->getJson(route('games.overlay.state', $game->slug))->assertJsonPath('score.away', 1);

        $this->travel(31)->seconds();
        $this->getJson(route('games.public.state', $game->slug))->assertJsonPath('score.away', 1);
    }

    public function test_scoorscherm_laadt(): void
    {
        $game = $this->gameWithLineups();
        $this->actingAs($this->user)->get(route('admin.score.show', $game))->assertOk()->assertSee('In het spel');
        $this->actingAs($this->user)->get(route('admin.games.lineup', $game))->assertOk();
        $this->actingAs($this->user)->get(route('admin.games.edit', $game))->assertOk();
        $this->actingAs($this->user)->get(route('admin.teams.index'))->assertOk();
        $this->actingAs($this->user)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_ongeldige_youtube_link_wordt_geweigerd(): void
    {
        $game = $this->gameWithLineups();
        $this->actingAs($this->user)->put(route('admin.games.update', $game), [
            'away_team_id' => $game->away_team_id,
            'home_team_id' => $game->home_team_id,
            'starts_at' => '2026-11-21 14:00',
            'innings_planned' => 9,
            'score_delay_seconds' => 0,
            'youtube_url' => 'https://example.com/video',
        ])->assertSessionHasErrors('youtube_url');
    }

    #[DataProvider('youtubeLinks')]
    public function test_youtube_id_herkennen(string $url, ?string $id): void
    {
        $this->assertSame($id, Game::parseYoutubeId($url));
    }

    public static function youtubeLinks(): array
    {
        return [
            ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            ['https://youtu.be/dQw4w9WgXcQ?si=abc', 'dQw4w9WgXcQ'],
            ['https://www.youtube.com/live/dQw4w9WgXcQ?feature=share', 'dQw4w9WgXcQ'],
            ['https://www.youtube.com/watch?feature=x&v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            ['https://example.com/watch?v=dQw4w9WgXcQ', null],
        ];
    }
}
