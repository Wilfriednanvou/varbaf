<?php

namespace Modules\Pilotage\Console;

use Modules\Pilotage\Contracts\FournisseurDEmbeddings;
use Modules\Pilotage\Models\FicheLexicale;
use Modules\Pilotage\Services\ServiceIndexationDense;
use Illuminate\Console\Command;

/**
 * Calcule les vecteurs denses du corpus déjà indexé.
 *
 * **Commande distincte de `varbaf:indexer`, et non une option.** Les
 * deux indexations n'ont ni le même coût ni les mêmes conditions :
 * l'une est locale et prend quelques secondes, l'autre appelle un
 * modèle et peut prendre plusieurs minutes ; l'une réussit toujours,
 * l'autre suppose qu'un service tourne. Les fondre en une seule
 * commande ferait échouer — ou traîner — la reconstruction de l'index
 * lexical à cause d'une dépendance dont elle n'a jamais eu besoin.
 *
 * L'ordre est imposé : `varbaf:indexer` compose les fiches,
 * `varbaf:indexer-vecteurs` les vectorise. La seconde le vérifie plutôt
 * que de le supposer.
 */
class IndexerVecteursCommand extends Command
{
    protected $signature = 'varbaf:indexer-vecteurs
        {--force : Recalculer même les fiches dont le texte et le modèle n\'ont pas changé}';

    protected $description = 'Calcule les vecteurs denses des fiches du corpus (branche dense de la recherche hybride).';

    public function handle(ServiceIndexationDense $indexation, FournisseurDEmbeddings $fournisseur): int
    {
        if (FicheLexicale::query()->comparable()->doesntExist()) {
            $this->components->error(
                'Le corpus n\'est pas composé : lancez d\'abord « php artisan varbaf:indexer ».',
            );

            return self::FAILURE;
        }

        if (! $fournisseur->estDisponible()) {
            $this->components->error("Fournisseur d'embeddings indisponible : {$fournisseur->nom()}.");
            $this->components->bulletList([
                'Le service est-il lancé ? (« ollama serve »)',
                "Le modèle est-il téléchargé ? (« ollama pull {$fournisseur->modele()} »)",
                'L\'adresse configurée est-elle la bonne ? ('.config('pilotage.dense.ollama.url').')',
            ]);

            $this->components->warn(
                'La recherche continue de fonctionner sur la seule branche lexicale : '
                .'ce n\'est pas une panne du système, c\'est une branche en moins.',
            );

            return self::FAILURE;
        }

        $this->components->info("Vectorisation du corpus — {$fournisseur->nom()}.");

        if ($this->option('force')) {
            $this->components->warn('Mode --force : tout le corpus est revectorisé.');
        }

        $barre = null;

        $rapport = $indexation->reindexer(
            (bool) $this->option('force'),
            function (int $faites, int $total) use (&$barre): void {
                $barre ??= $this->output->createProgressBar($total);
                $barre->setProgress(min($faites, $total));
            },
        );

        $barre?->finish();
        $this->newLine(2);

        $this->table(
            ['Indicateur', 'Valeur'],
            array_map(
                static fn (string $cle, string|int $valeur): array => [$cle, (string) $valeur],
                array_keys($rapport->indicateurs()),
                array_values($rapport->indicateurs()),
            ),
        );

        if ($rapport->echouees > 0) {
            // Un vecteur manquant ne casse rien et ne se voit nulle
            // part : la fiche reste trouvable par le lexical. C'est
            // exactement pour cela qu'il faut le dire ici.
            $this->components->warn(
                "{$rapport->echouees} fiche(s) sans vecteur : elles restent trouvables par la branche "
                .'lexicale, mais la branche dense les ignore. Relancez pour les rattraper.',
            );
        }

        $this->components->info("Couverture dense : {$rapport->partCouverte()} % du corpus.");

        return self::SUCCESS;
    }
}
