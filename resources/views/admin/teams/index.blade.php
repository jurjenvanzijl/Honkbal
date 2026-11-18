@extends('layouts.app')

@section('title', 'Teams')

@section('content')
    <div class="page-head">
        <h1>Teams</h1>
        <a class="btn btn-primary" href="{{ route('admin.teams.create') }}">Nieuw team</a>
    </div>

    @if ($teams->isEmpty())
        <p class="muted">Nog geen teams.</p>
    @else
        <ul class="team-list">
            @foreach ($teams as $team)
                <li class="card team-card">
                    <span class="team-dot big" style="--team: {{ $team->color }}" aria-hidden="true"></span>
                    <div>
                        <a href="{{ route('admin.teams.edit', $team) }}"><strong>{{ $team->name }}</strong></a>
                        <span class="muted">({{ $team->short_name }}) · {{ $team->players_count }} spelers</span>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
