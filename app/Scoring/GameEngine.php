<?php

namespace App\Scoring;

/**
 * Rekent de wedstrijdsituatie uit door alle gebeurtenissen (events) op volgorde af te spelen.
 *
 * De engine heeft geen database-kennis: hij krijgt opstellingen, startende werpers en
 * spelersnamen mee, en past daarna één voor één events toe. Daardoor is "ongedaan maken"
 * simpelweg: het laatste event verwijderen en opnieuw afspelen.
 *
 * Honken: 1, 2, 3. Bestemming 4 = gescoord, 0 = uit.
 *
 * Ondersteunde events (type => payload):
 *  - pitch:   {pitch: B|CS|SS|F|HBP}
 *  - play:    {result: 1B|2B|3B|HR|GO|FO|LO|E|FC|SF|SH, batter_to: 0-4,
 *              runners: {"1": 0-4, ...}, fielders?: "6-3", rbi?: int}
 *  - runner:  {action: SB|CS|WP|PB|BK|PK|ADV, runners: {"2": 3, ...}}
 *  - pitcher: {side: home|away, player_id: int}
 *  - sub:     {side: home|away, slot: 0-based int, player_id: int}
 */
class GameEngine
{
    public const PLAY_RESULTS = ['1B', '2B', '3B', 'HR', 'GO', 'FO', 'LO', 'E', 'FC', 'SF', 'SH'];

    public const RUNNER_ACTIONS = ['SB', 'CS', 'WP', 'PB', 'BK', 'PK', 'ADV'];

    public const PITCHES = ['B', 'CS', 'SS', 'F', 'HBP'];

    private int $inning = 1;

    private string $half = 'top';

    private int $outs = 0;

    private int $balls = 0;

    private int $strikes = 0;

    /** @var array<int, array{player: int, pitcher: ?int}|null> */
    private array $bases = [1 => null, 2 => null, 3 => null];

    private array $score = ['away' => 0, 'home' => 0];

    private array $hits = ['away' => 0, 'home' => 0];

    private array $errors = ['away' => 0, 'home' => 0];

    /** @var array{away: array<int,int>, home: array<int,int>} runs per inning */
    private array $linescore = ['away' => [1 => 0], 'home' => []];

    private array $batterIndex = ['away' => 0, 'home' => 0];

    /** @var array<int, array> */
    private array $batting = [];

    /** @var array<int, array> */
    private array $pitching = [];

    /** @var list<array> */
    private array $plays = [];

    private ?int $lastEventId = null;

    /**
     * @param  array{away: list<int>, home: list<int>}  $lineups  speler-id's in slagvolgorde
     * @param  array{away: ?int, home: ?int}  $pitchers  startende werpers
     * @param  array<int, string>  $names  speler-id => weergavenaam
     */
    public function __construct(
        private array $lineups,
        private array $pitchers,
        private array $names = [],
    ) {
        foreach (['away', 'home'] as $side) {
            $this->lineups[$side] = array_values($this->lineups[$side] ?? []);
            $this->pitchers[$side] = $this->pitchers[$side] ?? null;
            if ($this->pitchers[$side]) {
                $this->pitch($this->pitchers[$side], 'pitches', 0);
            }
        }
    }

    /**
     * Pas een event toe. Bij een ongeldige gebeurtenis blijft de state ongewijzigd.
     *
     * @param  array{type: string, payload: array, id?: int}  $event
     */
    public function apply(array $event): void
    {
        $snapshot = get_object_vars($this);

        try {
            match ($event['type']) {
                'pitch' => $this->applyPitch($event['payload']),
                'play' => $this->applyPlay($event['payload']),
                'runner' => $this->applyRunner($event['payload']),
                'pitcher' => $this->applyPitcherChange($event['payload']),
                'sub' => $this->applySubstitution($event['payload']),
                default => throw new InvalidPlayException('Onbekend type gebeurtenis.'),
            };
        } catch (InvalidPlayException $e) {
            foreach ($snapshot as $key => $value) {
                $this->{$key} = $value;
            }
            throw $e;
        }

        $this->lastEventId = $event['id'] ?? $this->lastEventId;
    }

    // ------------------------------------------------------------------
    // Pitches
    // ------------------------------------------------------------------

    private function applyPitch(array $p): void
    {
        $this->requireBatter();
        $pitch = $p['pitch'] ?? null;
        if (! in_array($pitch, self::PITCHES, true)) {
            throw new InvalidPlayException('Onbekende pitch.');
        }

        $pitcher = $this->requirePitcher();
        $this->pitch($pitcher, 'pitches');

        switch ($pitch) {
            case 'B':
                $this->pitch($pitcher, 'balls');
                $this->balls++;
                if ($this->balls === 4) {
                    $this->walk('BB');
                }
                break;
            case 'CS':
            case 'SS':
                $this->pitch($pitcher, 'strikes');
                $this->strikes++;
                if ($this->strikes === 3) {
                    $this->strikeout($pitch === 'SS');
                }
                break;
            case 'F':
                $this->pitch($pitcher, 'strikes');
                if ($this->strikes < 2) {
                    $this->strikes++;
                }
                break;
            case 'HBP':
                $this->pitch($pitcher, 'balls');
                $this->walk('HBP');
                break;
        }
    }

    private function walk(string $kind): void
    {
        $batter = $this->currentBatter();
        $pitcher = $this->currentPitcher();

        // Gedwongen opschuiven: alleen lopers die "geduwd" worden.
        $moves = [];
        if ($this->bases[1]) {
            $moves[1] = 2;
            if ($this->bases[2]) {
                $moves[2] = 3;
                if ($this->bases[3]) {
                    $moves[3] = 4;
                }
            }
        }

        $key = $kind === 'BB' ? 'bb' : 'hbp';
        $this->bat($batter, 'pa');
        $this->bat($batter, $key);
        $this->pitch($pitcher, $key);

        $runs = $this->moveRunners($moves, 1, $batter, $pitcher);
        $this->bat($batter, 'rbi', $runs);

        $text = $kind === 'BB' ? 'krijgt vier wijd' : 'wordt geraakt door de werper';
        $this->addPlay($this->name($batter).' '.$text.$this->runsText($runs).'.');
        $this->nextBatter();
    }

    private function strikeout(bool $swinging): void
    {
        $batter = $this->currentBatter();
        $pitcher = $this->currentPitcher();

        $this->bat($batter, 'pa');
        $this->bat($batter, 'ab');
        $this->bat($batter, 'so');
        $this->pitch($pitcher, 'so');
        $this->pitch($pitcher, 'outs');
        $this->outs++;

        $this->addPlay($this->name($batter).' gaat drie slag uit'.($swinging ? ' (zwaaiend)' : ' (kijkend)').'.');
        $this->nextBatter();
        $this->checkSideRetired();
    }

    // ------------------------------------------------------------------
    // Bal in het spel
    // ------------------------------------------------------------------

    private function applyPlay(array $p): void
    {
        $this->requireBatter();
        $result = $p['result'] ?? null;
        if (! in_array($result, self::PLAY_RESULTS, true)) {
            throw new InvalidPlayException('Onbekende uitkomst van de slagbeurt.');
        }
        $batterTo = (int) ($p['batter_to'] ?? -1);
        if ($batterTo < 0 || $batterTo > 4) {
            throw new InvalidPlayException('Geef aan waar de slagman eindigt.');
        }
        $moves = $this->normaliseMoves($p['runners'] ?? []);

        $minimum = ['1B' => 1, '2B' => 2, '3B' => 3, 'HR' => 4][$result] ?? null;
        if ($minimum !== null && $batterTo !== 0 && $batterTo < $minimum) {
            throw new InvalidPlayException('De slagman moet minimaal het honk van de honkslag halen.');
        }
        if ($result === 'HR' && $batterTo !== 4) {
            throw new InvalidPlayException('Bij een homerun scoort de slagman.');
        }

        $batter = $this->currentBatter();
        $pitcher = $this->requirePitcher();
        $this->pitch($pitcher, 'pitches');
        $this->pitch($pitcher, 'strikes');

        $isHit = in_array($result, ['1B', '2B', '3B', 'HR'], true);
        $sacrifice = in_array($result, ['SF', 'SH'], true);

        $this->bat($batter, 'pa');
        if ($sacrifice) {
            $this->bat($batter, strtolower($result));
        } else {
            $this->bat($batter, 'ab');
        }
        if ($isHit) {
            $this->bat($batter, 'h');
            $this->bat($batter, ['1B' => 'h1', '2B' => '2b', '3B' => '3b', 'HR' => 'hr'][$result]);
            $this->pitch($pitcher, 'h');
            $this->hits[$this->battingSide()]++;
        }
        if ($result === 'E') {
            $this->errors[$this->fieldingSide()]++;
        }

        $outsBefore = $this->outs;
        $runs = $this->moveRunners($moves, $batterTo, $batter, $pitcher);
        $outsOnPlay = $this->outs - $outsBefore;

        // RBI: niet bij een fout of een dubbelspel, tenzij de scorer het expliciet opgeeft.
        $rbi = isset($p['rbi']) ? max(0, (int) $p['rbi']) : (($result === 'E' || $outsOnPlay >= 2) ? 0 : $runs);
        $this->bat($batter, 'rbi', $rbi);

        $this->addPlay($this->describePlay($result, $batter, $batterTo, $p['fielders'] ?? null, $runs, $outsOnPlay));
        $this->nextBatter();
        $this->checkSideRetired();
    }

    private function describePlay(string $result, int $batter, int $to, ?string $fielders, int $runs, int $outs): string
    {
        $labels = [
            '1B' => 'slaat een honkslag',
            '2B' => 'slaat een tweehonkslag',
            '3B' => 'slaat een driehonkslag',
            'HR' => 'slaat een homerun',
            'GO' => 'slaat een grondbal',
            'FO' => 'slaat een vangbal',
            'LO' => 'slaat een lijnbal',
            'E' => 'bereikt het honk door een fout',
            'FC' => 'bereikt het honk via een fielder\'s choice',
            'SF' => 'slaat een opofferingsvangbal',
            'SH' => 'legt een opofferingsstootslag',
        ];
        $text = $this->name($batter).' '.$labels[$result];
        if ($fielders) {
            $text .= ' ('.$fielders.')';
        }
        if ($outs >= 2) {
            $text .= ', dubbelspel';
        } elseif ($to === 0 && ! in_array($result, ['SF', 'SH'], true)) {
            $text .= ', uit';
        }

        return $text.$this->runsText($runs).'.';
    }

    // ------------------------------------------------------------------
    // Loopacties tussen de pitches door
    // ------------------------------------------------------------------

    private function applyRunner(array $p): void
    {
        $action = $p['action'] ?? null;
        if (! in_array($action, self::RUNNER_ACTIONS, true)) {
            throw new InvalidPlayException('Onbekende loopactie.');
        }
        $moves = $this->normaliseMoves($p['runners'] ?? []);
        if ($moves === []) {
            throw new InvalidPlayException('Kies minimaal één loper.');
        }

        $pitcher = $this->requirePitcher();
        $movers = [];
        foreach ($moves as $from => $to) {
            $movers[$from] = $this->bases[$from]['player'];
            if ($action === 'SB' && $to !== 0) {
                $this->bat($movers[$from], 'sb');
            }
            if ($action === 'CS' && $to === 0) {
                $this->bat($movers[$from], 'cs');
            }
        }

        $runs = $this->moveRunners($moves, null, null, $pitcher);

        $labels = [
            'SB' => 'steelt',
            'CS' => 'wordt uitgegooid bij het stelen',
            'WP' => 'schuift op door een wilde worp',
            'PB' => 'schuift op door een doorgeschoten bal',
            'BK' => 'schuift op door een balk',
            'PK' => 'wordt afgegooid',
            'ADV' => 'schuift op',
        ];
        $parts = [];
        foreach ($moves as $from => $to) {
            $where = match ($to) {
                0 => 'uit',
                4 => 'scoort',
                default => 'naar '.$to.'e honk',
            };
            $parts[] = $this->name($movers[$from]).' ('.$where.')';
        }
        $this->addPlay(ucfirst($labels[$action]).': '.implode(', ', $parts).'.');

        if ($this->outs >= 3) {
            // De slagbeurt is niet afgemaakt: dezelfde slagman begint de volgende inning.
            $this->endHalfInning();
        }
    }

    // ------------------------------------------------------------------
    // Wissels
    // ------------------------------------------------------------------

    private function applyPitcherChange(array $p): void
    {
        $side = $this->requireSide($p['side'] ?? null);
        $player = (int) ($p['player_id'] ?? 0);
        if ($player <= 0) {
            throw new InvalidPlayException('Kies een werper.');
        }
        $this->pitchers[$side] = $player;
        $this->pitch($player, 'pitches', 0);
        $this->addPlay('Werperswissel: '.$this->name($player).' komt pitchen.');
    }

    private function applySubstitution(array $p): void
    {
        $side = $this->requireSide($p['side'] ?? null);
        $slot = (int) ($p['slot'] ?? -1);
        $player = (int) ($p['player_id'] ?? 0);
        if (! isset($this->lineups[$side][$slot])) {
            throw new InvalidPlayException('Ongeldige plek in de slagvolgorde.');
        }
        if ($player <= 0) {
            throw new InvalidPlayException('Kies een speler.');
        }
        $old = $this->lineups[$side][$slot];
        $this->lineups[$side][$slot] = $player;
        $this->bat($player, 'pa', 0);

        // Pinch runner: vervang de loper op het honk.
        foreach ($this->bases as $base => $runner) {
            if ($runner && $runner['player'] === $old && $side === $this->battingSide()) {
                $this->bases[$base]['player'] = $player;
            }
        }
        $this->addPlay('Wissel: '.$this->name($player).' vervangt '.$this->name($old).'.');
    }

    // ------------------------------------------------------------------
    // Kernlogica lopers
    // ------------------------------------------------------------------

    /**
     * @return array<int,int> honk => bestemming, alleen voor bezette honken
     */
    private function normaliseMoves(array $runners): array
    {
        $moves = [];
        // Altijd van 1e naar 3e honk controleren (zelfde volgorde als public/js/engine.js).
        ksort($runners);
        foreach ($runners as $from => $to) {
            $from = (int) $from;
            $to = (int) $to;
            if (! in_array($from, [1, 2, 3], true)) {
                throw new InvalidPlayException('Ongeldig honk.');
            }
            if (! $this->bases[$from]) {
                throw new InvalidPlayException("Er staat geen loper op het {$from}e honk.");
            }
            if ($to !== 0 && ($to < $from || $to > 4)) {
                throw new InvalidPlayException('Een loper kan niet terug naar een eerder honk.');
            }
            if ($to !== $from) {
                $moves[$from] = $to;
            }
        }
        krsort($moves);

        return $moves;
    }

    /**
     * Verplaatst lopers (en eventueel de slagman) en telt runs en outs.
     *
     * @param  array<int,int>  $moves
     * @return int aantal runs dat telt
     */
    private function moveRunners(array $moves, ?int $batterTo, ?int $batter, ?int $pitcher): int
    {
        // Altijd van 3e naar 1e honk verwerken, zodat een loper nooit een andere overschrijft.
        krsort($moves);
        $new = $this->bases;
        $scored = [];
        $outs = 0;

        foreach ($moves as $from => $to) {
            $runner = $this->bases[$from];
            $new[$from] = null;
            if ($to === 0) {
                $outs++;
            } elseif ($to === 4) {
                $scored[] = $runner;
            } else {
                // Lopers worden van 3 naar 1 verwerkt, dus een vrijgekomen honk is al leeg.
                $new[$to] = $runner;
            }
        }

        // Controle: twee lopers op hetzelfde honk.
        $targets = [];
        foreach ($moves as $from => $to) {
            if ($to >= 1 && $to <= 3) {
                $targets[] = $to;
            }
        }
        foreach ([1, 2, 3] as $base) {
            if ($this->bases[$base] && ! array_key_exists($base, $moves)) {
                $targets[] = $base;
            }
        }

        if ($batter !== null && $batterTo !== null) {
            if ($batterTo === 0) {
                $outs++;
            } elseif ($batterTo === 4) {
                $scored[] = ['player' => $batter, 'pitcher' => $pitcher];
            } else {
                $targets[] = $batterTo;
                $new[$batterTo] = ['player' => $batter, 'pitcher' => $pitcher];
            }
        }

        if (count($targets) !== count(array_unique($targets))) {
            throw new InvalidPlayException('Er kunnen geen twee lopers op hetzelfde honk staan.');
        }
        if ($this->outs + $outs > 3) {
            throw new InvalidPlayException('Er kunnen niet meer dan drie uit zijn.');
        }

        $this->outs += $outs;
        if ($pitcher) {
            $this->pitch($pitcher, 'outs', $outs);
        }

        // Derde out door de slagman: dan tellen runs op dat spel niet.
        if ($this->outs >= 3 && $batterTo === 0) {
            $scored = [];
        }

        foreach ($scored as $runner) {
            $this->bat($runner['player'], 'r');
            if ($runner['pitcher']) {
                $this->pitch($runner['pitcher'], 'r');
            }
        }
        $runs = count($scored);
        $side = $this->battingSide();
        $this->score[$side] += $runs;
        $this->linescore[$side][$this->inning] = ($this->linescore[$side][$this->inning] ?? 0) + $runs;

        $this->bases = $new;

        return $runs;
    }

    private function checkSideRetired(): void
    {
        if ($this->outs >= 3) {
            $this->endHalfInning();
        }
    }

    private function endHalfInning(): void
    {
        $this->addPlay('Drie uit, einde '.($this->half === 'top' ? 'eerste' : 'tweede').' helft van inning '.$this->inning.'.', 'system');
        $this->outs = 0;
        $this->balls = 0;
        $this->strikes = 0;
        $this->bases = [1 => null, 2 => null, 3 => null];
        if ($this->half === 'top') {
            $this->half = 'bottom';
        } else {
            $this->half = 'top';
            $this->inning++;
        }
        $side = $this->battingSide();
        $this->linescore[$side][$this->inning] = $this->linescore[$side][$this->inning] ?? 0;
    }

    private function nextBatter(): void
    {
        $side = $this->battingSide();
        $count = count($this->lineups[$side]);
        $this->batterIndex[$side] = ($this->batterIndex[$side] + 1) % max(1, $count);
        $this->balls = 0;
        $this->strikes = 0;
    }

    // ------------------------------------------------------------------
    // Hulpfuncties
    // ------------------------------------------------------------------

    public function battingSide(): string
    {
        return $this->half === 'top' ? 'away' : 'home';
    }

    public function fieldingSide(): string
    {
        return $this->half === 'top' ? 'home' : 'away';
    }

    private function currentBatter(): ?int
    {
        $side = $this->battingSide();

        return $this->lineups[$side][$this->batterIndex[$side]] ?? null;
    }

    private function currentPitcher(): ?int
    {
        return $this->pitchers[$this->fieldingSide()] ?? null;
    }

    private function requireBatter(): int
    {
        return $this->currentBatter() ?? throw new InvalidPlayException('Vul eerst de slagvolgorde in.');
    }

    private function requirePitcher(): int
    {
        return $this->currentPitcher() ?? throw new InvalidPlayException('Kies eerst een werper voor het veldteam.');
    }

    private function requireSide(?string $side): string
    {
        if (! in_array($side, ['home', 'away'], true)) {
            throw new InvalidPlayException('Kies een team.');
        }

        return $side;
    }

    private function bat(int $player, string $key, int $amount = 1): void
    {
        if (! isset($this->batting[$player])) {
            $this->batting[$player] = [
                'pa' => 0, 'ab' => 0, 'h' => 0, 'h1' => 0, '2b' => 0, '3b' => 0, 'hr' => 0,
                'r' => 0, 'rbi' => 0, 'bb' => 0, 'hbp' => 0, 'so' => 0, 'sb' => 0, 'cs' => 0,
                'sf' => 0, 'sh' => 0,
            ];
        }
        $this->batting[$player][$key] += $amount;
    }

    private function pitch(int $player, string $key, int $amount = 1): void
    {
        if (! isset($this->pitching[$player])) {
            $this->pitching[$player] = [
                'pitches' => 0, 'strikes' => 0, 'balls' => 0, 'outs' => 0,
                'h' => 0, 'r' => 0, 'bb' => 0, 'hbp' => 0, 'so' => 0,
            ];
        }
        $this->pitching[$player][$key] += $amount;
    }

    private function name(?int $player): string
    {
        return $player ? ($this->names[$player] ?? 'Speler '.$player) : 'Onbekend';
    }

    private function runsText(int $runs): string
    {
        return match (true) {
            $runs === 1 => ', 1 run scoort',
            $runs > 1 => ", {$runs} runs scoren",
            default => '',
        };
    }

    private function addPlay(string $text, string $kind = 'play'): void
    {
        $this->plays[] = [
            'inning' => $this->inning,
            'half' => $this->half,
            'kind' => $kind,
            'text' => $text,
            'score' => $this->score,
        ];
    }

    // ------------------------------------------------------------------
    // Uitvoer
    // ------------------------------------------------------------------

    public function state(): array
    {
        $bases = [];
        foreach ($this->bases as $base => $runner) {
            $bases[$base] = $runner ? ['player_id' => $runner['player'], 'name' => $this->name($runner['player'])] : null;
        }

        $batting = [];
        foreach ($this->batting as $id => $s) {
            $batting[$id] = $s + StatFormatter::batting($s);
        }
        $pitching = [];
        foreach ($this->pitching as $id => $s) {
            $pitching[$id] = $s + StatFormatter::pitching($s);
        }

        $batter = $this->currentBatter();
        $pitcher = $this->currentPitcher();

        return [
            'inning' => $this->inning,
            'half' => $this->half,
            'batting_side' => $this->battingSide(),
            'outs' => $this->outs,
            'balls' => $this->balls,
            'strikes' => $this->strikes,
            'bases' => $bases,
            'score' => $this->score,
            'hits' => $this->hits,
            'errors' => $this->errors,
            'linescore' => $this->linescore,
            'lineups' => $this->lineups,
            'pitchers' => $this->pitchers,
            'batter' => $batter ? ['player_id' => $batter, 'name' => $this->name($batter)] : null,
            'pitcher' => $pitcher ? [
                'player_id' => $pitcher,
                'name' => $this->name($pitcher),
                'pitches' => $this->pitching[$pitcher]['pitches'] ?? 0,
            ] : null,
            'batting' => $batting,
            'pitching' => $pitching,
            'plays' => $this->plays,
            'last_event_id' => $this->lastEventId,
        ];
    }
}
