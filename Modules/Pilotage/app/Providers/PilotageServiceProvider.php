<?php

namespace Modules\Pilotage\Providers;

use Livewire\Livewire;
use Modules\Pilotage\Console\IndexerCorpusCommand;
use Modules\Pilotage\Console\IndexerVecteursCommand;
use Modules\Pilotage\Console\EvaluerAssistantCommand;
use Modules\Pilotage\Console\VoisinsProduitCommand;
use Modules\Pilotage\Contracts\FournisseurDEmbeddings;
use Modules\Pilotage\Contracts\ModeleDeLangage;
use Modules\Pilotage\Embeddings\ClientOllama;
use Modules\Pilotage\Modele\ClientCompatibleOpenAI;
use Modules\Pilotage\Modele\ResolveurDeModele;
use Modules\Pilotage\Recommandation\MoteurLexical;
use Modules\Pilotage\Recherche\MoteurDense;
use Modules\Pilotage\Recherche\MoteurHybride;
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

        // Le fournisseur d'embeddings, derrière son port.
        //
        // Singleton parce que `ClientOllama` mémorise la disponibilité
        // du service et l'âge de son point d'entrée pour la durée du
        // processus : une instance neuve à chaque résolution
        // resonderait le réseau à chaque question, sur le chemin même
        // où l'utilisateur attend une réponse.
        $this->app->singleton(FournisseurDEmbeddings::class, ClientOllama::class);

        // Le catalogue des modèles de rédaction, indexé par la clé que
        // « pilotage.redaction.ordre » emploie.
        //
        // Les deux profils sont servis par la même classe : ils ne
        // diffèrent que par ce qu'ils lisent dans la configuration. Un
        // troisième fournisseur n'ajouterait ni classe ni ligne ici, mais
        // une entrée dans « pilotage.redaction.profils ».
        $this->app->singleton(ResolveurDeModele::class, fn (): ResolveurDeModele => new ResolveurDeModele([
            'local' => new ClientCompatibleOpenAI('local'),
            'distant' => new ClientCompatibleOpenAI('distant'),
        ]));

        // Le modèle derrière son port.
        //
        // **Toujours lié, jamais absent.** Le résolveur rend
        // `ModeleIndisponible` quand aucun modèle ne se déclare
        // disponible, de sorte qu'aucun appelant n'ait à distinguer
        // « pas de modèle » de « modèle qui n'a pas voulu rédiger ». Une
        // installation sans clé d'API n'a donc rien à configurer pour
        // fonctionner : elle liste les extraits, comme avant.
        $this->app->bind(
            ModeleDeLangage::class,
            fn ($app): ModeleDeLangage => $app->make(ResolveurDeModele::class)->resoudre(),
        );

        // Le catalogue des moteurs, indexé par la clé que
        // « pilotage.moteur.ordre » emploie.
        $this->app->singleton(ResolveurDeMoteur::class, fn ($app): ResolveurDeMoteur => new ResolveurDeMoteur([
            // L'hybride est en tête de l'ordre configuré. Il n'est pas
            // un troisième moteur à côté des deux autres : il les
            // arbitre, et se réduit à la branche lexicale quand le
            // fournisseur d'embeddings ne répond pas — en le disant.
            'hybride' => $app->make(MoteurHybride::class),

            'lexical' => $app->make(MoteurLexical::class),

            // Le dense est enregistré pour que la commande d'évaluation
            // puisse le mesurer seul, et volontairement absent de
            // « pilotage.moteur.ordre » : il n'a pas vocation à
            // répondre sans le lexical pour tempérer sa complaisance.
            'dense' => $app->make(MoteurDense::class),

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
                IndexerVecteursCommand::class,
                VoisinsProduitCommand::class,
                EvaluerAssistantCommand::class,
            ]);
        }
    }
}
