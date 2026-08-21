<?php

namespace Modules\Commerce\Providers;

use Modules\Commerce\Contracts\JournalDeCaisse;
use Modules\Commerce\Services\JournalDeCaisseEnAttente;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CommerceServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Commerce';

    protected string $nameLower = 'commerce';

    public function register(): void
    {
        parent::register();

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
}
