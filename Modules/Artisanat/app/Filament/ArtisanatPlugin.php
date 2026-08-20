<?php

namespace Modules\Artisanat\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Branche les ressources du module Artisanat sur le panneau
 * d'administration, selon le même patron que le Socle.
 */
class ArtisanatPlugin implements Plugin
{
    public function getId(): string
    {
        return 'artisanat';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: module_path('Artisanat', 'app/Filament/Resources'),
            for: 'Modules\\Artisanat\\Filament\\Resources',
        );

        $panel->discoverPages(
            in: module_path('Artisanat', 'app/Filament/Pages'),
            for: 'Modules\\Artisanat\\Filament\\Pages',
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
