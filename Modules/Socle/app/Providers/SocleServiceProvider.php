<?php

namespace Modules\Socle\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Socle\Models\Utilisateur;
use Nwidart\Modules\Support\ModuleServiceProvider;

class SocleServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Socle';

    protected string $nameLower = 'socle';

    public function boot(): void
    {
        parent::boot();

        $this->enregistrerAccesSuperUtilisateur();
    }

    /**
     * Accorde toutes les permissions au super-utilisateur.
     *
     * Le crochet vit ici et nulle part ailleurs : les ressources des
     * modules n'ont donc jamais à traiter ce cas particulier, elles se
     * contentent de vérifier la permission nominale. Retourner null —
     * et non false — laisse la main aux vérifications suivantes pour
     * les autres profils.
     */
    protected function enregistrerAccesSuperUtilisateur(): void
    {
        Gate::before(function ($utilisateur, string $capacite): ?bool {
            if ($utilisateur instanceof Utilisateur && $utilisateur->estSuperUtilisateur()) {
                return true;
            }

            return null;
        });
    }
}
