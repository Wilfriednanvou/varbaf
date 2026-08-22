<?php

namespace Modules\Tresorerie\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\SectionCaisse;

/**
 * Ouvre la première section de caisse pour l'exercice en cours.
 *
 * Le super-utilisateur est utilisé comme ouvreur de section : en
 * environnement de développement, il est le seul compte existant
 * au moment du seeding.
 *
 * `firstOrCreate` et non `updateOrCreate` : une fois la section créée,
 * ce seeder n'a plus le droit d'y toucher. Une section clôturée l'est
 * irréversiblement (RG-07) — si le seeder la retrouve dans cet état
 * lors d'un `db:seed` ultérieur (relance en cours d'exploitation,
 * ajout d'un nouveau module qui reseed), la forcer à `OUVERTE`
 * violerait la règle et lèverait de toute façon l'exception du modèle
 * qui protège une section clôturée contre toute écriture.
 */
class SectionCaisseSeeder extends Seeder
{
    public function run(): void
    {
        $caisse = Caisse::query()->where('code', 'CAISSE-PRINCIPALE')->firstOrFail();
        $village = VillageArtisanal::query()->firstOrFail();
        $exercice = Exercice::query()->where('en_cours', true)->firstOrFail();
        $superUtilisateur = Utilisateur::query()->firstOrFail();

        $section = SectionCaisse::query()
            ->firstOrCreate(
                ['caisse_id' => $caisse->id, 'exercice_id' => $exercice->id],
                [
                    'libelle' => "Section {$exercice->libelle}",
                    'date_ouverture' => now(),
                    'solde_ouverture' => 0,
                    'etat' => 'OUVERTE',
                    'ouverte_par' => $superUtilisateur->id,
                    'village_id' => $village->id,
                ],
            );

        $this->command->info(
            $section->wasRecentlyCreated
                ? "Section de caisse ouverte pour l'exercice {$exercice->libelle}."
                : "Section de caisse déjà existante pour l'exercice {$exercice->libelle} ({$section->etat->getLabel()}) — laissée telle quelle."
        );
    }
}
