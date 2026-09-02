<?php

namespace Modules\Commerce\Services;

use Illuminate\Support\Collection;
use Modules\Commerce\Enums\StatutParticipationProduit;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Models\ProduitExercice;
use Modules\Socle\Contracts\ReconducteurExercice;
use Modules\Socle\Models\Exercice;

/**
 * Ce que le Commerce reconduit d'un exercice à l'autre — même principe
 * que `ReconducteurArtisans`, voir son commentaire.
 */
class ReconducteurProduits implements ReconducteurExercice
{
    public function libelle(): string
    {
        return 'Produits';
    }

    public function elementsAReconduire(Exercice $exercice): Collection
    {
        return ProduitExercice::query()
            ->with('produit')
            ->where('exercice_id', $exercice->getKey())
            ->whereIn('statut', [
                StatutParticipationProduit::ACTIF->value,
                StatutParticipationProduit::RECONDUIT->value,
            ])
            ->get()
            ->filter(fn (ProduitExercice $participation) => $participation->produit !== null)
            ->map(fn (ProduitExercice $participation): array => [
                'id' => $participation->produit->getKey(),
                'libelle' => $participation->produit->identite,
                'statut_actuel' => $participation->statut->getLabel(),
            ])
            ->values();
    }

    public function reconduire(Exercice $ancien, Exercice $nouveau, array $idsSelectionnes): void
    {
        foreach (Produit::query()->whereIn('id', $idsSelectionnes)->get() as $produit) {
            ProduitExercice::query()->firstOrCreate(
                [
                    'produit_id' => $produit->getKey(),
                    'exercice_id' => $nouveau->getKey(),
                ],
                [
                    'statut' => StatutParticipationProduit::RECONDUIT,
                ],
            );
        }
    }
}
