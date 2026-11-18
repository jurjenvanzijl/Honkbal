@extends('layouts.app')

@section('title', 'Opstelling')

@section('content')
    <div class="page-head">
        <h1>Opstelling: {{ $game->awayTeam->name }} @ {{ $game->homeTeam->name }}</h1>
        <a class="btn btn-primary" href="{{ route('admin.score.show', $game) }}">Naar scoren</a>
    </div>

    @if ($locked)
        <div class="alert alert-info" role="status">De wedstrijd is al begonnen. Wissels doe je in het scoorscherm.</div>
    @endif

    <form method="post" action="{{ route('admin.games.lineup.update', $game) }}" class="form">
        @csrf @method('put')
        <div class="lineup-grid">
            @foreach (['away' => $game->awayTeam, 'home' => $game->homeTeam] as $side => $team)
                @php($current = $game->lineups->where('side', $side)->values())
                <fieldset class="card" @disabled($locked)>
                    <legend>{{ $team->name }} <span class="muted">({{ $side === 'away' ? 'uit' : 'thuis' }})</span></legend>

                    @if ($team->players->isEmpty())
                        <p class="muted">Dit team heeft nog geen spelers. <a href="{{ route('admin.teams.edit', $team) }}">Spelers toevoegen</a>.</p>
                    @else
                        <ol class="lineup-list">
                            @for ($i = 0; $i < $max; $i++)
                                @php($row = $current[$i] ?? null)
                                <li class="lineup-row">
                                    <select name="lineup[{{ $side }}][{{ $i }}][player_id]" aria-label="{{ $team->name }}, slagbeurt {{ $i + 1 }}: speler">
                                        <option value="">{{ $i < 9 ? '— kies speler —' : '— (optioneel) —' }}</option>
                                        @foreach ($team->players->where('active', true) as $player)
                                            <option value="{{ $player->id }}" @selected(old("lineup.$side.$i.player_id", $row?->player_id) == $player->id)>{{ $player->fullName() }}</option>
                                        @endforeach
                                    </select>
                                    <select name="lineup[{{ $side }}][{{ $i }}][position]" aria-label="{{ $team->name }}, slagbeurt {{ $i + 1 }}: positie">
                                        <option value="">Pos.</option>
                                        @foreach ($positions as $pos)
                                            <option value="{{ $pos }}" @selected(old("lineup.$side.$i.position", $row?->position) === $pos)>{{ $pos }}</option>
                                        @endforeach
                                    </select>
                                </li>
                            @endfor
                        </ol>

                        <div class="field">
                            <label for="{{ $side }}_pitcher_id">Startende werper</label>
                            <select id="{{ $side }}_pitcher_id" name="{{ $side }}_pitcher_id">
                                <option value="">— kies werper —</option>
                                @foreach ($team->players->where('active', true) as $player)
                                    <option value="{{ $player->id }}" @selected(old("{$side}_pitcher_id", $game->{$side.'_pitcher_id'}) == $player->id)>{{ $player->fullName() }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </fieldset>
            @endforeach
        </div>
        @unless ($locked)
            <button type="submit" class="btn btn-primary">Opstellingen opslaan</button>
        @endunless
    </form>
@endsection
