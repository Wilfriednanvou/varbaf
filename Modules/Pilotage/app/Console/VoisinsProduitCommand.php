<?php

namespace Modules\Pilotage\Console;

use Illuminate\Console\Command;
use Modules\Commerce\Models\Produit;
use Modules\Pilotage\Recommandation\CriteresDeVoisinage;
use Modules\Pilotage\Recommandation\ProduitVoisin;
use Modules\Pilotage\Services\ServiceRecommandationProduit;

/**
 * Affiche les voisins d'un produit et leur score, sans passer par le web.
 *
 * **C'est l'outil de calibrage.** Les trois paramètres de la
 * recommandation — nombre de voisins, seuil, majoration du même
 * artisan — n'ont pas de valeur évidente : elle se trouve en regardant
 * ce que le catalogue réel restitue et en jugeant si c'est pertinent.
 * Passer par le portail pour cela obligerait à publier des produits, à
 * ouvrir un navigateur, et à changer un fichier de configuration entre
 * deux essais. Ici, une option suffit, et les valeurs retenues se
 * reportent telles quelles dans le dossier.
 *
 * Elle sert aussi à la démonstration : montrer les scores derrière une
 * suggestion vaut mieux que montrer la suggestion seule.
 */
class VoisinsProduitCommand extends Command
{
    protected $signature = 'varbaf:voisins
        {reference : Référence du produit, par exemple BTQ04-0001}
        {--voisins= : Nombre de voisins restitués (défaut : configuration)}
        {--seuil= : Similarité minimale, entre 0 et 1 (défaut : configuration)}
        {--bonus= : Majoration appliquée au même artisan (défaut : configuration)}
        {--stock : Écarter les produits dont le stock est épuisé}';

    protected $description = 'Affiche les produits proches d\'une référence, avec leur score de similarité.';

    public function handle(ServiceRecommandationProduit $recommandation): int
    {
        $produit = Produit::query()
            ->with('artisan')
            ->where('reference', $this->argument('reference'))
            ->first();

        if (! $produit) {
            $this->components->error("Aucun produit ne porte la référence « {$this->argument('reference')} ».");

            return self::FAILURE;
        }

        $moteur = $recommandation->nomDuMoteur();

        if ($moteur === null) {
            $this->components->error(
                'Aucun moteur disponible : l\'index est vide. Lancez « php artisan varbaf:indexer ».',
            );

            return self::FAILURE;
        }

        $criteres = $this->criteres();

        $this->components->info("Produit : {$produit->reference} — {$produit->designation}");
        $this->components->twoColumnDetail('Artisan', $produit->artisan?->nom_complet ?? '—');
        $this->components->twoColumnDetail('Moteur', $moteur);

        $this->newLine();
        $this->table(
            ['Paramètre', 'Valeur'],
            collect($criteres->enTableau())
                ->map(fn (mixed $valeur, string $cle): array => [
                    $cle,
                    is_bool($valeur) ? ($valeur ? 'oui' : 'non') : (string) $valeur,
                ])
                ->values()
                ->all(),
        );

        $voisins = $recommandation->voisins($produit, $criteres);

        if ($voisins->isEmpty()) {
            $this->components->warn(
                'Aucun voisin n\'atteint le seuil de '.$criteres->seuil.'. '
                .'Abaissez --seuil pour voir ce que le catalogue propose de plus proche.',
            );

            return self::SUCCESS;
        }

        $modeles = Produit::query()
            ->with('artisan')
            ->whereIn('id', $voisins->pluck('produitId')->all())
            ->get()
            ->keyBy('id');

        $this->newLine();
        $this->table(
            ['Rang', 'Référence', 'Désignation', 'Artisan', 'Similarité', 'Score', 'Même artisan'],
            $voisins->values()->map(function (ProduitVoisin $voisin, int $rang) use ($modeles): array {
                $modele = $modeles->get($voisin->produitId);

                return [
                    $rang + 1,
                    $modele?->reference ?? '—',
                    $modele?->designation ?? '—',
                    $modele?->artisan?->nom_complet ?? '—',
                    number_format($voisin->similarite, 4, ',', ' '),
                    number_format($voisin->score, 4, ',', ' '),
                    $voisin->memeArtisan ? 'oui' : '',
                ];
            })->all(),
        );

        // La similarité franchit le seuil, le score ne fait que classer :
        // le rappeler ici évite d'avoir à relire le code pour comprendre
        // pourquoi une ligne au score élevé peut manquer.
        $this->components->info(
            'Le seuil porte sur la similarité ; le score, majoration comprise, ne sert qu\'au classement.',
        );

        return self::SUCCESS;
    }

    protected function criteres(): CriteresDeVoisinage
    {
        return CriteresDeVoisinage::depuisLaConfiguration(
            limite: $this->optionEntiere('voisins'),
            seuil: $this->optionFlottante('seuil'),
            bonusMemeArtisan: $this->optionFlottante('bonus'),
            exclureStockEpuise: $this->option('stock') ? true : null,
        );
    }

    protected function optionEntiere(string $nom): ?int
    {
        $valeur = $this->option($nom);

        return $valeur === null || $valeur === '' ? null : (int) $valeur;
    }

    protected function optionFlottante(string $nom): ?float
    {
        $valeur = $this->option($nom);

        return $valeur === null || $valeur === '' ? null : (float) $valeur;
    }
}
