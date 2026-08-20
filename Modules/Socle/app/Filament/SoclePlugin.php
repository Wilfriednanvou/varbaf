<?php

namespace Modules\Socle\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Branche les ressources du module Socle sur le panneau d'administration.
 *
 * Le panneau ne découvre que app/Filament ; chaque module apporte ses
 * propres écrans par un greffon, ce qui garde la découverte confinée au
 * module et rend l'ajout d'un module réversible en une ligne dans
 * AdminPanelProvider.
 */
class SoclePlugin implements Plugin
{
    public function getId(): string
    {
        return 'socle';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: module_path('Socle', 'app/Filament/Resources'),
            for: 'Modules\\Socle\\Filament\\Resources',
        );

        $panel->discoverPages(
            in: module_path('Socle', 'app/Filament/Pages'),
            for: 'Modules\\Socle\\Filament\\Pages',
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
