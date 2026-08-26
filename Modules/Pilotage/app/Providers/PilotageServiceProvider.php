<?php

namespace Modules\Pilotage\Providers;

use Livewire\Livewire;
use Modules\Pilotage\Console\IndexerCorpusCommand;
use Modules\Pilotage\Console\EvaluerAssistantCommand;
use Modules\Pilotage\Console\VoisinsProduitCommand;
use Modules\Pilotage\Recommandation\MoteurLexical;
use Modules\Pilotage\Recherche\MoteurMotsCles;
use Modules\Pilotage\Recommandation\ResolveurDeMoteur;
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

    public function register(): void
    {
        parent::register();

        // La fusion est explicite plutôt que laissée au parent : elle
        // garantit que `config('pilotage.*')` répond quelle que soit la
        // version de `nwidart/laravel-modules`, et les seuils du volet
        // analytique sont lus à chaque calcul — un `config()` qui rend
        // null y deviendrait un zéro silencieux.
        $this->mergeConfigFrom(module_path($this->name, 'config/config.php'), $this->nameLower);

        // Le catalogue des moteurs sémantiques, indexé par la clé que
        // « pilotage.moteur.ordre » emploie. Une branche dense s'ajoutera
        // ici sous la clé « dense » : c'est la seule ligne à écrire pour
        // qu'elle passe devant, et le repli sur la branche lexicale
        // fonctionne alors sans qu'aucun appelant ne change.
        $this->app->singleton(ResolveurDeMoteur::class, fn ($app): ResolveurDeMoteur => new ResolveurDeMoteur([
            'lexical' => $app->make(MoteurLexical::class),

            // Le témoin par mots-clés est enregistré mais absent de
            // « pilotage.moteur.ordre » : la commande d'évaluation
            // peut l'atteindre par son nom, la résolution normale ne
            // tombera jamais dessus. Ce n'est pas un moteur de repli,
            // c'est un instrument de mesure.
            'mots_cles' => $app->make(MoteurMotsCles::class),
        ]));
    }

    public function boot(): void
    {
        parent::boot();

        // `nwidart/laravel-modules` enregistre le namespace de vues
        // `pilotage::`, mais pas celui des composants Livewire. Sans
        // cette ligne, `<livewire:pilotage::indicateurs-cles />` ne
        // trouve aucune classe — même mécanique que dans la Trésorerie.
        Livewire::addNamespace('pilotage', classNamespace: 'Modules\\Pilotage\\Filament\\Widgets');

        // Les commandes d'un module ne sont pas découvertes
        // automatiquement : seul `app/Console/Commands` du socle
        // applicatif l'est. Sans cet enregistrement, `varbaf:indexer`
        // n'apparaît pas dans `php artisan list`.
        if ($this->app->runningInConsole()) {
            $this->commands([
                IndexerCorpusCommand::class,
                VoisinsProduitCommand::class,
                EvaluerAssistantCommand::class,
            ]);
        }
    }
}
