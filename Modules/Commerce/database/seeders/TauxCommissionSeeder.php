<?php

namespace Modules\Commerce\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Commerce\Models\TauxCommission;
use Modules\Socle\Models\VillageArtisanal;

/**
 * Taux de commission initial.
 *
 * Ce seeder existe parce que `getTauxEnVigueur()` lève une exception
 * quand aucun taux n'est en vigueur : sans lui, la base fraîchement
 * semée refuserait toute vente. Poser un taux n'est donc pas un
 * confort, c'est ce qui rend l'application démarrable.
 *
 * **Le taux posé ici est provisoire.** Il vaut 10 %, dernier des trois
 * taux cités en exemple lors du cadrage (15 %, puis 5 %, puis 10 %). Ce
 * n'est pas une donnée relevée sur pièce, et CLAUDE.md interdit les
 * données inventées : la référence de décision le dit explicitement, et
 * la console le rappelle à chaque passage. Le taux réel et sa date
 * d'effet doivent être repris de l'acte de la coordination.
 *
 * Corriger revient à enregistrer un nouveau taux daté depuis l'écran —
 * jamais à retoucher celui-ci une fois entré en vigueur.
 */
class TauxCommissionSeeder extends Seeder
{
    protected const TAUX_PROVISOIRE = 10.00;

    protected const REFERENCE_PROVISOIRE = 'TAUX PROVISOIRE — à remplacer par la référence de l\'acte en vigueur';

    public function run(): void
    {
        $village = VillageArtisanal::where('code', 'VARBAF')->first();

        if (! $village) {
            $this->command?->warn('Village VARBAF introuvable : le seeder du Socle doit passer avant celui du Commerce.');

            return;
        }

        // La date d'effet est celle de l'ouverture de l'exercice en
        // cours, à défaut le premier jour de l'année : un taux dont la
        // date d'effet serait postérieure à des ventes déjà saisies
        // rendrait ces ventes incommissionnables.
        $dateEffet = $village->exerciceEnCours()?->date_debut?->toDateString()
            ?? now()->startOfYear()->toDateString();

        $taux = TauxCommission::firstOrCreate(
            ['village_id' => $village->id, 'date_effet' => $dateEffet],
            [
                'taux' => self::TAUX_PROVISOIRE,
                'reference_decision' => self::REFERENCE_PROVISOIRE,
            ],
        );

        $this->command?->info("Taux de commission en vigueur : {$taux->libelle()}.");
        $this->command?->warn('Ce taux est PROVISOIRE. Saisissez le taux réel et sa date d\'effet depuis l\'écran « Taux de commission ».');
    }
}
