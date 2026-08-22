<?php

namespace Modules\Tresorerie\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Branche les ressources du module Trésorerie sur le panneau
 * d'administration, selon le même patron que le Commerce.
 */
class TresoreriePlugin implements Plugin
{
    public function getId(): string
    {
        return 'tresorerie';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: module_path('Tresorerie', 'app/Filament/Resources'),
            for: 'Modules\\Tresorerie\\Filament\\Resources',
        );

        $panel->discoverPages(
            in: module_path('Tresorerie', 'app/Filament/Pages'),
            for: 'Modules\\Tresorerie\\Filament\\Pages',
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
