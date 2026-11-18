<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b2545">
    <title>@yield('title', 'Honkbal Live') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=2">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icon.svg') }}" type="image/svg+xml">
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
                        <form method="post" action="{{ route('logout') }}" data-logout>
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
@auth
<script>
    // Uitloggen: waarschuw als er nog niet-verstuurde scores op dit toestel staan,
    // en verwijder de bewaarde (ingelogde) pagina's uit de offline-cache.
    document.querySelector('[data-logout]')?.addEventListener('submit', function (e) {
        let pending = 0;
        try {
            Object.keys(localStorage).filter((k) => k.startsWith('honkbal:game:')).forEach((k) => {
                pending += (JSON.parse(localStorage.getItem(k)).queue || []).length;
            });
        } catch (err) { /* geen opslag */ }
        if (pending && !confirm(pending + ' actie(s) zijn nog niet verstuurd. Ze blijven op dit toestel bewaard en worden verstuurd als je weer inlogt en het scoorscherm opent. Toch uitloggen?')) {
            e.preventDefault();
            return;
        }
        if (window.caches) caches.delete('honkbal-pages');
    });
</script>
@endauth
@stack('scripts')
</body>
</html>
