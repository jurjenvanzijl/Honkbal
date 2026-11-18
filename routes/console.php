<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

/*
 * Maak een scorer/beheerder aan. Er is bewust geen openbare registratie.
 * Gebruik: php artisan honkbal:gebruiker
 */
Artisan::command('honkbal:gebruiker', function () {
    $name = $this->ask('Naam');
    $email = $this->ask('E-mailadres');
    $password = $this->secret('Wachtwoord (minimaal 10 tekens)');

    $validator = Validator::make(compact('name', 'email', 'password'), [
        'name' => ['required', 'string', 'max:100'],
        'email' => ['required', 'email', 'unique:users,email'],
        'password' => ['required', 'string', 'min:10'],
    ]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    User::create(compact('name', 'email', 'password'));
    $this->info("Gebruiker {$email} aangemaakt. Inloggen via /inloggen.");

    return 0;
})->purpose('Maak een scorer/beheerder aan');
