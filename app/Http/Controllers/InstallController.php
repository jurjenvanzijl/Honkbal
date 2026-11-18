<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Installatie/bijwerken zonder SSH (bv. Hostinger met alleen Bestandsbeheer).
 *
 * - Alleen bereikbaar met de geheime SETUP_TOKEN uit .env (anders 404).
 * - Voert openstaande databasemigraties uit.
 * - Zolang er nog geen gebruikers zijn: formulier voor het eerste account.
 */
class InstallController extends Controller
{
    public function show(Request $request): View
    {
        $this->authorizeToken($request->query('token'));

        Artisan::call('migrate', ['--force' => true]);
        $migrateOutput = trim(Artisan::output());

        return view('install', [
            'token' => $request->query('token'),
            'needsUser' => User::count() === 0,
            'migrateOutput' => $migrateOutput,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeToken($request->input('token'));
        abort_if(User::count() > 0, 403, 'Er bestaat al een account. Log in via /inloggen.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ], [], ['name' => 'naam', 'email' => 'e-mailadres', 'password' => 'wachtwoord']);

        $user = User::create($data);
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')->with('status', 'Account aangemaakt. Welkom bij Honkbal Live!');
    }

    private function authorizeToken(mixed $token): void
    {
        $expected = (string) config('app.setup_token');
        abort_if($expected === '' || ! is_string($token) || ! hash_equals($expected, $token), 404);
    }
}
