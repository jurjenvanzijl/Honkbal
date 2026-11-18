# Honkbal Live – instructies voor Claude

Laravel 13-app (PHP 8.3+) om honkbalwedstrijden te scoren en live te volgen. Zie README.md.

- UI-teksten en foutmeldingen in het Nederlands.
- Spelregels zitten uitsluitend in `app/Scoring/GameEngine.php` (geen database-code daar). Nieuwe regels altijd met een unit test in `tests/Unit/GameEngineTest.php`.
- State wordt nooit los opgeslagen: alles volgt uit `game_events`. Kolommen als `games.home_score` zijn alleen een cache.
- Frontend zonder build-stap: `public/css`, `public/js`, Blade-views. Toegankelijkheid minimaal WCAG 2.1 AA.
- Openbare pagina's tonen namen via `Player::publicName()` (privacy-instelling per team).
- Tests: `php artisan test`.
