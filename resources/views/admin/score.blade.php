@extends('layouts.app')

@section('title', 'Scoren')
@section('body-class', 'scorer-body')

@php
    $rosters = [];
    foreach (['home' => $game->homeTeam, 'away' => $game->awayTeam] as $side => $team) {
        $rosters[$side] = $team->players->where('active', true)
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->fullName()])->values();
    }
    $config = [
        'stateUrl' => route('admin.score.state', $game),
        'eventUrl' => route('admin.score.store', $game),
        'undoUrl' => route('admin.score.undo', $game),
        'statusUrl' => route('admin.score.status', $game),
        'rosters' => $rosters,
    ];
@endphp

@section('content')
<div class="scorer" data-scorer data-config='@json($config)'>
    <section class="scorer-top card" aria-label="Huidige situatie">
        <div class="st-score">
            <span><span data-team="away">{{ $game->awayTeam->short_name }}</span> <strong data-score="away">0</strong></span>
            <span><span data-team="home">{{ $game->homeTeam->short_name }}</span> <strong data-score="home">0</strong></span>
        </div>
        <div class="st-inning" data-inning>▲ 1</div>
        <div data-diamond class="st-diamond"></div>
        <dl class="count">
            <div><dt>Wijd</dt><dd data-balls>0</dd></div>
            <div><dt>Slag</dt><dd data-strikes>0</dd></div>
            <div><dt>Uit</dt><dd data-outs>0</dd></div>
        </dl>
        <p class="st-who"><span class="muted">Slagman</span> <strong data-batter>–</strong></p>
        <p class="st-who"><span class="muted">Werper</span> <strong data-pitcher>–</strong></p>
    </section>

    <div class="alert alert-error" role="alert" data-error hidden></div>
    <div class="visually-hidden" aria-live="assertive" data-announce></div>

    {{-- Stap 1: pitch --}}
    <section class="panel" data-panel="pitch" aria-labelledby="p-pitch">
        <h2 id="p-pitch" class="visually-hidden">Pitch invoeren</h2>
        <div class="btn-grid">
            <button type="button" class="pad pad-ball" data-pitch="B">Wijd</button>
            <button type="button" class="pad pad-strike" data-pitch="CS">Slag<br><small>kijkend</small></button>
            <button type="button" class="pad pad-strike" data-pitch="SS">Slag<br><small>zwaaiend</small></button>
            <button type="button" class="pad" data-pitch="F">Foul</button>
            <button type="button" class="pad pad-play" data-open="play">In het spel</button>
            <button type="button" class="pad" data-pitch="HBP">Geraakt</button>
        </div>
        <div class="btn-row">
            <button type="button" class="btn" data-open="runner">Loopactie</button>
            <button type="button" class="btn" data-open="subs">Wissels</button>
            <button type="button" class="btn btn-undo" data-undo>↶ Ongedaan maken</button>
        </div>
    </section>

    {{-- Stap 2: bal in het spel --}}
    <section class="panel card" data-panel="play" hidden aria-labelledby="p-play">
        <h2 id="p-play">Bal in het spel</h2>
        <fieldset>
            <legend>Uitkomst</legend>
            <div class="btn-grid small">
                <button type="button" class="pad pad-hit" data-result="1B">Honkslag</button>
                <button type="button" class="pad pad-hit" data-result="2B">2-honkslag</button>
                <button type="button" class="pad pad-hit" data-result="3B">3-honkslag</button>
                <button type="button" class="pad pad-hit" data-result="HR">Homerun</button>
                <button type="button" class="pad" data-result="GO">Grondbal uit</button>
                <button type="button" class="pad" data-result="FO">Vangbal uit</button>
                <button type="button" class="pad" data-result="LO">Lijnbal uit</button>
                <button type="button" class="pad" data-result="E">Fout (E)</button>
                <button type="button" class="pad" data-result="FC">Fielder's choice</button>
                <button type="button" class="pad" data-result="SF">Opoff. vangbal</button>
                <button type="button" class="pad" data-result="SH">Opoff. stootslag</button>
            </div>
        </fieldset>
        <div data-play-details hidden>
            <div class="field">
                <label for="fielders">Verdedigers <span class="muted">(optioneel, bv. 6-3 of 8)</span></label>
                <input id="fielders" data-fielders maxlength="20" inputmode="text" autocomplete="off">
            </div>
            <fieldset>
                <legend>Waar eindigen slagman en lopers?</legend>
                <div data-moves></div>
            </fieldset>
            <div class="btn-row">
                <button type="button" class="btn btn-primary" data-submit-play>Bevestigen</button>
                <button type="button" class="btn" data-close>Annuleren</button>
            </div>
        </div>
        <div class="btn-row" data-play-cancel>
            <button type="button" class="btn" data-close>Annuleren</button>
        </div>
    </section>

    {{-- Loopacties --}}
    <section class="panel card" data-panel="runner" hidden aria-labelledby="p-runner">
        <h2 id="p-runner">Loopactie</h2>
        <div class="field">
            <label for="runner-action">Actie</label>
            <select id="runner-action" data-runner-action>
                <option value="SB">Gestolen honk</option>
                <option value="CS">Uitgegooid bij stelen</option>
                <option value="WP">Wilde worp</option>
                <option value="PB">Doorgeschoten bal</option>
                <option value="BK">Balk</option>
                <option value="PK">Afgegooid</option>
                <option value="ADV">Opschuiven (overig)</option>
            </select>
        </div>
        <fieldset>
            <legend>Lopers</legend>
            <div data-runner-moves></div>
        </fieldset>
        <div class="btn-row">
            <button type="button" class="btn btn-primary" data-submit-runner>Bevestigen</button>
            <button type="button" class="btn" data-close>Annuleren</button>
        </div>
    </section>

    {{-- Wissels --}}
    <section class="panel card" data-panel="subs" hidden aria-labelledby="p-subs">
        <h2 id="p-subs">Wissels</h2>
        <form class="form" data-sub-pitcher>
            <h3>Werperswissel (veldteam)</h3>
            <div class="field">
                <label for="new-pitcher">Nieuwe werper</label>
                <select id="new-pitcher" data-pitcher-select required></select>
            </div>
            <button type="submit" class="btn btn-primary">Werper wisselen</button>
        </form>
        <form class="form" data-sub-batter>
            <h3>Slag- of loperswissel</h3>
            <div class="field">
                <label for="sub-side">Team</label>
                <select id="sub-side" data-sub-side>
                    <option value="away">{{ $game->awayTeam->name }}</option>
                    <option value="home">{{ $game->homeTeam->name }}</option>
                </select>
            </div>
            <div class="field">
                <label for="sub-slot">Wie gaat eruit?</label>
                <select id="sub-slot" data-sub-slot required></select>
            </div>
            <div class="field">
                <label for="sub-player">Wie komt erin?</label>
                <select id="sub-player" data-sub-player required></select>
            </div>
            <button type="submit" class="btn btn-primary">Wisselen</button>
        </form>
        <div class="btn-row">
            <button type="button" class="btn" data-close>Sluiten</button>
        </div>
    </section>

    <section class="card" aria-labelledby="p-plays">
        <h2 id="p-plays">Laatste acties</h2>
        <ol class="pbp" data-plays reversed></ol>
    </section>

    <section class="card scorer-game" aria-labelledby="p-game">
        <h2 id="p-game">Wedstrijd</h2>
        <p>Status: <span class="badge" data-status></span></p>
        <div class="btn-row">
            <button type="button" class="btn" data-set-status="live">Wedstrijd live zetten</button>
            <button type="button" class="btn btn-danger" data-set-status="final">Wedstrijd beëindigen</button>
            <a class="btn" href="{{ route('games.public', $game->slug) }}" target="_blank" rel="noopener">Live-pagina ↗</a>
            <a class="btn" href="{{ route('admin.games.lineup', $game) }}">Opstelling</a>
        </div>
    </section>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('js/common.js') }}?v=1"></script>
    <script src="{{ asset('js/scorer.js') }}?v=1"></script>
@endpush
