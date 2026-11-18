@extends('layouts.app')

@section('title', 'Wedstrijden')

@section('content')
    <h1>Wedstrijden</h1>

    @if ($games->isEmpty())
        <p class="muted">Er zijn nog geen wedstrijden gepland.</p>
    @else
        <ul class="game-list">
            @foreach ($games as $game)
                <li>
                    <a class="game-row" href="{{ route('games.public', $game->slug) }}">
                        <span class="badge badge-{{ $game->status }}">{{ $game->statusLabel() }}</span>
                        <span class="game-row-teams">
                            <span class="team-line">
                                <span class="team-dot" style="--team: {{ $game->awayTeam->color }}" aria-hidden="true"></span>
                                {{ $game->awayTeam->name }}
                                @if ($game->status !== 'scheduled')<strong class="score">{{ $game->away_score }}</strong>@endif
                            </span>
                            <span class="team-line">
                                <span class="team-dot" style="--team: {{ $game->homeTeam->color }}" aria-hidden="true"></span>
                                {{ $game->homeTeam->name }}
                                @if ($game->status !== 'scheduled')<strong class="score">{{ $game->home_score }}</strong>@endif
                            </span>
                        </span>
                        <span class="game-row-meta">
                            @if ($game->status === 'live')
                                {{ $game->inning_label }}
                            @else
                                <time datetime="{{ $game->starts_at->toIso8601String() }}">{{ $game->starts_at->translatedFormat('D j M, H:i') }}</time>
                            @endif
                            @if ($game->youtube_url)
                                <span class="stream-tag">Stream</span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
