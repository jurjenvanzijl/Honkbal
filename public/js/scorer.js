/**
 * Scoorscherm: pitch voor pitch invoeren, ook zonder internet.
 *
 * Werking:
 *  - De server stuurt de opstelling ("setup") en alle opgeslagen events.
 *  - Nieuwe acties gaan eerst in een wachtrij op dit toestel (localStorage) en worden direct
 *    lokaal doorgerekend met public/js/engine.js (zelfde regels als de server).
 *  - Op de achtergrond verstuurt het script de wachtrij, één actie tegelijk. Elke actie heeft een
 *    uniek client_id, dus opnieuw versturen na een verbroken verbinding geeft geen dubbele acties.
 *  - Ongedaan maken: nog niet verstuurd → uit de wachtrij halen; wel verstuurd → een
 *    "ongedaan maken"-opdracht in de wachtrij zetten voor dat specifieke event.
 */
(function () {
    'use strict';
    const root = document.querySelector('[data-scorer]');
    if (!root) return;

    const H = window.Honkbal;
    const { GameEngine, InvalidPlay } = window.HonkbalEngine;
    const cfg = JSON.parse(root.dataset.config);
    const $ = (sel) => root.querySelector(sel);
    const $$ = (sel) => Array.from(root.querySelectorAll(sel));

    const STORE_KEY = 'honkbal:game:' + cfg.gameId;
    const BASE_LABEL = { 0: 'Uit', 1: '1e honk', 2: '2e honk', 3: '3e honk', 4: 'Scoort' };

    let csrf = document.querySelector('meta[name="csrf-token"]').content;
    let store = loadStore();
    let state = null;
    let currentResult = null;
    let syncing = false;
    let online = navigator.onLine;
    let problem = null; // {message, kind: 'conflict'|'auth'}

    // ------------------------------------------------------------------
    // Opslag op het toestel
    // ------------------------------------------------------------------

    function loadStore() {
        try {
            const raw = localStorage.getItem(STORE_KEY);
            if (raw) return JSON.parse(raw);
        } catch (e) { /* geen opslag beschikbaar */ }
        return { setup: null, serverEvents: [], meta: null, queue: [] };
    }

    function saveStore() {
        try {
            localStorage.setItem(STORE_KEY, JSON.stringify(store));
        } catch (e) {
            showError('Kan niet opslaan op dit toestel (privévenster of opslag vol?). Offline scoren werkt dan niet.');
        }
    }

    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        const b = new Uint8Array(16);
        (window.crypto || window.msCrypto).getRandomValues(b);
        b[6] = (b[6] & 0x0f) | 0x40;
        b[8] = (b[8] & 0x3f) | 0x80;
        const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
        return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
    }

    // ------------------------------------------------------------------
    // Lokaal doorrekenen
    // ------------------------------------------------------------------

    /** Opgeslagen events + wachtrij = wat de scorer ziet. */
    function effectiveEvents() {
        const list = store.serverEvents.slice();
        for (const op of store.queue) {
            if (op.op === 'event') list.push({ type: op.type, payload: op.payload, client_id: op.client_id });
            else list.pop();
        }
        return list;
    }

    function buildEngine() {
        const engine = new GameEngine(store.setup.lineups, store.setup.pitchers, store.meta.players);
        let conflict = null;
        for (const event of effectiveEvents()) {
            try {
                engine.apply(event);
            } catch (e) {
                if (!(e instanceof InvalidPlay)) throw e;
                conflict = e.message;
                break;
            }
        }
        return { engine, conflict };
    }

    function refresh() {
        if (!store.setup) return;
        const { engine, conflict } = buildEngine();
        if (conflict && !problem) {
            problem = { kind: 'conflict', message: 'De acties op dit toestel passen niet meer op de opgeslagen wedstrijd: ' + conflict };
        }
        render(Object.assign(engine.state(), {
            game: store.meta.game,
            players: store.meta.players,
            player_sides: store.meta.player_sides,
        }));
        renderSync();
    }

    function takeServerState(data) {
        store.setup = data.setup;
        store.serverEvents = data.events;
        store.meta = { game: data.game, players: data.players, player_sides: data.player_sides };
        saveStore();
    }

    // ------------------------------------------------------------------
    // Acties van de scorer
    // ------------------------------------------------------------------

    function addEvent(type, payload) {
        if (!store.setup) return false;
        if (store.meta.game.status === 'final') {
            showError('De wedstrijd is afgelopen. Zet hem eerst weer live.');
            return false;
        }
        const { engine, conflict } = buildEngine();
        if (conflict) {
            showError('Los eerst het synchronisatieprobleem op.');
            return false;
        }
        try {
            engine.apply({ type, payload });
        } catch (e) {
            if (!(e instanceof InvalidPlay)) throw e;
            showError(e.message);
            return false;
        }
        showError('');
        store.queue.push({ op: 'event', client_id: uuid(), type, payload });
        saveStore();
        refresh();
        sync();
        return true;
    }

    function undo() {
        const last = store.queue[store.queue.length - 1];
        if (last && last.op === 'event') {
            store.queue.pop();
        } else {
            const events = effectiveEvents();
            const target = events[events.length - 1];
            if (!target) {
                showError('Er is niets om ongedaan te maken.');
                return;
            }
            store.queue.push({ op: 'undo', event_id: target.id });
        }
        showError('');
        saveStore();
        refresh();
        announce('Laatste actie ongedaan gemaakt.');
        sync();
    }

    // ------------------------------------------------------------------
    // Synchroniseren met de server
    // ------------------------------------------------------------------

    async function http(method, url, body) {
        const res = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: body ? JSON.stringify(body) : undefined,
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        return { res, data };
    }

    async function refreshToken() {
        const { res, data } = await http('GET', cfg.tokenUrl);
        if (res.ok && data.token) {
            csrf = data.token;
            return true;
        }
        return false;
    }

    function sendOp(op) {
        if (op.op === 'event') return http('POST', cfg.eventUrl, { type: op.type, payload: op.payload, client_id: op.client_id });
        return http('DELETE', cfg.undoUrl, { event_id: op.event_id });
    }

    async function sync() {
        if (syncing || problem) return;
        syncing = true;
        renderSync();
        try {
            while (store.queue.length) {
                const op = store.queue[0];
                let { res, data } = await sendOp(op);
                if (res.status === 419 && await refreshToken()) {
                    ({ res, data } = await sendOp(op));
                }
                online = true;
                if (res.ok) {
                    store.queue.shift();
                    takeServerState(data);
                    continue;
                }
                if (res.status === 401 || res.status === 419) {
                    problem = { kind: 'auth', message: 'Je bent uitgelogd. Je acties staan veilig op dit toestel: log opnieuw in en open dit scherm weer.' };
                } else {
                    problem = { kind: 'conflict', message: data.message || 'De server weigerde een actie.' };
                }
                break;
            }
            if (!store.queue.length) await pull();
        } catch (e) {
            online = false; // geen verbinding: later opnieuw
        } finally {
            syncing = false;
            refresh();
        }
    }

    /** Haal de actuele stand op (bv. acties van een tweede scorer). Alleen als de wachtrij leeg is. */
    async function pull() {
        const { res, data } = await http('GET', cfg.stateUrl);
        if (res.ok && !store.queue.length) {
            online = true;
            takeServerState(data);
        } else if (res.status === 401) {
            problem = { kind: 'auth', message: 'Je bent uitgelogd. Log opnieuw in.' };
        }
    }

    function discardQueue() {
        if (!confirm(`${store.queue.length} niet-verstuurde actie(s) weggooien en de stand van de server laden?`)) return;
        store.queue = [];
        problem = null;
        saveStore();
        sync();
    }

    function retry() {
        problem = null;
        sync();
    }

    // ------------------------------------------------------------------
    // Weergave
    // ------------------------------------------------------------------

    function renderSync() {
        const el = $('[data-sync]');
        const n = store.queue.length;
        const waiting = n === 1 ? '1 actie' : n + ' acties';
        let text;
        let cls;
        if (problem) {
            text = problem.message;
            cls = 'sync-problem';
        } else if (syncing && n) {
            text = 'Versturen… (' + waiting + ')';
            cls = 'sync-busy';
        } else if (!online && n) {
            text = 'Offline: ' + waiting + ' bewaard op dit toestel. Wordt verstuurd zodra er verbinding is.';
            cls = 'sync-offline';
        } else if (!online) {
            text = 'Offline: je kunt gewoon doorscoren.';
            cls = 'sync-offline';
        } else if (n) {
            text = waiting + ' wachten op versturen.';
            cls = 'sync-busy';
        } else {
            text = 'Online: alles is opgeslagen.';
            cls = 'sync-ok';
        }
        el.textContent = text;
        el.className = 'sync ' + cls;
        $('[data-sync-actions]').hidden = !problem;
        $('[data-discard]').hidden = !problem || !n;
    }

    function showError(message) {
        const el = $('[data-error]');
        el.textContent = message;
        el.hidden = !message;
    }

    function announce(text) {
        $('[data-announce]').textContent = text;
    }

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
        $$('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== name; });
        if (name === 'play') resetPlayPanel();
        if (name === 'runner') renderRunnerMoves();
        const heading = $(`[data-panel="${name}"] h2`);
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

    function submitPlay() {
        if (!currentResult) return;
        const runners = {};
        $$('[data-moves] select').forEach((sel) => {
            if (sel.dataset.from !== 'B') runners[sel.dataset.from] = Number(sel.value);
        });
        const payload = { result: currentResult, batter_to: Number($('#mv-b').value), runners };
        const fielders = $('[data-fielders]').value.trim();
        if (fielders) payload.fielders = fielders;
        if (addEvent('play', payload)) closePanels();
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
        const taken = new Set(occupied);
        const defaults = {};
        occupied.forEach((b) => {
            let to = b;
            if (['WP', 'PB', 'BK'].includes(action)) to = Math.min(4, b + 1);
            if (action === 'SB' && b < 3 && !taken.has(b + 1)) { to = b + 1; taken.delete(b); taken.add(to); }
            defaults[b] = to;
        });
        if (action === 'CS' || action === 'PK') defaults[Math.min(...occupied)] = 0;
        $('[data-runner-moves]').innerHTML = occupied
            .map((b) => moveSelect('rm-' + b, `Loper op ${BASE_LABEL[b]}: ${state.bases[b].name}`, b, defaults[b], false))
            .join('');
    }

    function submitRunner() {
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
        if (addEvent('runner', { action: $('[data-runner-action]').value, runners })) closePanels();
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
    // Wedstrijdstatus (vereist verbinding)
    // ------------------------------------------------------------------

    async function setStatus(status) {
        if (store.queue.length) {
            showError('Wacht tot alle acties verstuurd zijn voordat je de status wijzigt.');
            return;
        }
        const label = status === 'final' ? 'De wedstrijd beëindigen?' : 'De wedstrijd (weer) live zetten?';
        if (!confirm(label)) return;
        try {
            let { res, data } = await http('POST', cfg.statusUrl, { status });
            if (res.status === 419 && await refreshToken()) ({ res, data } = await http('POST', cfg.statusUrl, { status }));
            if (!res.ok) {
                showError(data.message || 'Status wijzigen is niet gelukt.');
                return;
            }
            takeServerState(data);
            showError('');
            refresh();
        } catch (e) {
            showError('Voor het wijzigen van de status is verbinding nodig.');
        }
    }

    // ------------------------------------------------------------------
    // Event listeners
    // ------------------------------------------------------------------

    root.addEventListener('click', (e) => {
        const btn = e.target.closest('button');
        if (!btn || !state) return;

        if (btn.dataset.pitch) addEvent('pitch', { pitch: btn.dataset.pitch });
        else if (btn.dataset.open) openPanel(btn.dataset.open);
        else if (btn.dataset.result) chooseResult(btn.dataset.result);
        else if (btn.hasAttribute('data-submit-play')) submitPlay();
        else if (btn.hasAttribute('data-submit-runner')) submitRunner();
        else if (btn.hasAttribute('data-close')) closePanels();
        else if (btn.hasAttribute('data-undo')) undo();
        else if (btn.dataset.setStatus) setStatus(btn.dataset.setStatus);
        else if (btn.hasAttribute('data-retry')) retry();
        else if (btn.hasAttribute('data-discard')) discardQueue();
    });

    $('[data-runner-action]').addEventListener('change', renderRunnerMoves);
    $('[data-sub-side]').addEventListener('change', fillBatterSub);

    $('[data-sub-pitcher]').addEventListener('submit', (e) => {
        e.preventDefault();
        const fielding = state.batting_side === 'home' ? 'away' : 'home';
        const player = Number($('[data-pitcher-select]').value);
        if (player && addEvent('pitcher', { side: fielding, player_id: player })) closePanels();
    });

    $('[data-sub-batter]').addEventListener('submit', (e) => {
        e.preventDefault();
        const payload = {
            side: $('[data-sub-side]').value,
            slot: Number($('[data-sub-slot]').value),
            player_id: Number($('[data-sub-player]').value),
        };
        if (payload.player_id && addEvent('sub', payload)) closePanels();
    });

    window.addEventListener('online', () => { online = true; sync(); });
    window.addEventListener('offline', () => { online = false; renderSync(); });
    // Regelmatig: wachtrij versturen of de stand verversen (tweede scorer).
    setInterval(() => { if (!document.hidden) sync(); }, 10000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) sync(); });

    // ------------------------------------------------------------------
    // Opstarten
    // ------------------------------------------------------------------

    (async function start() {
        if (store.setup) refresh(); // direct de lokale stand tonen
        try {
            const { res, data } = await http('GET', cfg.stateUrl);
            if (res.ok) {
                online = true;
                if (!store.queue.length) takeServerState(data);
                else {
                    // Wachtrij aanwezig: alleen setup en namen bijwerken, events volgen na het versturen.
                    store.setup = data.setup;
                    store.meta = { game: data.game, players: data.players, player_sides: data.player_sides };
                    if (!store.serverEvents.length) store.serverEvents = data.events;
                    saveStore();
                }
            } else if (res.status === 401) {
                problem = { kind: 'auth', message: 'Je bent uitgelogd. Log opnieuw in.' };
            }
        } catch (e) {
            online = false;
        }
        if (!store.setup) {
            showError('Geen verbinding en deze wedstrijd is nog niet eerder op dit toestel geopend. Open het scoorscherm één keer met internet.');
            return;
        }
        refresh();
        sync();
    })();
})();
