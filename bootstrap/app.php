<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Les commandes de l'application sont déclarées explicitement.
    // `nwidart/laravel-modules` enregistre celles des modules par leurs
    // fournisseurs de services ; celles qui vivent à la racine — la
    // reprise du registre traverse l'Artisanat et le Commerce, elle
    // n'appartient donc à aucun des deux — ont besoin de cette ligne.
    ->withCommands([__DIR__.'/../app/Console/Commands'])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
