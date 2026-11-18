<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b2545">
    <title>@yield('title', 'Honkbal Live') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=1">
    @stack('head')
</head>
<body class="@yield('body-class')">
<a class="skip-link" href="#inhoud">Naar de inhoud</a>
<header class="site-header">
    <div class="wrap header-inner">
        <a class="brand" href="{{ route('home') }}">
            <span class="brand-ball" aria-hidden="true"></span>
            {{ config('app.name') }}
        </a>
        <nav aria-label="Hoofdmenu">
            <ul class="nav">
                <li><a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>Wedstrijden</a></li>
                @auth
                    <li><a href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.dashboard', 'admin.games.*', 'admin.score.*')) aria-current="page" @endif>Beheer</a></li>
                    <li><a href="{{ route('admin.teams.index') }}" @if(request()->routeIs('admin.teams.*')) aria-current="page" @endif>Teams</a></li>
                    <li>
                        <form method="post" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="link-button">Uitloggen</button>
                        </form>
                    </li>
                @else
                    <li><a href="{{ route('login') }}">Inloggen</a></li>
                @endauth
            </ul>
        </nav>
    </div>
</header>
<main id="inhoud" class="wrap">
    @include('partials.flash')
    @yield('content')
</main>
@stack('scripts')
</body>
</html>
