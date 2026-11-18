/**
 * JavaScript-versie van app/Scoring/GameEngine.php, zodat het scoorscherm offline kan doorrekenen.
 *
 * LET OP: de PHP-engine is leidend. Elke wijziging in de spelregels moet in BEIDE bestanden.
 * tests/Unit/EngineParityTest.php speelt honderden willekeurige acties in beide engines af
 * en faalt zodra de uitkomsten verschillen.
 *
 * Werkt in de browser (window.HonkbalEngine) en in Node (module.exports) voor de tests.
 */
(function (global) {
    'use strict';

    const PLAY_RESULTS = ['1B', '2B', '3B', 'HR', 'GO', 'FO', 'LO', 'E', 'FC', 'SF', 'SH'];
    const RUNNER_ACTIONS = ['SB', 'CS', 'WP', 'PB', 'BK', 'PK', 'ADV'];
    const PITCHES = ['B', 'CS', 'SS', 'F', 'HBP'];

    class InvalidPlay extends Error {}

    /** PHP (int)-cast: "3" -> 3, null -> 0, "abc" -> 0, 2.7 -> 2. */
    const int = (v) => {
        if (v === null || v === undefined || v === false) return 0;
        if (v === true) return 1;
        const n = parseInt(v, 10);
        return Number.isNaN(n) ? 0 : n;
    };

    const ucfirst = (s) => s.charAt(0).toUpperCase() + s.slice(1);

    /** Honkbalnotatie zoals StatFormatter::rate(): .333 en 1.000 */
    function rate(num, den) {
        if (den === 0) return '-';
        const value = (Math.round((num / den) * 1000) / 1000).toFixed(3);
        return value.startsWith('0') ? value.slice(1) : value;
    }

    function battingRates(s) {
        const tb = s.h1 + 2 * s['2b'] + 3 * s['3b'] + 4 * s.hr;
        const obpDen = s.ab + s.bb + s.hbp + s.sf;
        return { avg: rate(s.h, s.ab), obp: rate(s.h + s.bb + s.hbp, obpDen), slg: rate(tb, s.ab) };
    }

    function pitchingRates(s) {
        return { ip: Math.floor(s.outs / 3) + '.' + (s.outs % 3) };
    }

    class GameEngine {
        /**
         * @param {{away: number[], home: number[]}} lineups
         * @param {{away: ?number, home: ?number}} pitchers
         * @param {Object<string,string>} names
         */
        constructor(lineups, pitchers, names = {}) {
            this.names = names || {};
            this.s = {
                inning: 1,
                half: 'top',
                outs: 0,
                balls: 0,
                strikes: 0,
                bases: { 1: null, 2: null, 3: null },
                score: { away: 0, home: 0 },
                hits: { away: 0, home: 0 },
                errors: { away: 0, home: 0 },
                linescore: { away: { 1: 0 }, home: {} },
                batterIndex: { away: 0, home: 0 },
                batting: {},
                pitching: {},
                plays: [],
                lastEventId: null,
                lineups: { away: [], home: [] },
                pitchers: { away: null, home: null },
            };
            for (const side of ['away', 'home']) {
                this.s.lineups[side] = ((lineups && lineups[side]) || []).map(Number);
                const p = pitchers && pitchers[side] ? Number(pitchers[side]) : null;
                this.s.pitchers[side] = p;
                if (p) this.pitch(p, 'pitches', 0);
            }
        }

        /** Pas een event toe. Bij een ongeldige gebeurtenis blijft de state ongewijzigd. */
        apply(event) {
            const snapshot = JSON.parse(JSON.stringify(this.s));
            try {
                const p = event.payload || {};
                switch (event.type) {
                    case 'pitch': this.applyPitch(p); break;
                    case 'play': this.applyPlay(p); break;
                    case 'runner': this.applyRunner(p); break;
                    case 'pitcher': this.applyPitcherChange(p); break;
                    case 'sub': this.applySubstitution(p); break;
                    default: throw new InvalidPlay('Onbekend type gebeurtenis.');
                }
            } catch (e) {
                this.s = snapshot;
                throw e;
            }
            if (event.id !== undefined && event.id !== null) this.s.lastEventId = event.id;
        }

        // --------------------------------------------------------------
        // Pitches
        // --------------------------------------------------------------

        applyPitch(p) {
            this.requireBatter();
            const pitch = p.pitch;
            if (!PITCHES.includes(pitch)) throw new InvalidPlay('Onbekende pitch.');

            const s = this.s;
            const pitcher = this.requirePitcher();
            this.pitch(pitcher, 'pitches');

            switch (pitch) {
                case 'B':
                    this.pitch(pitcher, 'balls');
                    s.balls++;
                    if (s.balls === 4) this.walk('BB');
                    break;
                case 'CS':
                case 'SS':
                    this.pitch(pitcher, 'strikes');
                    s.strikes++;
                    if (s.strikes === 3) this.strikeout(pitch === 'SS');
                    break;
                case 'F':
                    this.pitch(pitcher, 'strikes');
                    if (s.strikes < 2) s.strikes++;
                    break;
                case 'HBP':
                    this.pitch(pitcher, 'balls');
                    this.walk('HBP');
                    break;
            }
        }

        walk(kind) {
            const s = this.s;
            const batter = this.currentBatter();
            const pitcher = this.currentPitcher();

            const moves = {};
            if (s.bases[1]) {
                moves[1] = 2;
                if (s.bases[2]) {
                    moves[2] = 3;
                    if (s.bases[3]) moves[3] = 4;
                }
            }

            const key = kind === 'BB' ? 'bb' : 'hbp';
            this.bat(batter, 'pa');
            this.bat(batter, key);
            this.pitch(pitcher, key);

            const runs = this.moveRunners(moves, 1, batter, pitcher);
            this.bat(batter, 'rbi', runs);

            const text = kind === 'BB' ? 'krijgt vier wijd' : 'wordt geraakt door de werper';
            this.addPlay(this.name(batter) + ' ' + text + this.runsText(runs) + '.');
            this.nextBatter();
        }

        strikeout(swinging) {
            const batter = this.currentBatter();
            const pitcher = this.currentPitcher();

            this.bat(batter, 'pa');
            this.bat(batter, 'ab');
            this.bat(batter, 'so');
            this.pitch(pitcher, 'so');
            this.pitch(pitcher, 'outs');
            this.s.outs++;

            this.addPlay(this.name(batter) + ' gaat drie slag uit' + (swinging ? ' (zwaaiend)' : ' (kijkend)') + '.');
            this.nextBatter();
            this.checkSideRetired();
        }

        // --------------------------------------------------------------
        // Bal in het spel
        // --------------------------------------------------------------

        applyPlay(p) {
            this.requireBatter();
            const result = p.result;
            if (!PLAY_RESULTS.includes(result)) throw new InvalidPlay('Onbekende uitkomst van de slagbeurt.');
            const batterTo = p.batter_to === undefined || p.batter_to === null ? -1 : int(p.batter_to);
            if (batterTo < 0 || batterTo > 4) throw new InvalidPlay('Geef aan waar de slagman eindigt.');
            const moves = this.normaliseMoves(p.runners || {});

            const minimum = { '1B': 1, '2B': 2, '3B': 3, HR: 4 }[result];
            if (minimum !== undefined && batterTo !== 0 && batterTo < minimum) {
                throw new InvalidPlay('De slagman moet minimaal het honk van de honkslag halen.');
            }
            if (result === 'HR' && batterTo !== 4) throw new InvalidPlay('Bij een homerun scoort de slagman.');

            const s = this.s;
            const batter = this.currentBatter();
            const pitcher = this.requirePitcher();
            this.pitch(pitcher, 'pitches');
            this.pitch(pitcher, 'strikes');

            const isHit = ['1B', '2B', '3B', 'HR'].includes(result);
            const sacrifice = ['SF', 'SH'].includes(result);

            this.bat(batter, 'pa');
            if (sacrifice) this.bat(batter, result.toLowerCase());
            else this.bat(batter, 'ab');
            if (isHit) {
                this.bat(batter, 'h');
                this.bat(batter, { '1B': 'h1', '2B': '2b', '3B': '3b', HR: 'hr' }[result]);
                this.pitch(pitcher, 'h');
                s.hits[this.battingSide()]++;
            }
            if (result === 'E') s.errors[this.fieldingSide()]++;

            const outsBefore = s.outs;
            const runs = this.moveRunners(moves, batterTo, batter, pitcher);
            const outsOnPlay = s.outs - outsBefore;

            const hasRbi = p.rbi !== undefined && p.rbi !== null;
            const rbi = hasRbi ? Math.max(0, int(p.rbi)) : ((result === 'E' || outsOnPlay >= 2) ? 0 : runs);
            this.bat(batter, 'rbi', rbi);

            // PHP behandelt de string "0" als leeg; dat doen we hier ook.
            const fielders = p.fielders && p.fielders !== '0' ? String(p.fielders) : null;
            this.addPlay(this.describePlay(result, batter, batterTo, fielders, runs, outsOnPlay));
            this.nextBatter();
            this.checkSideRetired();
        }

        describePlay(result, batter, to, fielders, runs, outs) {
            const labels = {
                '1B': 'slaat een honkslag',
                '2B': 'slaat een tweehonkslag',
                '3B': 'slaat een driehonkslag',
                HR: 'slaat een homerun',
                GO: 'slaat een grondbal',
                FO: 'slaat een vangbal',
                LO: 'slaat een lijnbal',
                E: 'bereikt het honk door een fout',
                FC: "bereikt het honk via een fielder's choice",
                SF: 'slaat een opofferingsvangbal',
                SH: 'legt een opofferingsstootslag',
            };
            let text = this.name(batter) + ' ' + labels[result];
            if (fielders) text += ' (' + fielders + ')';
            if (outs >= 2) text += ', dubbelspel';
            else if (to === 0 && !['SF', 'SH'].includes(result)) text += ', uit';
            return text + this.runsText(runs) + '.';
        }

        // --------------------------------------------------------------
        // Loopacties
        // --------------------------------------------------------------

        applyRunner(p) {
            const action = p.action;
            if (!RUNNER_ACTIONS.includes(action)) throw new InvalidPlay('Onbekende loopactie.');
            const moves = this.normaliseMoves(p.runners || {});
            if (!moves.length) throw new InvalidPlay('Kies minimaal één loper.');

            const s = this.s;
            const pitcher = this.requirePitcher();
            const movers = {};
            for (const [from, to] of moves) {
                movers[from] = s.bases[from].player;
                if (action === 'SB' && to !== 0) this.bat(movers[from], 'sb');
                if (action === 'CS' && to === 0) this.bat(movers[from], 'cs');
            }

            this.moveRunners(moves, null, null, pitcher);

            const labels = {
                SB: 'steelt',
                CS: 'wordt uitgegooid bij het stelen',
                WP: 'schuift op door een wilde worp',
                PB: 'schuift op door een doorgeschoten bal',
                BK: 'schuift op door een balk',
                PK: 'wordt afgegooid',
                ADV: 'schuift op',
            };
            const parts = moves.map(([from, to]) => {
                const where = to === 0 ? 'uit' : (to === 4 ? 'scoort' : 'naar ' + to + 'e honk');
                return this.name(movers[from]) + ' (' + where + ')';
            });
            this.addPlay(ucfirst(labels[action]) + ': ' + parts.join(', ') + '.');

            if (s.outs >= 3) this.endHalfInning();
        }

        // --------------------------------------------------------------
        // Wissels
        // --------------------------------------------------------------

        applyPitcherChange(p) {
            const side = this.requireSide(p.side);
            const player = int(p.player_id);
            if (player <= 0) throw new InvalidPlay('Kies een werper.');
            this.s.pitchers[side] = player;
            this.pitch(player, 'pitches', 0);
            this.addPlay('Werperswissel: ' + this.name(player) + ' komt pitchen.');
        }

        applySubstitution(p) {
            const s = this.s;
            const side = this.requireSide(p.side);
            const slot = p.slot === undefined || p.slot === null ? -1 : int(p.slot);
            const player = int(p.player_id);
            if (slot < 0 || s.lineups[side][slot] === undefined || s.lineups[side][slot] === null) {
                throw new InvalidPlay('Ongeldige plek in de slagvolgorde.');
            }
            if (player <= 0) throw new InvalidPlay('Kies een speler.');
            const old = s.lineups[side][slot];
            s.lineups[side][slot] = player;
            this.bat(player, 'pa', 0);

            for (const base of [1, 2, 3]) {
                const runner = s.bases[base];
                if (runner && runner.player === old && side === this.battingSide()) {
                    runner.player = player;
                }
            }
            this.addPlay('Wissel: ' + this.name(player) + ' vervangt ' + this.name(old) + '.');
        }

        // --------------------------------------------------------------
        // Kernlogica lopers
        // --------------------------------------------------------------

        /** @returns {Array<[number, number]>} [honk, bestemming], van 3 naar 1 gesorteerd */
        normaliseMoves(runners) {
            const s = this.s;
            const map = new Map();
            for (const key of Object.keys(runners)) {
                const from = int(key);
                const to = int(runners[key]);
                if (![1, 2, 3].includes(from)) throw new InvalidPlay('Ongeldig honk.');
                if (!s.bases[from]) throw new InvalidPlay('Er staat geen loper op het ' + from + 'e honk.');
                if (to !== 0 && (to < from || to > 4)) throw new InvalidPlay('Een loper kan niet terug naar een eerder honk.');
                if (to !== from) map.set(from, to);
            }
            return Array.from(map.entries()).sort((a, b) => b[0] - a[0]);
        }

        /**
         * @param {Array<[number, number]>|Object} movesIn
         * @returns {number} runs die tellen
         */
        moveRunners(movesIn, batterTo, batter, pitcher) {
            const s = this.s;
            const moves = (Array.isArray(movesIn) ? movesIn : Object.entries(movesIn).map(([f, t]) => [Number(f), t]))
                .slice().sort((a, b) => b[0] - a[0]);
            const moved = new Set(moves.map(([from]) => from));
            const next = { 1: s.bases[1], 2: s.bases[2], 3: s.bases[3] };
            let scored = [];
            let outs = 0;

            for (const [from, to] of moves) {
                const runner = s.bases[from];
                next[from] = null;
                if (to === 0) outs++;
                else if (to === 4) scored.push(runner);
                else next[to] = runner;
            }

            const targets = [];
            for (const [, to] of moves) if (to >= 1 && to <= 3) targets.push(to);
            for (const base of [1, 2, 3]) if (s.bases[base] && !moved.has(base)) targets.push(base);

            if (batter !== null && batterTo !== null) {
                if (batterTo === 0) outs++;
                else if (batterTo === 4) scored.push({ player: batter, pitcher });
                else {
                    targets.push(batterTo);
                    next[batterTo] = { player: batter, pitcher };
                }
            }

            if (new Set(targets).size !== targets.length) {
                throw new InvalidPlay('Er kunnen geen twee lopers op hetzelfde honk staan.');
            }
            if (s.outs + outs > 3) throw new InvalidPlay('Er kunnen niet meer dan drie uit zijn.');

            s.outs += outs;
            if (pitcher) this.pitch(pitcher, 'outs', outs);

            if (s.outs >= 3 && batterTo === 0) scored = [];

            for (const runner of scored) {
                this.bat(runner.player, 'r');
                if (runner.pitcher) this.pitch(runner.pitcher, 'r');
            }
            const runs = scored.length;
            const side = this.battingSide();
            s.score[side] += runs;
            s.linescore[side][s.inning] = (s.linescore[side][s.inning] || 0) + runs;

            s.bases = next;
            return runs;
        }

        checkSideRetired() {
            if (this.s.outs >= 3) this.endHalfInning();
        }

        endHalfInning() {
            const s = this.s;
            this.addPlay('Drie uit, einde ' + (s.half === 'top' ? 'eerste' : 'tweede') + ' helft van inning ' + s.inning + '.', 'system');
            s.outs = 0;
            s.balls = 0;
            s.strikes = 0;
            s.bases = { 1: null, 2: null, 3: null };
            if (s.half === 'top') {
                s.half = 'bottom';
            } else {
                s.half = 'top';
                s.inning++;
            }
            const side = this.battingSide();
            if (s.linescore[side][s.inning] === undefined) s.linescore[side][s.inning] = 0;
        }

        nextBatter() {
            const s = this.s;
            const side = this.battingSide();
            const count = s.lineups[side].length;
            s.batterIndex[side] = (s.batterIndex[side] + 1) % Math.max(1, count);
            s.balls = 0;
            s.strikes = 0;
        }

        // --------------------------------------------------------------
        // Hulpfuncties
        // --------------------------------------------------------------

        battingSide() { return this.s.half === 'top' ? 'away' : 'home'; }

        fieldingSide() { return this.s.half === 'top' ? 'home' : 'away'; }

        currentBatter() {
            const side = this.battingSide();
            const id = this.s.lineups[side][this.s.batterIndex[side]];
            return id === undefined ? null : id;
        }

        currentPitcher() {
            return this.s.pitchers[this.fieldingSide()] || null;
        }

        requireBatter() {
            const b = this.currentBatter();
            if (b === null) throw new InvalidPlay('Vul eerst de slagvolgorde in.');
            return b;
        }

        requirePitcher() {
            const p = this.currentPitcher();
            if (p === null) throw new InvalidPlay('Kies eerst een werper voor het veldteam.');
            return p;
        }

        requireSide(side) {
            if (side !== 'home' && side !== 'away') throw new InvalidPlay('Kies een team.');
            return side;
        }

        bat(player, key, amount = 1) {
            if (!this.s.batting[player]) {
                this.s.batting[player] = {
                    pa: 0, ab: 0, h: 0, h1: 0, '2b': 0, '3b': 0, hr: 0,
                    r: 0, rbi: 0, bb: 0, hbp: 0, so: 0, sb: 0, cs: 0, sf: 0, sh: 0,
                };
            }
            this.s.batting[player][key] += amount;
        }

        pitch(player, key, amount = 1) {
            if (!this.s.pitching[player]) {
                this.s.pitching[player] = {
                    pitches: 0, strikes: 0, balls: 0, outs: 0, h: 0, r: 0, bb: 0, hbp: 0, so: 0,
                };
            }
            this.s.pitching[player][key] += amount;
        }

        name(player) {
            return player ? (this.names[player] !== undefined ? this.names[player] : 'Speler ' + player) : 'Onbekend';
        }

        runsText(runs) {
            if (runs === 1) return ', 1 run scoort';
            if (runs > 1) return ', ' + runs + ' runs scoren';
            return '';
        }

        addPlay(text, kind = 'play') {
            const s = this.s;
            s.plays.push({ inning: s.inning, half: s.half, kind, text, score: { away: s.score.away, home: s.score.home } });
        }

        // --------------------------------------------------------------
        // Uitvoer (zelfde vorm als GameEngine::state() in PHP)
        // --------------------------------------------------------------

        state() {
            const s = JSON.parse(JSON.stringify(this.s));
            const bases = {};
            for (const base of [1, 2, 3]) {
                const r = s.bases[base];
                bases[base] = r ? { player_id: r.player, name: this.name(r.player) } : null;
            }
            const batting = {};
            for (const id of Object.keys(s.batting)) batting[id] = Object.assign(s.batting[id], battingRates(s.batting[id]));
            const pitching = {};
            for (const id of Object.keys(s.pitching)) pitching[id] = Object.assign(s.pitching[id], pitchingRates(s.pitching[id]));

            const batter = this.currentBatter();
            const pitcher = this.currentPitcher();

            return {
                inning: s.inning,
                half: s.half,
                batting_side: this.battingSide(),
                outs: s.outs,
                balls: s.balls,
                strikes: s.strikes,
                bases,
                score: s.score,
                hits: s.hits,
                errors: s.errors,
                linescore: s.linescore,
                lineups: s.lineups,
                pitchers: s.pitchers,
                batter: batter ? { player_id: batter, name: this.name(batter) } : null,
                pitcher: pitcher ? {
                    player_id: pitcher,
                    name: this.name(pitcher),
                    pitches: s.pitching[pitcher] ? s.pitching[pitcher].pitches : 0,
                } : null,
                batting,
                pitching,
                plays: s.plays,
                last_event_id: s.lastEventId,
            };
        }
    }

    const api = { GameEngine, InvalidPlay, PLAY_RESULTS, RUNNER_ACTIONS, PITCHES };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else global.HonkbalEngine = api;
})(typeof window !== 'undefined' ? window : globalThis);
