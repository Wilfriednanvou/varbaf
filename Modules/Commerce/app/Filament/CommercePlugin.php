<?php

namespace Modules\Commerce\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Branche les ressources du module Commerce sur le panneau
 * d'administration, selon le même patron que le Socle et l'Artisanat.
 */
class CommercePlugin implements Plugin
{
    public function getId(): string
    {
        return 'commerce';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: module_path('Commerce', 'app/Filament/Resources'),
            for: 'Modules\\Commerce\\Filament\\Resources',
        );

        $panel->discoverPages(
            in: module_path('Commerce', 'app/Filament/Pages'),
            for: 'Modules\\Commerce\\Filament\\Pages',
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
