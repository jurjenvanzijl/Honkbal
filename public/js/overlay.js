/**
 * Overlay voor in de livestream (OBS/Prism browserbron).
 */
(function () {
    'use strict';
    const root = document.querySelector('[data-overlay]');
    if (!root) return;
    const H = window.Honkbal;
    const $ = (sel) => root.querySelector(sel);

    function render(state) {
        $('[data-score="away"]').textContent = state.score.away;
        $('[data-score="home"]').textContent = state.score.home;
        $('[data-inning]').textContent = state.game.status === 'final' ? 'FINAL' : H.halfLabel(state);
        $('[data-diamond]').innerHTML = H.diamond(state.bases, 56);
        $('[data-count]').textContent = state.balls + '-' + state.strikes;
        const outs = $('[data-outs]');
        outs.innerHTML = [0, 1, 2].map((i) => `<i class="${i < state.outs ? 'on' : ''}"></i>`).join('');
        outs.setAttribute('aria-label', state.outs + ' uit');
    }

    H.poll(root.dataset.stateUrl, render, 2000);
})();
