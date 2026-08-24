<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        // Contournement d'un bug de symfony/html-sanitizer (utilisé par Filament)
        // sur PHP 8.3 qui tente d'appeler Dom\HTMLDocument (introduit en PHP 8.4).
        Str::macro('sanitizeHtml', function (string $html): string {
            return strip_tags($html, ['b', 'i', 'u', 's', 'strong', 'em', 'a', 'br', 'ul', 'ol', 'li', 'p', 'span']);
        });
    }
}
