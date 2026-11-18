<?php

namespace Tests\Unit;

use App\Scoring\GameEngine;
use App\Scoring\InvalidPlayException;
use PHPUnit\Framework\TestCase;

/**
 * Controleert dat public/js/engine.js (offline scoren in de browser) exact hetzelfde rekent
 * als app/Scoring/GameEngine.php. Er worden willekeurige wedstrijden gegenereerd, inclusief
 * ongeldige acties; per actie moeten beide engines hetzelfde doen (of dezelfde foutmelding geven)
 * en de eindstand moet identiek zijn.
 *
 * Vereist Node.js; zonder Node wordt de test overgeslagen.
 */
class EngineParityTest extends TestCase
{
    private const CASES = 40;

    private const EVENTS_PER_CASE = 300;

    public function test_php_en_javascript_engine_rekenen_identiek(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('Node.js is niet beschikbaar.');
        }

        mt_srand(20261118);
        $cases = [];
        $expected = [];
        for ($c = 0; $c < self::CASES; $c++) {
            [$case, $result] = $this->generateCase($c);
            $cases[] = $case;
            $expected[] = $result;
        }

        $file = tempnam(sys_get_temp_dir(), 'parity');
        file_put_contents($file, json_encode(['cases' => $cases]));
        $output = shell_exec(escapeshellarg($node).' '.escapeshellarg(__DIR__.'/../js/run-engine.cjs').' '.escapeshellarg($file).' 2>&1');
        unlink($file);

        $actual = json_decode((string) $output, true);
        $this->assertIsArray($actual, 'Node gaf geen geldige JSON terug: '.substr((string) $output, 0, 500));

        $validEvents = 0;
        foreach ($expected as $i => $exp) {
            $js = $actual['results'][$i];
            foreach ($exp['outcomes'] as $e => $outcome) {
                $this->assertSame(
                    $outcome,
                    $js['outcomes'][$e],
                    "Scenario {$i}, event {$e}: ".json_encode($cases[$i]['events'][$e])
                );
                $validEvents += $outcome === null ? 1 : 0;
            }
            $this->assertEquals(
                json_decode(json_encode($exp['state']), true),
                $js['state'],
                "Eindstand van scenario {$i} verschilt tussen PHP en JavaScript."
            );
        }

        // Zorg dat de generator genoeg geldige acties maakt om iets te bewijzen.
        $this->assertGreaterThan(self::CASES * self::EVENTS_PER_CASE * 0.5, $validEvents);
    }

    /** @return array{0: array, 1: array{outcomes: list<?string>, state: array}} */
    private function generateCase(int $index): array
    {
        $size = [9, 9, 9, 10, 1][$index % 5];
        $lineups = ['away' => range(1, $size), 'home' => range(101, 100 + $size)];
        $pitchers = $index % 7 === 6 ? ['away' => null, 'home' => 150] : ['away' => 50, 'home' => 150];
        $names = [];
        foreach ([...$lineups['away'], ...$lineups['home'], 50, 51, 150, 151, 60, 160] as $id) {
            $names[$id] = 'Speler #'.$id;
        }
        unset($names[160]); // één speler zonder naam: test de terugvaltekst

        $engine = new GameEngine($lineups, $pitchers, $names);
        $events = [];
        $outcomes = [];

        for ($n = 0; $n < self::EVENTS_PER_CASE; $n++) {
            $event = $this->randomEvent($engine->state());
            $event['id'] = $n + 1;
            $events[] = $event;
            try {
                $engine->apply($event);
                $outcomes[] = null;
            } catch (InvalidPlayException $e) {
                $outcomes[] = $e->getMessage();
            }
        }

        return [
            ['lineups' => $lineups, 'pitchers' => $pitchers, 'names' => $names, 'events' => $events],
            ['outcomes' => $outcomes, 'state' => $engine->state()],
        ];
    }

    private function randomEvent(array $state): array
    {
        $roll = mt_rand(1, 100);
        $occupied = array_keys(array_filter($state['bases']));

        if ($roll <= 55) {
            return ['type' => 'pitch', 'payload' => ['pitch' => $this->pick(['B', 'B', 'CS', 'SS', 'F', 'F', 'HBP', 'X'])]];
        }

        if ($roll <= 85) {
            $result = $this->pick([...GameEngine::PLAY_RESULTS, 'ZZ']);
            $batterTo = ['1B' => 1, '2B' => 2, '3B' => 3, 'HR' => 4, 'E' => 1, 'FC' => 1][$result] ?? 0;
            if (mt_rand(1, 10) === 1) {
                $batterTo = mt_rand(-1, 5);
            }
            $runners = [];
            foreach ($occupied as $base) {
                $runners[(string) $base] = mt_rand(1, 6) === 1 ? mt_rand(0, 4) : min(4, $base + ($batterTo ?: 0));
            }
            if (mt_rand(1, 15) === 1) {
                $runners[(string) mt_rand(0, 3)] = mt_rand(0, 4); // mogelijk een leeg of ongeldig honk
            }
            $payload = ['result' => $result, 'batter_to' => $batterTo, 'runners' => $runners];
            if (mt_rand(1, 4) === 1) {
                $payload['fielders'] = $this->pick(['6-3', '8', '4-6-3', '0', '']);
            }
            if (mt_rand(1, 12) === 1) {
                $payload['rbi'] = mt_rand(-1, 3);
            }

            return ['type' => 'play', 'payload' => $payload];
        }

        if ($roll <= 94) {
            $runners = [];
            foreach ($occupied as $base) {
                if (mt_rand(0, 1)) {
                    $runners[(string) $base] = mt_rand(0, 4);
                }
            }
            if ($runners === [] && mt_rand(1, 3) === 1) {
                $runners[(string) mt_rand(1, 3)] = mt_rand(0, 4);
            }

            return ['type' => 'runner', 'payload' => [
                'action' => $this->pick([...GameEngine::RUNNER_ACTIONS, 'XX']),
                'runners' => $runners,
            ]];
        }

        if ($roll <= 97) {
            return ['type' => 'pitcher', 'payload' => [
                'side' => $this->pick(['home', 'away', 'foo']),
                'player_id' => $this->pick([51, 151, 60, 160, 0]),
            ]];
        }

        if ($roll <= 99) {
            return ['type' => 'sub', 'payload' => [
                'side' => $this->pick(['home', 'away']),
                'slot' => mt_rand(-1, 10),
                'player_id' => $this->pick([60, 160, 0]),
            ]];
        }

        return ['type' => 'onbekend', 'payload' => []];
    }

    private function pick(array $options): mixed
    {
        return $options[array_rand($options)];
    }
}
