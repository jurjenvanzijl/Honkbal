<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Nederlandse datums (bv. "za 21 nov") in translatedFormat().
        Carbon::setLocale(config('app.locale'));

        // Achter de proxy van een hostingpartij komt https soms als http binnen. Forceer https
        // als APP_URL https is, anders laden CSS/JS niet (mixed content) en werkt offline niet.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
