<?php

namespace Modules\Commerce\Console;

use Illuminate\Console\Command;
use Modules\Commerce\Enums\StatutParticipationProduit;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Models\ProduitExercice;
use Modules\Socle\Models\Exercice;

/**
 * Peuple `produit_exercices` depuis `Produit.actif`, meme principe et
 * meme motif que `varbaf:bootstrap-artisan-exercices` — voir son
 * commentaire.
 */
class BootstrapProduitExercicesCommand extends Command
{
    protected $signature = 'varbaf:bootstrap-produit-exercices
        {--exercice= : Libelle de l\'exercice cible (defaut : l\'exercice en cours)}';

    protected $description = "Cree la participation de chaque produit sur un exercice qui n'en a encore aucune.";

    public function handle(): int
    {
        $libelle = $this->option('exercice');

        $exercice = $libelle
            ? Exercice::where('libelle', $libelle)->first()
            : Exercice::courant();

        if (! $exercice) {
            $this->components->error($libelle
                ? "Aucun exercice ne porte le libelle « {$libelle} »."
                : 'Aucun exercice en cours.');

            return self::FAILURE;
        }

        $crees = 0;

        Produit::query()
            ->whereDoesntHave('participationsExercices', fn ($requete) => $requete->where('exercice_id', $exercice->id))
            ->each(function (Produit $produit) use ($exercice, &$crees): void {
                ProduitExercice::create([
                    'produit_id' => $produit->id,
                    'exercice_id' => $exercice->id,
                    'statut' => $produit->actif
                        ? StatutParticipationProduit::ACTIF
                        : StatutParticipationProduit::DESACTIVE,
                ]);

                $crees++;
            });

        $this->components->info("{$crees} participation(s) creee(s) pour l'exercice {$exercice->libelle}.");

        return self::SUCCESS;
    }
}
