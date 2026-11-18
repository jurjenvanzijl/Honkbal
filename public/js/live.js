/**
 * Live meekijkpagina: ververst het scorebord elke paar seconden.
 */
(function () {
    'use strict';
    const root = document.querySelector('[data-live]');
    if (!root) return;
    const H = window.Honkbal;
    const $ = (sel) => root.querySelector(sel);
    let lastPlayCount = null;

    function render(state) {
        $('[data-score="away"]').textContent = state.score.away;
        $('[data-score="home"]').textContent = state.score.home;
        const status = $('[data-status]');
        status.textContent = state.game.status_label;
        status.className = 'badge badge-' + state.game.status;
        if (state.game.status !== 'scheduled') {
            $('[data-inning]').textContent = state.game.status === 'final' ? 'Einde' : H.halfLabel(state);
            $('[data-inning]').title = H.halfLabelLong(state);
        }

        $('[data-diamond]').innerHTML = H.diamond(state.bases, 110);
        $('[data-balls]').textContent = state.balls;
        $('[data-strikes]').textContent = state.strikes;
        $('[data-outs]').textContent = state.outs;
        $('[data-batter]').textContent = state.batter ? state.batter.name : '–';
        $('[data-pitcher]').textContent = state.pitcher ? state.pitcher.name + ' (' + state.pitcher.pitches + ' pitches)' : '–';

        $('[data-linescore]').innerHTML = H.linescore(state);
        $('[data-boxscore]').innerHTML = H.boxscore(state);

        const plays = state.plays.slice().reverse();
        $('[data-plays]').innerHTML = plays.length
            ? plays.map((p) => `<li class="pbp-${p.kind}"><span class="pbp-inning">${H.esc(H.halfLabel(p))}</span> ${H.esc(p.text)}</li>`).join('')
            : '<li class="muted">Nog geen acties.</li>';

        // Nieuwste actie voorlezen voor schermlezers (niet bij de eerste keer laden).
        if (lastPlayCount !== null && state.plays.length > lastPlayCount) {
            $('[data-announce]').textContent = state.plays[state.plays.length - 1].text;
        }
        lastPlayCount = state.plays.length;

        $('[data-updated]').textContent = 'Bijgewerkt om ' + new Date().toLocaleTimeString('nl-NL');
    }

    H.poll(root.dataset.stateUrl, render, 4000);
})();
