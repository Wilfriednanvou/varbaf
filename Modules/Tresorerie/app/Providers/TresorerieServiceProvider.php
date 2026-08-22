<?php

namespace Modules\Tresorerie\Providers;

use Livewire\Livewire;
use Modules\Commerce\Contracts\JournalDeCaisse;
use Modules\Tresorerie\Services\ServiceTresorerie;
use Nwidart\Modules\Support\ModuleServiceProvider;

class TresorerieServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Tresorerie';

    protected string $nameLower = 'tresorerie';

    public function register(): void
    {
        parent::register();

        // Liaison définitive du port vers le brouillard de caisse.
        //
        // Le module Trésorerie a une priorité de 70, le Commerce de 80 :
        // ce fournisseur de services est donc enregistré **avant** celui
        // du Commerce. Le `singletonIf` du Commerce ne surcharge pas
        // une liaison existante — le brouillard réel gagne.
        $this->app->singleton(JournalDeCaisse::class, ServiceTresorerie::class);
    }

    public function boot(): void
    {
        parent::boot();

        // `nwidart/laravel-modules` enregistre la vue `tresorerie::...`
        // (namespace Blade), mais pas le namespace de composant Livewire
        // du même nom : sans cet enregistrement, `<livewire:tresorerie::...>`
        // ne trouve aucune classe. C'est l'écran de session de caisse qui
        // en dépend.
        Livewire::addNamespace('tresorerie', classNamespace: 'Modules\\Tresorerie\\Livewire');
    }
}
