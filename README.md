# Honkbal Live

Webapp om honkbalwedstrijden **pitch voor pitch te scoren** en **live te laten volgen**, met optioneel de YouTube-livestream erbij en een scorebord-overlay voor in de stream.

## Doel

- Eén scorer houdt op telefoon of tablet de wedstrijd bij: wijd, slag, foul, slagresultaten, lopers, wissels.
- Ouders, fans en spelers volgen via een openbare link: stand, situatie op de honken, telling, verloop en statistieken.
- De YouTube-stream staat op dezelfde pagina; het scorebord kan worden vertraagd zodat het niets verklapt.
- Een transparante overlay (stand, inning, honken, telling, outs) kan als browserbron in OBS of Prism in de stream.

## Functies (versie 0.1)

| Onderdeel | Wat het doet |
|---|---|
| Teams & spelers | Teams met kleur en afkorting; spelers snel toevoegen (één per regel, alleen rugnummer mag ook) |
| Wedstrijden | Thuis/uit, aanvang, locatie, aantal innings, YouTube-link, vertraging scorebord |
| Opstelling | Slagvolgorde (tot 12), posities, startende werpers. Ligt vast na de eerste pitch |
| Scoren | Wijd / slag (kijkend, zwaaiend) / foul / geraakt / in het spel; honkslag t/m homerun, grondbal-, vang-, lijnbal uit, fout, fielder's choice, opofferingen; lopersvoorstel dat je kunt aanpassen |
| Loopacties | Gestolen honk, uitgegooid, wilde worp, doorgeschoten bal, balk, afgegooid, opschuiven |
| Wissels | Werperswissel, slag-/loperswissel (pinch runner vervangt de loper op het honk) |
| Ongedaan maken | Laatste actie terugdraaien, onbeperkt |
| Statistieken | Slag: AB, R, H, 2B, 3B, HR, RBI, BB, HBP, SO, SB, CS, AVG, OBP, SLG. Werp: IP, H, R, BB, SO, pitches |
| Live-pagina | Scorebord, stand per inning (R/H/E), situatie, verloop, statistieken; ververst elke 4 s |
| Overlay | `/wedstrijd/{code}/overlay`, transparant, ververst elke 2 s, zonder vertraging |
| Privacy | Per team instelbaar: openbaar alleen voornaam + rugnummer (standaard) of volledige naam |

## Hoe het werkt

De bron van waarheid is een **lijst met gebeurtenissen** per wedstrijd (`game_events`). De klasse
`App\Scoring\GameEngine` speelt die af en rekent stand, outs, honken, slagvolgorde en statistieken uit.
Daardoor is ongedaan maken simpelweg: laatste gebeurtenis verwijderen en opnieuw afspelen.
De engine valideert elke nieuwe gebeurtenis (bijv. "er staat geen loper op het 1e honk") voordat hij wordt opgeslagen.

```
app/Scoring/GameEngine.php        rekenregels honkbal (geen database)
app/Scoring/GameStateService.php  koppeling database ↔ engine, opslaan, ongedaan maken
app/Scoring/StatFormatter.php     AVG/OBP/SLG en innings pitched
app/Http/Controllers/...          beheer, scoren (JSON) en openbare pagina's
public/js/scorer.js               scoorscherm (geen build-stap nodig)
public/js/live.js, overlay.js     live-pagina en overlay (pollen JSON)
```

Frontend is bewust zonder build-stap (gewone CSS en JavaScript in `public/`), zodat het op elke PHP-hosting draait.

## Installatie

Vereist: PHP 8.3+, Composer, SQLite of MySQL/MariaDB.

```bash
git clone https://github.com/jurjenvanzijl/honkbal.git
cd honkbal
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite      # bij SQLite
php artisan migrate
php artisan honkbal:gebruiker       # maak een scorer/beheerder aan
php artisan serve                   # http://localhost:8000
```

Demodata (verzonnen namen) laden: `php artisan migrate:fresh --seed`, inloggen met `demo@example.com` / `demo-wachtwoord`.
**Niet gebruiken op productie.**

## Configuratie (`.env`)

| Variabele | Uitleg |
|---|---|
| `APP_NAME` | Naam in de header, standaard "Honkbal Live" |
| `APP_URL` | Volledige URL van de site (nodig voor juiste links naar live-pagina en overlay) |
| `APP_LOCALE` / `APP_TIMEZONE` | Standaard `nl` en `Europe/Amsterdam` |
| `DB_CONNECTION` | `sqlite` (standaard) of `mysql` met `DB_HOST`, `DB_DATABASE`, enz. |
| `APP_ENV=production`, `APP_DEBUG=false` | Altijd zo op een live server |

Er is geen openbare registratie: accounts maak je met `php artisan honkbal:gebruiker`.

## Gebruik

1. **Teams** → *Nieuw team*: je eigen team en de tegenstander. Voeg spelers toe.
2. **Beheer** → *Nieuwe wedstrijd*. Plak eventueel de YouTube-link en stel de vertraging in (begin met ±15 s).
3. **Opstelling**: slagvolgorde en startende werpers per team.
4. **Scoren**: tik per pitch. Bij *In het spel* kies je de uitkomst; de app stelt voor waar lopers eindigen, pas dat aan waar nodig en bevestig. Vergissing? *Ongedaan maken*.
5. Deel de **live-pagina**-link (staat bij *Bewerken* van de wedstrijd). Na afloop: *Wedstrijd beëindigen*.

### Livestream

- Streamen vanaf een telefoon via de YouTube-app kan pas vanaf 50 abonnees. Met een app als **Larix Broadcaster** of **Prism Live** en een streamsleutel (YouTube Studio → *Live gaan* → *Stream*) geldt die grens niet.
- Zet de stream op **Verborgen** als hij alleen via de live-pagina te zien moet zijn, en laat *Insluiten toestaan* aan.
- **Overlay**: voeg in OBS/Prism een *browserbron* toe met de overlay-URL (bv. 640×120). De achtergrond is transparant.
- **Toestemming**: bij jeugdteams heb je toestemming van ouders nodig voor beeld en namen. Laat "volledige namen tonen" uit tenzij die toestemming er is.

## Testen

```bash
php artisan test
```

Unit tests voor de spelregels (`tests/Unit/GameEngineTest.php`) en feature tests voor de hele flow
(inloggen, opstelling, scoren, ongedaan maken, openbare pagina, privacy, vertraging) in `tests/Feature/ScoringFlowTest.php`.
GitHub Actions draait de tests bij elke push.

## Bekende beperkingen / nog niet gebouwd

- **Geen offline-modus**: zonder bereik wordt een actie niet opgeslagen (de scorer krijgt een duidelijke melding).
- Live-pagina ververst via polling (elke 4 s), nog geen WebSockets.
- Vereenvoudigingen: geen dropped third strike, geen earned runs/ERA, geen fielding-statistieken per verdediger, RBI-regel vereenvoudigd (geen RBI bij fout of dubbelspel; overschrijfbaar via `rbi` in het event).
- Geen seizoensstatistieken over meerdere wedstrijden, geen spray chart.
- Eén rol: iedereen met een account mag alles beheren.

## Ideeën voor volgende versies

Offline scoren met synchronisatie (PWA), seizoensstatistieken, spray chart, WebSockets (Laravel Reverb),
pushmeldingen, export naar PDF/CSV, meerdere clubs/rollen.
