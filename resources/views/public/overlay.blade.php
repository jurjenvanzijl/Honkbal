<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Overlay {{ $game->awayTeam->short_name }} – {{ $game->homeTeam->short_name }}</title>
    <link rel="stylesheet" href="{{ asset('css/overlay.css') }}?v=1">
</head>
<body>
{{-- Transparante scorebalk voor OBS / Prism (browserbron, bv. 640×120). --}}
<div class="ov" data-overlay data-state-url="{{ route('games.overlay.state', $game->slug) }}">
    <div class="ov-teams">
        <div class="ov-team" style="--team: {{ $game->awayTeam->color }}">
            <span class="ov-abbr">{{ $game->awayTeam->short_name }}</span>
            <span class="ov-score" data-score="away">{{ $game->away_score }}</span>
        </div>
        <div class="ov-team" style="--team: {{ $game->homeTeam->color }}">
            <span class="ov-abbr">{{ $game->homeTeam->short_name }}</span>
            <span class="ov-score" data-score="home">{{ $game->home_score }}</span>
        </div>
    </div>
    <div class="ov-inning" data-inning>{{ $game->inning_label }}</div>
    <div class="ov-diamond" data-diamond></div>
    <div class="ov-count">
        <span data-count>0-0</span>
        <span class="ov-outs" data-outs aria-label="0 uit"></span>
    </div>
</div>
<script src="{{ asset('js/common.js') }}?v=1"></script>
<script src="{{ asset('js/overlay.js') }}?v=1"></script>
</body>
</html>
