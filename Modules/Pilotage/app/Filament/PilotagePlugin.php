<?php

namespace Modules\Pilotage\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Branche la page de tableau de bord du Pilotage sur le panneau
 * d'administration, selon le même patron que les autres modules.
 *
 * Les widgets ne sont pas déclarés au panneau : ils sont montés par la
 * page, qui leur transmet les filtres. Un widget posé directement sur
 * le tableau de bord par défaut n'aurait aucun moyen de connaître
 * l'exercice ni l'intervalle choisis.
 */
class PilotagePlugin implements Plugin
{
    public function getId(): string
    {
        return 'pilotage';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function register(Panel $panel): void
    {
        $panel->discoverPages(
            in: module_path('Pilotage', 'app/Filament/Pages'),
            for: 'Modules\\Pilotage\\Filament\\Pages',
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
