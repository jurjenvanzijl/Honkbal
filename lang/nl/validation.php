<?php

// Nederlandse validatiemeldingen voor de regels die deze app gebruikt.
// Niet-vertaalde regels vallen terug op het Engels (APP_FALLBACK_LOCALE).

return [
    'array' => ':Attribute moet een lijst zijn.',
    'between' => [
        'numeric' => ':Attribute moet tussen :min en :max liggen.',
    ],
    'date' => ':Attribute is geen geldige datum.',
    'different' => ':Attribute en :other moeten verschillend zijn.',
    'email' => ':Attribute moet een geldig e-mailadres zijn.',
    'exists' => 'De gekozen :attribute bestaat niet.',
    'in' => 'De gekozen :attribute is ongeldig.',
    'integer' => ':Attribute moet een geheel getal zijn.',
    'max' => [
        'array' => ':Attribute mag niet meer dan :max items bevatten.',
        'numeric' => ':Attribute mag niet groter zijn dan :max.',
        'string' => ':Attribute mag niet meer dan :max tekens bevatten.',
    ],
    'min' => [
        'numeric' => ':Attribute moet minimaal :min zijn.',
        'string' => ':Attribute moet minimaal :min tekens bevatten.',
    ],
    'regex' => 'Het formaat van :attribute is ongeldig.',
    'required' => ':Attribute is verplicht.',
    'string' => ':Attribute moet tekst zijn.',
    'unique' => ':Attribute is al in gebruik.',
    'url' => ':Attribute moet een geldige (https-)link zijn.',

    'attributes' => [
        'email' => 'e-mailadres',
        'password' => 'wachtwoord',
        'name' => 'naam',
    ],
];
