<?php

namespace Modules\Commerce\Providers;

use Illuminate\Support\Facades\Event;
use Modules\Commerce\Console\BootstrapProduitExercicesCommand;
use Modules\Commerce\Contracts\JournalDeCaisse;
use Modules\Commerce\Events\SeuilAlerteFranchi;
use Modules\Commerce\Listeners\NotifierSeuilAlerte;
use Modules\Commerce\Services\JournalDeCaisseEnAttente;
use Modules\Commerce\Services\ReconducteurProduits;
use Modules\Socle\Services\RegistreDeReconduction;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CommerceServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Commerce';

    protected string $nameLower = 'commerce';

    public function register(): void
    {
        parent::register();

        // Fusion explicite, comme dans le Pilotage : `config('commerce.*')`
        // répond alors quelle que soit la version de
        // `nwidart/laravel-modules`. Les destinataires de l'alerte de
        // rupture s'y lisent, et un `config()` qui rendrait null
        // laisserait une alerte sans destinataire, en silence.
        $this->mergeConfigFrom(module_path($this->name, 'config/config.php'), $this->nameLower);

        // Liaison provisoire du port vers le brouillard de caisse. Le
        // module Trésorerie remplacera cette implémentation par la
        // sienne ; ni ServiceVente ni les écrans n'auront à changer.
        //
        // `singletonIf` et non `singleton` : si la Trésorerie a déjà
        // enregistré la sienne — son fournisseur de services a une
        // priorité plus basse et passe donc après —, on ne l'écrase
        // pas. Singleton, et non simple liaison : c'est ce qui permet
        // à un test de résoudre la même instance que le service de
        // vente et d'y observer les encaissements déposés.
        $this->app->singletonIf(JournalDeCaisse::class, JournalDeCaisseEnAttente::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Règle 15. L'événement était émis depuis la première tranche du
        // module ; c'est l'écoute qui manquait. Le branchement est
        // déclaré ici plutôt que découvert automatiquement : un module
        // doit dire ce qu'il écoute, et cette ligne est la seule à lire
        // pour savoir que l'alerte de rupture part effectivement.
        Event::listen(SeuilAlerteFranchi::class, NotifierSeuilAlerte::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                BootstrapProduitExercicesCommand::class,
            ]);
        }

        // Meme raison que dans l'Artisanat : depose apres que le Socle
        // a lie le registre.
        $this->app->make(RegistreDeReconduction::class)
            ->ajouter('produits', $this->app->make(ReconducteurProduits::class));
    }
}
