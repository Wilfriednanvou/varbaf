<?php

namespace Modules\Artisanat\Services;

use Illuminate\Support\Collection;
use Modules\Artisanat\Enums\StatutParticipationArtisan;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\ArtisanExercice;
use Modules\Socle\Contracts\ReconducteurExercice;
use Modules\Socle\Models\Exercice;

/**
 * Ce que l'Artisanat reconduit d'un exercice à l'autre — voir
 * `ReconducteurExercice` pour le motif du contrat.
 */
class ReconducteurArtisans implements ReconducteurExercice
{
    public function libelle(): string
    {
        return 'Artisans';
    }

    public function elementsAReconduire(Exercice $exercice): Collection
    {
        return ArtisanExercice::query()
            ->with('artisan')
            ->where('exercice_id', $exercice->getKey())
            ->whereIn('statut', [
                StatutParticipationArtisan::ACTIF->value,
                StatutParticipationArtisan::RECONDUIT->value,
            ])
            ->get()
            ->filter(fn (ArtisanExercice $participation) => $participation->artisan !== null)
            ->map(fn (ArtisanExercice $participation): array => [
                'id' => $participation->artisan->getKey(),
                'libelle' => $participation->artisan->identite,
                'statut_actuel' => $participation->statut->getLabel(),
            ])
            ->values();
    }

    public function reconduire(Exercice $ancien, Exercice $nouveau, array $idsSelectionnes): void
    {
        foreach (Artisan::query()->whereIn('id', $idsSelectionnes)->get() as $artisan) {
            ArtisanExercice::query()->firstOrCreate(
                [
                    'artisan_id' => $artisan->getKey(),
                    'exercice_id' => $nouveau->getKey(),
                ],
                [
                    'statut' => StatutParticipationArtisan::RECONDUIT,
                    'date_activation' => $nouveau->date_debut,
                ],
            );
        }
    }
}
