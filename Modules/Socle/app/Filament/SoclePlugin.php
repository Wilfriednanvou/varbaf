<?php

namespace Modules\Socle\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

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
        // Present sur chaque ecran du panneau, pas seulement le tableau
        // de bord : c'est ce qui fait de l'exercice consulte une notion
        // globale plutot qu'un filtre propre a une page. Voir
        // ContexteExercice pour le motif de la distinction avec
        // l'exercice actif.
        //
        // `FilamentView::registerRenderHook()` directement, et non
        // `$panel->renderHook()` : `Panel::boot()` publie les crochets
        // du panneau vers `FilamentView` *avant* de demarrer les
        // greffons — un crochet pose ici via `$panel->renderHook()`
        // serait donc stocke sans jamais etre lu.
        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_END,
            fn (): string => Blade::render("@livewire('socle::selecteur-exercice')"),
        );
    }
}
