<?php

namespace Modules\Pilotage\Providers;

use Livewire\Livewire;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Module Pilotage — priorité 90, la plus haute du projet.
 *
 * Le Pilotage lit les quatre modules qui le précèdent et n'est lu par
 * aucun : il s'enregistre donc en dernier. C'est le seul module dont la
 * dépendance descendante porte sur tout le reste, ce qui est
 * précisément pourquoi `RapportService` existe — sans lui, chaque
 * module aurait fini par exposer ses propres statistiques et créer les
 * dépendances montantes que `docs/modele-classes.md` interdit.
 */
class PilotageServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Pilotage';

    protected string $nameLower = 'pilotage';

    public function boot(): void
    {
        parent::boot();

        // `nwidart/laravel-modules` enregistre le namespace de vues
        // `pilotage::`, mais pas celui des composants Livewire. Sans
        // cette ligne, `<livewire:pilotage::indicateurs-cles />` ne
        // trouve aucune classe — même mécanique que dans la Trésorerie.
        Livewire::addNamespace('pilotage', classNamespace: 'Modules\\Pilotage\\Filament\\Widgets');
    }
}
