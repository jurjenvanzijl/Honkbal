/**
 * Scoorscherm: pitch voor pitch invoeren. Alle logica (tellen, outs, innings) zit op de
 * server in de GameEngine; dit script stuurt alleen events en tekent de teruggegeven state.
 */
(function () {
    'use strict';
    const root = document.querySelector('[data-scorer]');
    if (!root) return;

    const H = window.Honkbal;
    const cfg = JSON.parse(root.dataset.config);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const $ = (sel) => root.querySelector(sel);
    const $$ = (sel) => Array.from(root.querySelectorAll(sel));

    let state = null;
    let busy = false;
    let currentResult = null;

    const BASE_LABEL = { 0: 'Uit', 1: '1e honk', 2: '2e honk', 3: '3e honk', 4: 'Scoort' };

    // ------------------------------------------------------------------
    // Communicatie met de server
    // ------------------------------------------------------------------

    async function request(method, url, body) {
        if (busy) return;
        busy = true;
        root.classList.add('is-busy');
        showError('');
        try {
            const res = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: body ? JSON.stringify(body) : undefined,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                if (res.status === 419 || res.status === 401) {
                    showError('Je sessie is verlopen. Ververs de pagina en log opnieuw in.');
                } else {
                    showError(data.message || 'Opslaan is niet gelukt.');
                }
                return false;
            }
            render(data);
            return true;
        } catch (e) {
            showError('Geen verbinding: de actie is NIET opgeslagen. Probeer het opnieuw zodra je bereik hebt.');
            return false;
        } finally {
            busy = false;
            root.classList.remove('is-busy');
        }
    }

    const sendEvent = (type, payload) => request('POST', cfg.eventUrl, { type, payload });

    function showError(message) {
        const el = $('[data-error]');
        el.textContent = message;
        el.hidden = !message;
    }

    function announce(text) {
        $('[data-announce]').textContent = text;
    }

    // ------------------------------------------------------------------
    // Weergave
    // ------------------------------------------------------------------

    function render(s) {
        const previousPlays = state ? state.plays.length : null;
        state = s;

        $('[data-score="away"]').textContent = s.score.away;
        $('[data-score="home"]').textContent = s.score.home;
        $('[data-inning]').textContent = H.halfLabel(s);
        $('[data-inning]').title = H.halfLabelLong(s);
        $('[data-diamond]').innerHTML = H.diamond(s.bases, 96);
        $('[data-balls]').textContent = s.balls;
        $('[data-strikes]').textContent = s.strikes;
        $('[data-outs]').textContent = s.outs;
        $('[data-batter]').textContent = s.batter ? s.batter.name : 'Geen opstelling';
        $('[data-pitcher]').textContent = s.pitcher ? `${s.pitcher.name} (${s.pitcher.pitches})` : 'Geen werper';

        const status = $('[data-status]');
        status.textContent = s.game.status_label;
        status.className = 'badge badge-' + s.game.status;

        const plays = s.plays.slice(-12).reverse();
        $('[data-plays]').innerHTML = plays.length
            ? plays.map((p) => `<li class="pbp-${p.kind}"><span class="pbp-inning">${H.esc(H.halfLabel(p))}</span> ${H.esc(p.text)}</li>`).join('')
            : '<li class="muted">Nog geen acties. Begin met de eerste pitch.</li>';

        if (previousPlays !== null && s.plays.length > previousPlays) {
            announce(s.plays[s.plays.length - 1].text);
        }
        fillSubForms();
    }

    // ------------------------------------------------------------------
    // Panelen
    // ------------------------------------------------------------------

    function openPanel(name) {
        $$('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== name && p.dataset.panel !== 'pitch'; });
        $('[data-panel="pitch"]').hidden = name !== 'pitch';
        const panel = $(`[data-panel="${name}"]`);
        if (name === 'play') resetPlayPanel();
        if (name === 'runner') renderRunnerMoves();
        const heading = panel.querySelector('h2');
        if (heading && name !== 'pitch') {
            heading.tabIndex = -1;
            heading.focus();
        }
    }

    function closePanels() {
        openPanel('pitch');
    }

    // ------------------------------------------------------------------
    // Bal in het spel
    // ------------------------------------------------------------------

    function resetPlayPanel() {
        currentResult = null;
        $$('[data-result]').forEach((b) => b.setAttribute('aria-pressed', 'false'));
        $('[data-play-details]').hidden = true;
        $('[data-play-cancel]').hidden = false;
        $('[data-fielders]').value = '';
    }

    /** Voorstel waar iedereen eindigt; de scorer kan het aanpassen. */
    function defaultMoves(result, bases) {
        const occupied = [3, 2, 1].filter((b) => bases[b]);
        const batterTo = { '1B': 1, '2B': 2, '3B': 3, HR: 4, E: 1, FC: 1 }[result] ?? 0;
        const runners = {};
        occupied.forEach((b) => {
            let to = b;
            switch (result) {
                case '1B': case 'E': case 'SH': to = Math.min(4, b + 1); break;
                case '2B': to = Math.min(4, b + 2); break;
                case '3B': case 'HR': to = 4; break;
                case 'SF': to = b === 3 ? 4 : b; break;
                case 'FC': to = b === 1 ? 0 : b; break;
                default: to = b;
            }
            runners[b] = to;
        });
        return { batterTo, runners };
    }

    function moveSelect(id, label, from, selected, isBatter) {
        const options = [0];
        for (let b = Math.max(1, from); b <= 4; b++) options.push(b);
        const opts = options.map((v) => {
            const text = !isBatter && v === from ? `Blijft op ${BASE_LABEL[v]}` : BASE_LABEL[v];
            return `<option value="${v}"${v === selected ? ' selected' : ''}>${text}</option>`;
        }).join('');
        return `<div class="field move"><label for="${id}">${H.esc(label)}</label><select id="${id}" data-from="${isBatter ? 'B' : from}">${opts}</select></div>`;
    }

    function chooseResult(result) {
        currentResult = result;
        $$('[data-result]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.result === result)));
        const { batterTo, runners } = defaultMoves(result, state.bases);
        let html = moveSelect('mv-b', 'Slagman: ' + (state.batter ? state.batter.name : ''), 0, batterTo, true);
        [3, 2, 1].forEach((b) => {
            if (state.bases[b]) {
                html += moveSelect('mv-' + b, `Loper op ${BASE_LABEL[b]}: ${state.bases[b].name}`, b, runners[b], false);
            }
        });
        $('[data-moves]').innerHTML = html;
        $('[data-play-details]').hidden = false;
        $('[data-play-cancel]').hidden = true;
        $('[data-submit-play]').focus();
    }

    async function submitPlay() {
        if (!currentResult) return;
        const runners = {};
        $$('[data-moves] select').forEach((sel) => {
            if (sel.dataset.from !== 'B') runners[sel.dataset.from] = Number(sel.value);
        });
        const payload = {
            result: currentResult,
            batter_to: Number($('#mv-b').value),
            runners,
        };
        const fielders = $('[data-fielders]').value.trim();
        if (fielders) payload.fielders = fielders;
        if (await sendEvent('play', payload)) closePanels();
    }

    // ------------------------------------------------------------------
    // Loopacties
    // ------------------------------------------------------------------

    function renderRunnerMoves() {
        const action = $('[data-runner-action]').value;
        const occupied = [3, 2, 1].filter((b) => state.bases[b]);
        if (!occupied.length) {
            $('[data-runner-moves]').innerHTML = '<p class="muted">Er staan geen lopers op de honken.</p>';
            return;
        }
        // Voorstel per actie.
        const taken = new Set(occupied);
        const defaults = {};
        occupied.forEach((b) => {
            let to = b;
            if (['WP', 'PB', 'BK'].includes(action)) to = Math.min(4, b + 1);
            if (action === 'SB' && b < 3 && !taken.has(b + 1)) { to = b + 1; taken.delete(b); taken.add(to); }
            defaults[b] = to;
        });
        if (action === 'CS' || action === 'PK') {
            const lowest = Math.min(...occupied);
            defaults[lowest] = 0;
        }
        $('[data-runner-moves]').innerHTML = occupied
            .map((b) => moveSelect('rm-' + b, `Loper op ${BASE_LABEL[b]}: ${state.bases[b].name}`, b, defaults[b], false))
            .join('');
    }

    async function submitRunner() {
        const runners = {};
        $$('[data-runner-moves] select').forEach((sel) => {
            const from = Number(sel.dataset.from);
            const to = Number(sel.value);
            if (to !== from) runners[from] = to;
        });
        if (!Object.keys(runners).length) {
            showError('Er verandert niets: kies waar een loper naartoe gaat.');
            return;
        }
        if (await sendEvent('runner', { action: $('[data-runner-action]').value, runners })) closePanels();
    }

    // ------------------------------------------------------------------
    // Wissels
    // ------------------------------------------------------------------

    function fillSubForms() {
        if (!state) return;
        const fielding = state.batting_side === 'home' ? 'away' : 'home';
        const current = state.pitcher ? state.pitcher.player_id : null;
        $('[data-pitcher-select]').innerHTML = cfg.rosters[fielding]
            .filter((p) => p.id !== current)
            .map((p) => `<option value="${p.id}">${H.esc(p.name)}</option>`).join('');
        fillBatterSub();
    }

    function fillBatterSub() {
        const side = $('[data-sub-side]').value;
        const lineup = state.lineups[side] || [];
        $('[data-sub-slot]').innerHTML = lineup
            .map((id, i) => `<option value="${i}">${i + 1}. ${H.esc(state.players[id] || 'Speler ' + id)}</option>`).join('');
        $('[data-sub-player]').innerHTML = cfg.rosters[side]
            .filter((p) => !lineup.includes(p.id))
            .map((p) => `<option value="${p.id}">${H.esc(p.name)}</option>`).join('');
    }

    // ------------------------------------------------------------------
    // Event listeners
    // ------------------------------------------------------------------

    root.addEventListener('click', async (e) => {
        const btn = e.target.closest('button');
        if (!btn || busy) return;

        if (btn.dataset.pitch) {
            await sendEvent('pitch', { pitch: btn.dataset.pitch });
        } else if (btn.dataset.open) {
            openPanel(btn.dataset.open);
        } else if (btn.dataset.result) {
            chooseResult(btn.dataset.result);
        } else if (btn.hasAttribute('data-submit-play')) {
            await submitPlay();
        } else if (btn.hasAttribute('data-submit-runner')) {
            await submitRunner();
        } else if (btn.hasAttribute('data-close')) {
            closePanels();
        } else if (btn.hasAttribute('data-undo')) {
            if (await request('DELETE', cfg.undoUrl)) announce('Laatste actie ongedaan gemaakt.');
        } else if (btn.dataset.setStatus) {
            const label = btn.dataset.setStatus === 'final' ? 'De wedstrijd beëindigen?' : 'De wedstrijd (weer) live zetten?';
            if (confirm(label)) await request('POST', cfg.statusUrl, { status: btn.dataset.setStatus });
        }
    });

    $('[data-runner-action]').addEventListener('change', renderRunnerMoves);
    $('[data-sub-side]').addEventListener('change', fillBatterSub);

    $('[data-sub-pitcher]').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fielding = state.batting_side === 'home' ? 'away' : 'home';
        const player = Number($('[data-pitcher-select]').value);
        if (player && await sendEvent('pitcher', { side: fielding, player_id: player })) closePanels();
    });

    $('[data-sub-batter]').addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = {
            side: $('[data-sub-side]').value,
            slot: Number($('[data-sub-slot]').value),
            player_id: Number($('[data-sub-player]').value),
        };
        if (payload.player_id && await sendEvent('sub', payload)) closePanels();
    });

    // Eerste keer laden.
    fetch(cfg.stateUrl, { headers: { Accept: 'application/json' } })
        .then((r) => r.json())
        .then(render)
        .catch(() => showError('Kon de wedstrijd niet laden. Ververs de pagina.'));
})();
