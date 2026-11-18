/**
 * Hulpscript voor tests/Unit/EngineParityTest.php.
 * Leest scenario's (JSON) uit het bestand in argv[2], speelt ze af in public/js/engine.js
 * en schrijft per scenario de uitkomst van elk event plus de eindstand als JSON naar stdout.
 */
const fs = require('fs');
const path = require('path');
const { GameEngine, InvalidPlay } = require(path.join(__dirname, '../../public/js/engine.js'));

const input = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const results = input.cases.map((c) => {
    const engine = new GameEngine(c.lineups, c.pitchers, c.names);
    const outcomes = c.events.map((event) => {
        try {
            engine.apply(event);
            return null;
        } catch (e) {
            if (!(e instanceof InvalidPlay)) throw e;
            return e.message;
        }
    });
    return { outcomes, state: engine.state() };
});
process.stdout.write(JSON.stringify({ results }));
