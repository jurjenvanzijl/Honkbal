@extends('layouts.app')

@section('title', $team->exists ? $team->name : 'Nieuw team')

@section('content')
    <h1>{{ $team->exists ? $team->name : 'Nieuw team' }}</h1>

    <form method="post" action="{{ $team->exists ? route('admin.teams.update', $team) : route('admin.teams.store') }}" class="card form">
        @csrf
        @if ($team->exists) @method('put') @endif
        <div class="form-row">
            <div class="field grow">
                <label for="name">Naam</label>
                <input id="name" name="name" value="{{ old('name', $team->name) }}" required maxlength="100">
            </div>
            <div class="field">
                <label for="short_name">Afkorting <span class="muted">(max. 4)</span></label>
                <input id="short_name" name="short_name" value="{{ old('short_name', $team->short_name) }}" required maxlength="4" size="5">
            </div>
            <div class="field">
                <label for="color">Kleur</label>
                <input id="color" type="color" name="color" value="{{ old('color', $team->color) }}">
            </div>
        </div>
        <div class="field check">
            <input id="show_full_names" type="checkbox" name="show_full_names" value="1" @checked(old('show_full_names', $team->show_full_names))>
            <label for="show_full_names">Volledige namen tonen op openbare pagina's</label>
        </div>
        <p class="muted small">Staat dit uit, dan tonen we op de live-pagina alleen voornaam en rugnummer. Zet het alleen aan als de spelers (of bij jeugd: hun ouders) daar toestemming voor geven.</p>
        <button type="submit" class="btn btn-primary">Opslaan</button>
    </form>

    @if ($team->exists)
        <section class="card">
            <h2>Spelers</h2>
            @if ($team->players->isEmpty())
                <p class="muted">Nog geen spelers.</p>
            @else
                <p class="muted small player-legend" aria-hidden="true"><span>Nr.</span><span>Voornaam</span><span>Achternaam</span><span>Pos.</span><span>Actief</span></p>
                <ul class="player-list">
                    @foreach ($team->players as $player)
                        <li>
                            <form method="post" action="{{ route('admin.players.update', $player) }}" class="player-row">
                                @csrf @method('put')
                                <input name="number" value="{{ $player->number }}" inputmode="numeric" maxlength="3" aria-label="Rugnummer van {{ $player->first_name }}">
                                <input name="first_name" value="{{ $player->first_name }}" required maxlength="100" aria-label="Voornaam">
                                <input name="last_name" value="{{ $player->last_name }}" maxlength="100" aria-label="Achternaam van {{ $player->first_name }}">
                                <input name="position" value="{{ $player->position }}" maxlength="3" aria-label="Positie van {{ $player->first_name }}">
                                <input type="checkbox" name="active" value="1" @checked($player->active) aria-label="{{ $player->first_name }} is actief">
                                <span class="row-actions">
                                    <button type="submit" class="btn btn-small">Opslaan</button>
                                    <button type="submit" class="btn btn-small btn-danger" form="del-{{ $player->id }}">Verwijderen</button>
                                </span>
                            </form>
                            <form id="del-{{ $player->id }}" method="post" action="{{ route('admin.players.destroy', $player) }}" onsubmit="return confirm('Speler verwijderen?')">
                                @csrf @method('delete')
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="post" action="{{ route('admin.players.store', $team) }}" class="form">
                @csrf
                <div class="field">
                    <label for="players">Spelers toevoegen</label>
                    <textarea id="players" name="players" rows="5" placeholder="12 Voornaam Achternaam&#10;7 Voornaam&#10;23" aria-describedby="players-help"></textarea>
                    <p id="players-help" class="muted small">Eén speler per regel: rugnummer, voornaam, achternaam. Voor een tegenstander is alleen een rugnummer genoeg.</p>
                </div>
                <button type="submit" class="btn">Toevoegen</button>
            </form>
        </section>

        <form method="post" action="{{ route('admin.teams.destroy', $team) }}" onsubmit="return confirm('Team en alle spelers verwijderen?')">
            @csrf @method('delete')
            <button type="submit" class="btn btn-danger">Team verwijderen</button>
        </form>
    @endif
@endsection
