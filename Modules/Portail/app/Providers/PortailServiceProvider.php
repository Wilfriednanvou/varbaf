<?php

namespace Modules\Portail\Providers;

use Illuminate\Support\Facades\Route;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Module Portail — priorité 100, le dernier de la chaîne.
 *
 * Couche de diffusion posée au-dessus des mêmes produits et des mêmes
 * artisans, sans duplication de données
 * (`docs/modele-classes.md`, module 6). Le Portail lit l'Artisanat et
 * le Commerce ; aucun d'eux ne le connaît.
 *
 * **Le portail n'est pas dans le panneau.** CLAUDE.md l'énonce :
 * « Ne pas coder le portail public dans le panneau Filament : c'est une
 * interface publique distincte. » Ses routes sont donc chargées ici, sur
 * le groupe `web` et sans aucun middleware d'authentification, tandis
 * que les écrans qui pilotent la publication restent, eux, dans le
 * panneau.
 */
class PortailServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Portail';

    protected string $nameLower = 'portail';

    public function boot(): void
    {
        parent::boot();

        $this->chargerLesRoutesPubliques();
    }

    /**
     * Routes publiques : groupe `web` pour la session et la protection
     * CSRF du formulaire de contact, jamais `auth`. Un visiteur du
     * portail n'a pas de compte et n'en aura pas — le portail est en
     * consultation seule.
     */
    protected function chargerLesRoutesPubliques(): void
    {
        $fichier = module_path('Portail', 'routes/web.php');

        if (! is_file($fichier)) {
            return;
        }

        Route::middleware('web')
            ->name('portail.')
            ->group($fichier);
    }
}
