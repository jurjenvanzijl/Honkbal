/**
 * Gedeelde weergavefuncties voor de live-pagina, de overlay en het scoorscherm.
 * Geen build-stap nodig: gewoon JavaScript in de browser.
 */
(function () {
    'use strict';

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);

    const BASE_NAMES = { 1: 'eerste', 2: 'tweede', 3: 'derde' };

    /** SVG van het binnenveld met bezette honken. */
    function diamond(bases, size = 120) {
        const occupied = [1, 2, 3].filter((b) => bases && bases[b]);
        const label = occupied.length
            ? 'Lopers op ' + occupied.map((b) => BASE_NAMES[b] + ' honk' + (bases[b].name ? ' (' + bases[b].name + ')' : '')).join(', ')
            : 'Geen lopers op de honken';
        // Posities: 2e boven, 1e rechts, 3e links, thuis onder.
        const pos = { 1: [78, 50], 2: [50, 22], 3: [22, 50] };
        const baseRect = (b) => {
            const [x, y] = pos[b];
            const cls = bases && bases[b] ? 'base on' : 'base';
            return `<rect class="${cls}" x="${x - 9}" y="${y - 9}" width="18" height="18" transform="rotate(45 ${x} ${y})"/>`;
        };
        return `<svg class="diamond" viewBox="0 0 100 100" width="${size}" height="${size}" role="img" aria-label="${esc(label)}">
            <path class="infield" d="M50 22 L78 50 L50 78 L22 50 Z"/>
            ${baseRect(1)}${baseRect(2)}${baseRect(3)}
            <path class="home-plate" d="M44 80 L56 80 L56 86 L50 91 L44 86 Z"/>
        </svg>`;
    }

    function halfLabel(state) {
        return (state.half === 'top' ? '▲ ' : '▼ ') + state.inning;
    }

    function halfLabelLong(state) {
        return (state.half === 'top' ? 'Eerste helft ' : 'Tweede helft ') + state.inning + 'e inning';
    }

    /** Tabel met runs per inning + R/H/E. */
    function linescore(state) {
        const teams = state.game.teams;
        const innings = Math.max(state.game.innings_planned, state.inning);
        let head = '<tr><th scope="col"><span class="visually-hidden">Team</span></th>';
        for (let i = 1; i <= innings; i++) head += `<th scope="col">${i}</th>`;
        head += '<th scope="col" title="Runs">R</th><th scope="col" title="Honkslagen">H</th><th scope="col" title="Fouten">E</th></tr>';

        const row = (side) => {
            let html = `<tr><th scope="row">${esc(teams[side].short_name)}</th>`;
            for (let i = 1; i <= innings; i++) {
                const v = state.linescore[side] ? state.linescore[side][i] : undefined;
                const current = state.game.status === 'live' && state.inning === i && state.batting_side === side;
                html += `<td${current ? ' class="current"' : ''}>${v === undefined ? '' : esc(v)}</td>`;
            }
            html += `<td class="total">${esc(state.score[side])}</td><td>${esc(state.hits[side])}</td><td>${esc(state.errors[side])}</td></tr>`;
            return html;
        };

        return `<table class="linescore"><caption class="visually-hidden">Runs per inning</caption><thead>${head}</thead><tbody>${row('away')}${row('home')}</tbody></table>`;
    }

    /** Slag- en werpstatistieken per team. */
    function boxscore(state) {
        const name = (id) => state.players[id] || ('Speler ' + id);
        let html = '';
        ['away', 'home'].forEach((side) => {
            const team = state.game.teams[side];
            html += `<h3>${esc(team.name)}</h3>`;
            html += '<div class="table-scroll"><table class="stats"><caption class="visually-hidden">Slagstatistieken ' + esc(team.name) + '</caption>'
                + '<thead><tr><th scope="col">Slagman</th><th scope="col" title="At bats">AB</th><th scope="col" title="Runs">R</th><th scope="col" title="Honkslagen">H</th><th scope="col" title="Runs binnengeslagen">RBI</th><th scope="col" title="Vier wijd">BB</th><th scope="col" title="Drie slag">SO</th><th scope="col" title="Slaggemiddelde">AVG</th></tr></thead><tbody>';
            const seen = new Set();
            (state.lineups[side] || []).forEach((id) => {
                seen.add(id);
                const s = state.batting[id];
                html += statRow(name(id), s);
            });
            // Spelers die zijn gewisseld maar wel stats hebben.
            Object.keys(state.batting).forEach((key) => {
                const id = Number(key);
                if (!seen.has(id) && belongsTo(state, id, side)) html += statRow(name(id), state.batting[id], true);
            });
            html += '</tbody></table></div>';

            const pitchers = Object.keys(state.pitching).map(Number).filter((id) => pitcherSide(state, id) === side);
            if (pitchers.length) {
                html += '<div class="table-scroll"><table class="stats"><caption class="visually-hidden">Werpstatistieken ' + esc(team.name) + '</caption>'
                    + '<thead><tr><th scope="col">Werper</th><th scope="col" title="Innings gegooid">IP</th><th scope="col" title="Honkslagen tegen">H</th><th scope="col" title="Runs tegen">R</th><th scope="col" title="Vier wijd">BB</th><th scope="col" title="Strike-outs">SO</th><th scope="col" title="Aantal pitches">P</th></tr></thead><tbody>';
                pitchers.forEach((id) => {
                    const p = state.pitching[id];
                    html += `<tr><th scope="row">${esc(name(id))}</th><td>${esc(p.ip)}</td><td>${p.h}</td><td>${p.r}</td><td>${p.bb}</td><td>${p.so}</td><td>${p.pitches}</td></tr>`;
                });
                html += '</tbody></table></div>';
            }
        });
        return html;
    }

    function statRow(label, s, sub = false) {
        if (!s) {
            return `<tr><th scope="row">${esc(label)}</th><td>0</td><td>0</td><td>0</td><td>0</td><td>0</td><td>0</td><td>-</td></tr>`;
        }
        return `<tr${sub ? ' class="sub"' : ''}><th scope="row">${esc(label)}</th><td>${s.ab}</td><td>${s.r}</td><td>${s.h}</td><td>${s.rbi}</td><td>${s.bb}</td><td>${s.so}</td><td>${esc(s.avg)}</td></tr>`;
    }

    function belongsTo(state, id, side) {
        return state.player_sides ? state.player_sides[id] === side : false;
    }

    function pitcherSide(state, id) {
        return state.player_sides ? state.player_sides[id] : null;
    }

    /**
     * Haalt periodiek JSON op. Pauzeert als het tabblad niet zichtbaar is.
     */
    function poll(url, onData, interval = 5000) {
        let timer = null;
        let etag = null;
        const tick = async () => {
            clearTimeout(timer);
            try {
                const res = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (res.ok) {
                    const text = await res.text();
                    if (text !== etag) {
                        etag = text;
                        onData(JSON.parse(text));
                    }
                }
            } catch (e) {
                // Netwerk weg: gewoon later opnieuw proberen.
            }
            if (!document.hidden) timer = setTimeout(tick, interval);
        };
        document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
        tick();
        return { refresh: tick };
    }

    window.Honkbal = { esc, diamond, linescore, boxscore, halfLabel, halfLabelLong, poll };
})();
