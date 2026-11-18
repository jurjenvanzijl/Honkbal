# Honkbal Live – instructies voor Claude

Laravel 13-app (PHP 8.3+) om honkbalwedstrijden te scoren en live te volgen. Zie README.md.

- UI-teksten en foutmeldingen in het Nederlands.
- Spelregels zitten in `app/Scoring/GameEngine.php` (leidend, geen database-code) én gespiegeld in `public/js/engine.js` voor offline scoren. Wijzig ALTIJD beide; `tests/Unit/EngineParityTest.php` controleert dat ze identiek rekenen. Nieuwe regels met een unit test in `tests/Unit/GameEngineTest.php` en zo nodig de generator in de parity-test uitbreiden.
- Scoorscherm werkt offline: acties gaan via een wachtrij in localStorage met een `client_id` (idempotent). Verander je het eventformaat, houd dan rekening met acties die nog in die wachtrij op een toestel staan.
- State wordt nooit los opgeslagen: alles volgt uit `game_events`. Kolommen als `games.home_score` zijn alleen een cache.
- Frontend zonder build-stap: `public/css`, `public/js`, Blade-views. Toegankelijkheid minimaal WCAG 2.1 AA.
- Openbare pagina's tonen namen via `Player::publicName()` (privacy-instelling per team).
- Tests: `php artisan test`.
