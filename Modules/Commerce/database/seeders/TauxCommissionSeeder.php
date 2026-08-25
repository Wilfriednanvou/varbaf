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

    /**
     * Date d'effet du taux provisoire : l'ouverture de l'exercice
     * pendant lequel s'ouvre le registre transcrit.
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
     * Elle disparaîtra avec le taux provisoire lui-même, le jour où la
     * coordination saisira ses actes réels et leurs dates d'effet.
     */
    protected const DATE_EFFET_PROVISOIRE = '2023-01-01';

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

        $dateEffet = min(self::DATE_EFFET_PROVISOIRE, $ouvertureExercice);

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
