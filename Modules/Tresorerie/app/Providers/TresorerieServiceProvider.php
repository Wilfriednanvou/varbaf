<?php

namespace Modules\Tresorerie\Providers;

use Livewire\Livewire;
use Modules\Commerce\Contracts\JournalDeCaisse;
use Modules\Socle\Services\VerrousDeCloture;
use Modules\Tresorerie\Services\ServiceTresorerie;
use Modules\Tresorerie\Services\VerrouTresorerie;
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

        // La Trésorerie vient déclarer ce qui, chez elle, s'oppose à la
        // clôture d'un exercice (DT-01). Le dépôt a lieu dans `boot()`
        // et non dans `register()` : le registre est lié par le Socle
        // dans son propre `register()`, et tous les `register()` passent
        // avant tous les `boot()`. C'est ce qui garantit qu'on dépose
        // dans l'instance que le modèle interrogera.
        $this->app->make(VerrousDeCloture::class)->ajouter(new VerrouTresorerie);

        // `nwidart/laravel-modules` enregistre la vue `tresorerie::...`
        // (namespace Blade), mais pas le namespace de composant Livewire
        // du même nom : sans cet enregistrement, `<livewire:tresorerie::...>`
        // ne trouve aucune classe. C'est l'écran de session de caisse qui
        // en dépend.
        Livewire::addNamespace('tresorerie', classNamespace: 'Modules\\Tresorerie\\Livewire');
    }
}
