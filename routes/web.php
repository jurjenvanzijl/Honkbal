<?php

use App\Http\Controllers\Admin\GameController;
use App\Http\Controllers\Admin\PlayerController;
use App\Http\Controllers\Admin\ScoreController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\PublicGameController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------
// Openbaar: wedstrijden volgen
// ---------------------------------------------------------------
Route::get('/', [PublicGameController::class, 'index'])->name('home');
Route::get('/wedstrijd/{slug}', [PublicGameController::class, 'show'])->name('games.public');
Route::get('/wedstrijd/{slug}/stand.json', [PublicGameController::class, 'state'])
    ->middleware('throttle:120,1')->name('games.public.state');
Route::get('/wedstrijd/{slug}/overlay', [PublicGameController::class, 'overlay'])->name('games.overlay');
Route::get('/wedstrijd/{slug}/overlay.json', [PublicGameController::class, 'overlayState'])
    ->middleware('throttle:120,1')->name('games.overlay.state');

// ---------------------------------------------------------------
// Inloggen
// ---------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/inloggen', [LoginController::class, 'show'])->name('login');
    Route::post('/inloggen', [LoginController::class, 'login'])->middleware('throttle:5,1');
});
Route::post('/uitloggen', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// ---------------------------------------------------------------
// Beheer en scoren (alleen ingelogd)
// ---------------------------------------------------------------
Route::middleware('auth')->prefix('beheer')->name('admin.')->group(function () {
    Route::get('/', [GameController::class, 'index'])->name('dashboard');

    Route::resource('teams', TeamController::class)->except('show');
    Route::post('teams/{team}/spelers', [PlayerController::class, 'store'])->name('players.store');
    Route::put('spelers/{player}', [PlayerController::class, 'update'])->name('players.update');
    Route::delete('spelers/{player}', [PlayerController::class, 'destroy'])->name('players.destroy');

    Route::resource('wedstrijden', GameController::class)
        ->parameters(['wedstrijden' => 'game'])
        ->names('games')
        ->except(['index', 'show']);
    Route::get('wedstrijden/{game}/opstelling', [GameController::class, 'lineup'])->name('games.lineup');
    Route::put('wedstrijden/{game}/opstelling', [GameController::class, 'updateLineup'])->name('games.lineup.update');

    Route::get('csrf-token', [ScoreController::class, 'token'])->name('csrf');
    Route::get('wedstrijden/{game}/scoren', [ScoreController::class, 'show'])->name('score.show');
    Route::get('wedstrijden/{game}/stand', [ScoreController::class, 'state'])->name('score.state');
    Route::post('wedstrijden/{game}/events', [ScoreController::class, 'store'])->name('score.store');
    Route::delete('wedstrijden/{game}/events/laatste', [ScoreController::class, 'undo'])->name('score.undo');
    Route::post('wedstrijden/{game}/status', [ScoreController::class, 'status'])->name('score.status');
});
