@extends('layouts.app')

@section('title', 'Inloggen')

@section('content')
    <div class="card narrow">
        <h1>Inloggen</h1>
        <p class="muted">Alleen voor scorers en beheerders. Wedstrijden volgen kan zonder account.</p>
        <form method="post" action="{{ route('login') }}" class="form">
            @csrf
            <div class="field">
                <label for="email">E-mailadres</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>
            <div class="field">
                <label for="password">Wachtwoord</label>
                <input id="password" type="password" name="password" required autocomplete="current-password">
            </div>
            <div class="field check">
                <input id="remember" type="checkbox" name="remember" value="1">
                <label for="remember">Ingelogd blijven</label>
            </div>
            <button type="submit" class="btn btn-primary">Inloggen</button>
        </form>
    </div>
@endsection
