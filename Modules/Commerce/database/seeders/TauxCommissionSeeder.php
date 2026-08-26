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
 * **Le taux de 10 % est confirmé par la coordination** (26/08/2026). Il
 * n'est plus provisoire : c'est le taux prélevé par le village sur les
 * ventes des artisans. Seule la référence de l'acte qui le fixe reste à
 * renseigner, et elle ne change ni la valeur ni la date d'effet.
 *
 * Le mécanisme d'historisation reste entier : `TauxCommission` porte une
 * date d'effet parce que RG-11 fait dépendre le taux d'une vente de sa
 * propre date. Qu'un seul taux soit en vigueur aujourd'hui ne dispense
 * pas d'en poser un daté — c'est ce qui permettra d'en enregistrer un
 * second sans retoucher le premier.
 *
 * Corriger revient à enregistrer un nouveau taux daté depuis l'écran —
 * jamais à retoucher celui-ci une fois entré en vigueur.
 */
class TauxCommissionSeeder extends Seeder
{
    protected const TAUX_EN_VIGUEUR = 10.00;

    protected const REFERENCE_ACTE = 'Taux de 10 % confirmé par la coordination — référence de l\'acte à renseigner';

    /**
     * Date d'effet : l'ouverture de l'exercice pendant lequel s'ouvre le
     * registre transcrit.
     *
     * **Pourquoi remonter aussi loin.** Le taux appliqué à une vente est
     * celui en vigueur **à sa date** (règle 10), et `getTauxEnVigueur()`
     * lève une exception quand aucun acte ne couvre cette date — c'est
     * son intérêt, une vente qu'on ne sait pas commissionner ne
     * s'enregistre pas. Or le registre du village ouvre le 5 juillet
     * 2023. Un taux provisoire prenant effet à l'ouverture de l'exercice
     * courant laisserait donc mille lignes incommissionnables et
     * rendrait la reprise impossible : il ne remplirait pas l'office
     * pour lequel il existe.
     *
     * La constante est datée et non calculée : elle désigne un fait —
     * l'ouverture du registre — et non une variable d'environnement.
     */
    protected const DATE_EFFET_INITIALE = '2023-01-01';

    public function run(): void
    {
        $village = VillageArtisanal::where('code', 'VARBAF')->first();

        if (! $village) {
            $this->command?->warn('Village VARBAF introuvable : le seeder du Socle doit passer avant celui du Commerce.');

            return;
        }

        // Un taux dont la date d'effet serait postérieure à des ventes
        // déjà saisies rendrait ces ventes incommissionnables. La date
        // retenue est donc la plus ancienne des deux : l'ouverture du
        // registre transcrit, ou celle de l'exercice en cours si le
        // village venait à en ouvrir un antérieur.
        $ouvertureExercice = $village->exerciceEnCours()?->date_debut?->toDateString()
            ?? now()->startOfYear()->toDateString();

        $dateEffet = min(self::DATE_EFFET_INITIALE, $ouvertureExercice);

        $taux = TauxCommission::firstOrCreate(
            ['village_id' => $village->id, 'date_effet' => $dateEffet],
            [
                'taux' => self::TAUX_EN_VIGUEUR,
                'reference_decision' => self::REFERENCE_ACTE,
            ],
        );

        $this->command?->info("Taux de commission en vigueur : {$taux->libelle()}.");
        $this->command?->comment('Référence de l\'acte à renseigner depuis l\'écran « Taux de commission ».');
    }
}
