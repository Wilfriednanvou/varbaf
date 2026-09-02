<?php

namespace Modules\Portail\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Modules\Portail\Services\ServicePortail;
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

    /**
     * La configuration du module est fusionnee des l'enregistrement.
     *
     * `config('portail.visuels.*')` doit repondre avant tout rendu de vue,
     * y compris sous `config:cache` : un `config()` qui rend null ne leve
     * rien, il affiche une page sans ses images.
     */
    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(module_path($this->name, 'config/config.php'), $this->nameLower);
    }

    public function boot(): void
    {
        parent::boot();

        $this->chargerLesRoutesPubliques();
        $this->partagerLeVillageAvecLeGabarit();
    }

    /**
     * Les coordonnées du village, attachées au seul gabarit.
     *
     * Un compositeur plutôt qu'un passage par chaque contrôleur : le
     * pied de page appartient au gabarit, pas aux pages, et huit
     * contrôleurs qui passeraient la même variable finiraient par en
     * oublier un — la page concernée perdrait son pied de page sans que
     * rien n'échoue.
     *
     * Il est posé sur `portail::*` et non sur le seul gabarit. Blade
     * rend la vue enfant **avant** la mise en page : une variable
     * attachée au seul gabarit existe dans son pied de page et manque
     * dans le corps de la page de contact, qui affiche les mêmes
     * coordonnées. Le motif est le même que celui du thème absent — la
     * page ne tombe pas, elle affiche moins.
     *
     * Le coût reste d'une requête : `village()` mémorise son résultat
     * pour la durée de la requête, et les partiels rendus en boucle
     * relisent la valeur déjà en main.
     */
    protected function partagerLeVillageAvecLeGabarit(): void
    {
        View::composer('portail::*', function ($vue): void {
            $vue->with('village', app(ServicePortail::class)->village());
        });
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
