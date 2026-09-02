<?php

namespace Modules\Socle\Providers;

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Services\ContexteExercice;
use Modules\Socle\Services\VerrousDeCloture;
use Nwidart\Modules\Support\ModuleServiceProvider;

class SocleServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Socle';

    protected string $nameLower = 'socle';

    public function register(): void
    {
        parent::register();

        // Le registre est lié ici, dans `register()`, pour que les
        // autres modules le trouvent déjà en place quand leur `boot()`
        // vient y déposer leur verrou : tous les `register()` passent
        // avant tous les `boot()`.
        $this->app->singleton(VerrousDeCloture::class);

        // Singleton par requête : la session sous-jacente persiste
        // d'elle-même entre les requêtes, l'objet n'a besoin d'être
        // résolu qu'une fois par requête pour que tous ses appelants
        // s'accordent sur le même exercice consulté.
        $this->app->singleton(ContexteExercice::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->enregistrerAccesSuperUtilisateur();

        // `nwidart/laravel-modules` enregistre le namespace de vues
        // `socle::`, mais pas celui des composants Livewire — même
        // mécanique que dans le Pilotage et la Trésorerie. Sans cette
        // ligne, `<livewire:socle::selecteur-exercice />` ne trouve
        // aucune classe.
        Livewire::addNamespace('socle', classNamespace: 'Modules\\Socle\\Filament\\Widgets');
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
