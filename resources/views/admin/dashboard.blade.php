@extends('layouts.app')

@section('title', 'Beheer')

@section('content')
    <div class="page-head">
        <h1>Beheer</h1>
        <div class="actions">
            <a class="btn" href="{{ route('admin.teams.index') }}">Teams en spelers</a>
            @if ($teamCount >= 2)
                <a class="btn btn-primary" href="{{ route('admin.games.create') }}">Nieuwe wedstrijd</a>
            @endif
        </div>
    </div>

    @if ($teamCount < 2)
        <div class="card">
            <h2>Aan de slag</h2>
            <ol>
                <li>Maak je eigen team en de tegenstander aan via <a href="{{ route('admin.teams.create') }}">Nieuw team</a>.</li>
                <li>Voeg spelers toe (bij een tegenstander zijn alleen rugnummers genoeg).</li>
                <li>Maak een wedstrijd aan, vul de opstellingen in en begin met scoren.</li>
            </ol>
        </div>
    @endif

    @if ($games->isNotEmpty())
        <div class="table-scroll card">
            <table class="table">
                <caption class="visually-hidden">Wedstrijden</caption>
                <thead>
                <tr>
                    <th scope="col">Datum</th>
                    <th scope="col">Wedstrijd</th>
                    <th scope="col">Stand</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="visually-hidden">Acties</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($games as $game)
                    <tr>
                        <td>{{ $game->starts_at->translatedFormat('j M Y H:i') }}</td>
                        <td>{{ $game->awayTeam->name }} @ {{ $game->homeTeam->name }}</td>
                        <td>{{ $game->away_score }} – {{ $game->home_score }}</td>
                        <td><span class="badge badge-{{ $game->status }}">{{ $game->statusLabel() }}</span></td>
                        <td class="row-actions">
                            <a class="btn btn-primary btn-small" href="{{ route('admin.score.show', $game) }}">Scoren</a>
                            <a class="btn btn-small" href="{{ route('admin.games.lineup', $game) }}">Opstelling</a>
                            <a class="btn btn-small" href="{{ route('admin.games.edit', $game) }}">Bewerken</a>
                            <a class="btn btn-small" href="{{ route('games.public', $game->slug) }}">Live-pagina</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
