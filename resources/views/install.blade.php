@extends('layouts.app')

@section('title', 'Installatie')

@section('content')
    <div class="card narrow">
        <h1>Installatie</h1>
        <p class="alert alert-success" role="status">De database is bijgewerkt.</p>
        @if ($migrateOutput)
            <details>
                <summary>Details</summary>
                <pre class="small">{{ $migrateOutput }}</pre>
            </details>
        @endif

        @if ($needsUser)
            <h2>Eerste account aanmaken</h2>
            <form method="post" action="{{ route('install.store') }}" class="form">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="field">
                    <label for="name">Naam</label>
                    <input id="name" name="name" value="{{ old('name') }}" required maxlength="100" autocomplete="name">
                </div>
                <div class="field">
                    <label for="email">E-mailadres</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
                </div>
                <div class="field">
                    <label for="password">Wachtwoord <span class="muted">(minimaal 10 tekens)</span></label>
                    <input id="password" type="password" name="password" required minlength="10" autocomplete="new-password">
                </div>
                <div class="field">
                    <label for="password_confirmation">Wachtwoord herhalen</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required minlength="10" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary">Account aanmaken</button>
            </form>
        @else
            <p>Er is al een account. <a href="{{ route('login') }}">Ga naar inloggen</a>.</p>
        @endif
    </div>
@endsection
