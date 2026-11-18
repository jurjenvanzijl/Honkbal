<?php

namespace Tests\Unit;

use App\Scoring\GameEngine;
use App\Scoring\InvalidPlayException;
use PHPUnit\Framework\TestCase;

class GameEngineTest extends TestCase
{
    /** Uitteam: spelers 1-9, thuisteam: spelers 11-19. Werpers: 100 (thuis) en 200 (uit). */
    private function engine(): GameEngine
    {
        return new GameEngine(
            ['away' => range(1, 9), 'home' => range(11, 19)],
            ['away' => 200, 'home' => 100],
        );
    }

    private function pitches(GameEngine $e, string ...$pitches): void
    {
        foreach ($pitches as $p) {
            $e->apply(['type' => 'pitch', 'payload' => ['pitch' => $p]]);
        }
    }

    private function play(GameEngine $e, string $result, int $batterTo, array $runners = []): void
    {
        $e->apply(['type' => 'play', 'payload' => ['result' => $result, 'batter_to' => $batterTo, 'runners' => $runners]]);
    }

    public function test_drie_slag_is_uit_en_volgende_slagman(): void
    {
        $e = $this->engine();
        $this->pitches($e, 'CS', 'SS', 'F', 'SS');
        $s = $e->state();

        $this->assertSame(1, $s['outs']);
        $this->assertSame(0, $s['strikes']);
        $this->assertSame(2, $s['batter']['player_id']);
        $this->assertSame(1, $s['batting'][1]['so']);
        $this->assertSame(4, $s['pitching'][100]['pitches']);
        $this->assertSame(1, $s['pitching'][100]['so']);
    }

    public function test_foul_bij_twee_slag_telt_niet_als_derde_slag(): void
    {
        $e = $this->engine();
        $this->pitches($e, 'CS', 'CS', 'F', 'F');
        $this->assertSame(2, $e->state()['strikes']);
        $this->assertSame(0, $e->state()['outs']);
    }

    public function test_vier_wijd_met_volle_honken_drukt_run_binnen(): void
    {
        $e = $this->engine();
        $this->pitches($e, 'B', 'B', 'B', 'B'); // 1 op 1e
        $this->pitches($e, 'HBP');              // 1 op 2e, 2 op 1e
        $this->pitches($e, 'B', 'B', 'B', 'B'); // honken vol
        $this->pitches($e, 'B', 'B', 'B', 'B'); // run

        $s = $e->state();
        $this->assertSame(1, $s['score']['away']);
        $this->assertSame(1, $s['batting'][4]['rbi']);
        $this->assertSame(1, $s['batting'][1]['r']);
        $this->assertSame(1, $s['pitching'][100]['r']);
        $this->assertNotNull($s['bases'][1]);
        $this->assertNotNull($s['bases'][2]);
        $this->assertNotNull($s['bases'][3]);
    }

    public function test_vier_wijd_duwt_alleen_gedwongen_lopers(): void
    {
        $e = $this->engine();
        $this->play($e, '2B', 2);               // loper op 2
        $this->pitches($e, 'B', 'B', 'B', 'B'); // vier wijd: loper op 2 blijft staan

        $s = $e->state();
        $this->assertSame(2, $s['bases'][1]['player_id']);
        $this->assertSame(1, $s['bases'][2]['player_id']);
        $this->assertNull($s['bases'][3]);
    }

    public function test_homerun_met_lopers(): void
    {
        $e = $this->engine();
        $this->play($e, '1B', 1);
        $this->play($e, '1B', 1, ['1' => 2]);
        $this->play($e, 'HR', 4, ['1' => 4, '2' => 4]);

        $s = $e->state();
        $this->assertSame(3, $s['score']['away']);
        $this->assertSame(3, $s['batting'][3]['rbi']);
        $this->assertSame(1, $s['batting'][3]['hr']);
        $this->assertSame(3, $s['hits']['away']);
        $this->assertSame([1 => null, 2 => null, 3 => null], $s['bases']);
        $this->assertSame('1.000', $s['batting'][3]['avg']);
        $this->assertSame('4.000', $s['batting'][3]['slg']);
    }

    public function test_drie_uit_wisselt_van_slagteam_en_leegt_honken(): void
    {
        $e = $this->engine();
        $this->play($e, '1B', 1);
        $this->play($e, 'GO', 0);
        $this->play($e, 'FO', 0);
        $this->play($e, 'LO', 0);

        $s = $e->state();
        $this->assertSame('bottom', $s['half']);
        $this->assertSame(1, $s['inning']);
        $this->assertSame(0, $s['outs']);
        $this->assertSame([1 => null, 2 => null, 3 => null], $s['bases']);
        $this->assertSame(11, $s['batter']['player_id']);
        $this->assertSame('1.0', $s['pitching'][100]['ip']);
    }

    public function test_slagvolgorde_loopt_door_naar_volgende_inning(): void
    {
        $e = $this->engine();
        $this->play($e, 'GO', 0);
        $this->play($e, 'GO', 0);
        $this->play($e, 'GO', 0);
        $this->play($e, 'GO', 0); // thuis
        $this->play($e, 'GO', 0);
        $this->play($e, 'GO', 0);

        $s = $e->state();
        $this->assertSame(2, $s['inning']);
        $this->assertSame('top', $s['half']);
        $this->assertSame(4, $s['batter']['player_id']);
    }

    public function test_dubbelspel_geeft_geen_rbi(): void
    {
        $e = $this->engine();
        $this->play($e, '1B', 1);
        $this->play($e, '2B', 2, ['1' => 3]);           // lopers op 2 en 3
        $this->play($e, 'GO', 0, ['2' => 0, '3' => 4]); // dubbelspel, run scoort

        $s = $e->state();
        $this->assertSame(2, $s['outs']);
        $this->assertSame(1, $s['score']['away']);
        $this->assertSame(0, $s['batting'][3]['rbi']);
    }

    public function test_run_telt_niet_als_slagman_derde_out_is(): void
    {
        $e = $this->engine();
        $this->play($e, '3B', 3);
        $this->play($e, 'GO', 0);
        $this->play($e, 'GO', 0);
        $this->play($e, 'GO', 0, ['3' => 4]);

        $this->assertSame(0, $e->state()['score']['away']);
    }

    public function test_opofferingsvangbal_telt_niet_als_slagbeurt(): void
    {
        $e = $this->engine();
        $this->play($e, '3B', 3);
        $this->play($e, 'SF', 0, ['3' => 4]);

        $s = $e->state();
        $this->assertSame(1, $s['batting'][2]['pa']);
        $this->assertSame(0, $s['batting'][2]['ab']);
        $this->assertSame(1, $s['batting'][2]['rbi']);
        $this->assertSame(1, $s['score']['away']);
    }

    public function test_gestolen_honk_en_uitgegooid(): void
    {
        $e = $this->engine();
        $this->play($e, '1B', 1);
        $e->apply(['type' => 'runner', 'payload' => ['action' => 'SB', 'runners' => ['1' => 2]]]);
        $e->apply(['type' => 'runner', 'payload' => ['action' => 'CS', 'runners' => ['2' => 0]]]);

        $s = $e->state();
        $this->assertSame(1, $s['batting'][1]['sb']);
        $this->assertSame(1, $s['batting'][1]['cs']);
        $this->assertSame(1, $s['outs']);
        $this->assertSame(2, $s['batter']['player_id'], 'slagbeurt loopt nog');
    }

    public function test_derde_out_bij_stelen_laat_slagman_volgende_inning_beginnen(): void
    {
        $e = $this->engine();
        $this->play($e, 'GO', 0);
        $this->play($e, 'GO', 0);
        $this->play($e, '1B', 1);
        $this->pitches($e, 'B');
        $e->apply(['type' => 'runner', 'payload' => ['action' => 'CS', 'runners' => ['1' => 0]]]);

        $s = $e->state();
        $this->assertSame('bottom', $s['half']);
        $this->assertSame(0, $s['balls']);

        // Na de thuisbeurt komt speler 4 (niet 5) aan slag.
        $this->play($e, 'GO', 0);
        $this->play($e, 'GO', 0);
        $this->play($e, 'GO', 0);
        $this->assertSame(4, $e->state()['batter']['player_id']);
    }

    public function test_twee_lopers_op_hetzelfde_honk_wordt_geweigerd_en_state_blijft_gelijk(): void
    {
        $e = $this->engine();
        $this->play($e, '1B', 1);
        $before = $e->state();

        try {
            $this->play($e, '1B', 1); // loper op 1 blijft staan: ongeldig
            $this->fail('Verwachtte een InvalidPlayException');
        } catch (InvalidPlayException) {
        }

        $this->assertEquals($before, $e->state());
    }

    public function test_loper_kan_niet_terug(): void
    {
        $this->expectException(InvalidPlayException::class);
        $e = $this->engine();
        $this->play($e, '2B', 2);
        $e->apply(['type' => 'runner', 'payload' => ['action' => 'ADV', 'runners' => ['2' => 1]]]);
    }

    public function test_werperswissel_en_runs_op_verantwoordelijke_werper(): void
    {
        $e = $this->engine();
        $this->play($e, '1B', 1); // loper van werper 100
        $e->apply(['type' => 'pitcher', 'payload' => ['side' => 'home', 'player_id' => 101]]);
        $this->play($e, 'HR', 4, ['1' => 4]);

        $s = $e->state();
        $this->assertSame(1, $s['pitching'][100]['r']);
        $this->assertSame(1, $s['pitching'][101]['r']);
        $this->assertSame(101, $s['pitcher']['player_id']);
    }

    public function test_pinch_runner_vervangt_loper_op_honk(): void
    {
        $e = $this->engine();
        $this->play($e, '1B', 1);
        $e->apply(['type' => 'sub', 'payload' => ['side' => 'away', 'slot' => 0, 'player_id' => 50]]);

        $s = $e->state();
        $this->assertSame(50, $s['bases'][1]['player_id']);
        $this->assertSame(50, $s['lineups']['away'][0]);
    }

    public function test_fout_telt_als_error_voor_veldteam(): void
    {
        $e = $this->engine();
        $this->play($e, 'E', 1);
        $s = $e->state();
        $this->assertSame(1, $s['errors']['home']);
        $this->assertSame(0, $s['hits']['away']);
        $this->assertSame(1, $s['batting'][1]['ab']);
    }

    public function test_zonder_werper_kan_niet_gescoord_worden(): void
    {
        $this->expectException(InvalidPlayException::class);
        $e = new GameEngine(['away' => [1], 'home' => [2]], ['away' => null, 'home' => null]);
        $e->apply(['type' => 'pitch', 'payload' => ['pitch' => 'B']]);
    }
}
