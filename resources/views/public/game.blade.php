@extends('layouts.app')

@section('title', $game->awayTeam->name.' – '.$game->homeTeam->name)

@section('content')
    <div class="live-page" data-live data-state-url="{{ route('games.public.state', $game->slug) }}">
        <section class="scoreboard card" aria-labelledby="scorebord-titel">
            <h1 id="scorebord-titel" class="visually-hidden">{{ $game->awayTeam->name }} tegen {{ $game->homeTeam->name }}</h1>
            <div class="sb-team" style="--team: {{ $game->awayTeam->color }}">
                <span class="sb-name">{{ $game->awayTeam->name }}</span>
                <span class="sb-score" data-score="away">{{ $game->away_score }}</span>
            </div>
            <div class="sb-mid">
                <span class="badge badge-{{ $game->status }}" data-status>{{ $game->statusLabel() }}</span>
                <span class="sb-inning" data-inning>{{ $game->status === 'scheduled' ? $game->starts_at->translatedFormat('j M H:i') : $game->inning_label }}</span>
            </div>
            <div class="sb-team sb-home" style="--team: {{ $game->homeTeam->color }}">
                <span class="sb-score" data-score="home">{{ $game->home_score }}</span>
                <span class="sb-name">{{ $game->homeTeam->name }}</span>
            </div>
        </section>

        <div class="live-grid">
            <div class="live-main">
                @if ($youtubeId)
                    <section class="stream card" aria-label="Livestream">
                        <div class="video">
                            <iframe
                                src="https://www.youtube-nocookie.com/embed/{{ $youtubeId }}?autoplay=0&rel=0"
                                title="Livestream {{ $game->awayTeam->name }} tegen {{ $game->homeTeam->name }}"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                allowfullscreen
                                loading="lazy"></iframe>
                        </div>
                        @if ($game->score_delay_seconds > 0)
                            <p class="muted small">Het scorebord loopt {{ $game->score_delay_seconds }} seconden achter, zodat het gelijk loopt met de stream.</p>
                        @endif
                    </section>
                @endif

                <section class="card" aria-labelledby="linescore-titel">
                    <h2 id="linescore-titel">Stand per inning</h2>
                    <div class="table-scroll" data-linescore><p class="muted">Laden…</p></div>
                </section>

                <section class="card" aria-labelledby="pbp-titel">
                    <h2 id="pbp-titel">Verloop</h2>
                    <div class="visually-hidden" aria-live="polite" data-announce></div>
                    <ol class="pbp" data-plays reversed></ol>
                </section>
                <section class="card" aria-labelledby="box-titel">
                    <h2 id="box-titel">Statistieken</h2>
                    <div data-boxscore><p class="muted">Laden…</p></div>
                </section>
            </div>

            <aside class="live-side">
                <section class="card situation" aria-labelledby="situatie-titel">
                    <h2 id="situatie-titel">Situatie</h2>
                    <div class="situation-grid">
                        <div data-diamond></div>
                        <dl class="count">
                            <div><dt>Wijd</dt><dd data-balls>0</dd></div>
                            <div><dt>Slag</dt><dd data-strikes>0</dd></div>
                            <div><dt>Uit</dt><dd data-outs>0</dd></div>
                        </dl>
                    </div>
                    <p><span class="muted">Aan slag:</span> <strong data-batter>–</strong></p>
                    <p><span class="muted">Werper:</span> <strong data-pitcher>–</strong></p>
                </section>

            </aside>
        </div>
        <p class="muted small" data-updated></p>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/common.js') }}?v=1"></script>
    <script src="{{ asset('js/live.js') }}?v=1"></script>
@endpush
