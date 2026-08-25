<?php

namespace Modules\Portail\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Écrans d'administration du portail.
 *
 * Le site public est hors du panneau — CLAUDE.md l'exige — mais ce qui
 * le pilote y reste : décider ce qui est publié, rédiger les textes,
 * suivre les demandes reçues. La frontière est celle du visiteur, pas
 * celle des données.
 */
class PortailPlugin implements Plugin
{
    public function getId(): string
    {
        return 'portail';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: module_path('Portail', 'app/Filament/Resources'),
            for: 'Modules\\Portail\\Filament\\Resources',
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
