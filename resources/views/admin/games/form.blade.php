@extends('layouts.app')

@section('title', $game->exists ? 'Wedstrijd bewerken' : 'Nieuwe wedstrijd')

@section('content')
    <h1>{{ $game->exists ? 'Wedstrijd bewerken' : 'Nieuwe wedstrijd' }}</h1>

    @php($locked = $game->exists && $game->events()->exists())

    <form method="post" action="{{ $game->exists ? route('admin.games.update', $game) : route('admin.games.store') }}" class="card form">
        @csrf
        @if ($game->exists) @method('put') @endif

        <div class="form-row">
            <div class="field grow">
                <label for="away_team_id">Uitteam (slaat eerst)</label>
                <select id="away_team_id" name="away_team_id" required @disabled($locked)>
                    <option value="">Kies…</option>
                    @foreach ($teams as $team)
                        <option value="{{ $team->id }}" @selected(old('away_team_id', $game->away_team_id) == $team->id)>{{ $team->name }}</option>
                    @endforeach
                </select>
                @if ($locked)<input type="hidden" name="away_team_id" value="{{ $game->away_team_id }}">@endif
            </div>
            <div class="field grow">
                <label for="home_team_id">Thuisteam</label>
                <select id="home_team_id" name="home_team_id" required @disabled($locked)>
                    <option value="">Kies…</option>
                    @foreach ($teams as $team)
                        <option value="{{ $team->id }}" @selected(old('home_team_id', $game->home_team_id) == $team->id)>{{ $team->name }}</option>
                    @endforeach
                </select>
                @if ($locked)<input type="hidden" name="home_team_id" value="{{ $game->home_team_id }}">@endif
            </div>
        </div>
        @if ($locked)
            <p class="muted small">De wedstrijd is begonnen; de teams liggen vast.</p>
        @endif

        <div class="form-row">
            <div class="field">
                <label for="starts_at">Aanvang</label>
                <input id="starts_at" type="datetime-local" name="starts_at" required
                       value="{{ old('starts_at', $game->starts_at?->format('Y-m-d\TH:i')) }}">
            </div>
            <div class="field grow">
                <label for="location">Locatie</label>
                <input id="location" name="location" maxlength="150" value="{{ old('location', $game->location) }}">
            </div>
            <div class="field">
                <label for="innings_planned">Innings</label>
                <input id="innings_planned" type="number" name="innings_planned" min="1" max="12" required
                       value="{{ old('innings_planned', $game->innings_planned) }}">
            </div>
        </div>

        <fieldset>
            <legend>Livestream</legend>
            <div class="field">
                <label for="youtube_url">YouTube-link <span class="muted">(optioneel)</span></label>
                <input id="youtube_url" type="url" name="youtube_url" maxlength="255" placeholder="https://www.youtube.com/live/…"
                       value="{{ old('youtube_url', $game->youtube_url) }}" aria-describedby="yt-help">
                <p id="yt-help" class="muted small">Zet de stream in YouTube op <em>verborgen</em> als je hem alleen via deze pagina wilt delen, en zorg dat insluiten is toegestaan.</p>
            </div>
            <div class="field">
                <label for="score_delay_seconds">Vertraging scorebord (seconden)</label>
                <input id="score_delay_seconds" type="number" name="score_delay_seconds" min="0" max="120" required
                       value="{{ old('score_delay_seconds', $game->score_delay_seconds) }}" aria-describedby="delay-help">
                <p id="delay-help" class="muted small">Een YouTube-stream loopt meestal 5–30 seconden achter. Met deze vertraging verklapt het scorebord op de live-pagina niets voordat het in beeld is. De overlay in de stream heeft geen vertraging nodig.</p>
            </div>
        </fieldset>

        <button type="submit" class="btn btn-primary">{{ $game->exists ? 'Opslaan' : 'Aanmaken en opstelling invullen' }}</button>
    </form>

    @if ($game->exists)
        <section class="card">
            <h2>Links</h2>
            <p><strong>Live-pagina voor publiek:</strong><br><a href="{{ route('games.public', $game->slug) }}">{{ route('games.public', $game->slug) }}</a></p>
            <p><strong>Overlay voor OBS/Prism</strong> <span class="muted">(browserbron, bv. 640 × 120, transparante achtergrond)</span>:<br>
                <a href="{{ route('games.overlay', $game->slug) }}">{{ route('games.overlay', $game->slug) }}</a></p>
        </section>

        <form method="post" action="{{ route('admin.games.destroy', $game) }}" onsubmit="return confirm('Wedstrijd en alle scores definitief verwijderen?')">
            @csrf @method('delete')
            <button type="submit" class="btn btn-danger">Wedstrijd verwijderen</button>
        </form>
    @endif
@endsection
