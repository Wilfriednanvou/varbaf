<?php

namespace Modules\Artisanat\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Artisanat\Enums\NatureContenant;
use Modules\Artisanat\Models\Boutique;
use Modules\Socle\Models\VillageArtisanal;

/**
 * Parc locatif du Village Artisanal Régional de Bafoussam, repris du
 * relevé réel.
 *
 * **Dix-sept locaux de vente, plus trois emprises louées.** Le chiffre
 * de vingt-quatre qui circulait venait d'un décompte confus. La
 * correction du 23/08 l'avait ramené à dix-sept en écartant le sous-sol
 * et l'espace vert, au motif qu'aucun des deux n'abritait d'espace
 * locatif attribué.
 *
 * **Ce motif était faux, et l'état de recouvrement des redevances 2026
 * le dit.** Le sous-sol abrite deux espaces loués — G0201 à la CNTC
 * pour 60 000 FCFA par mois, la redevance la plus élevée du parc, et
 * G0202 à SCOOPS AAMRO pour 10 000 — et l'espace vert un troisième,
 * EV0101, à 5 000. Soit 75 000 FCFA de redevance mensuelle que le
 * système ne voyait pas. Ils entrent donc dans le parc, et `nature` les
 * distingue des locaux de vente : c'est ce qui permet au taux
 * d'occupation présenté à la tutelle de rester calculé sur les
 * boutiques seules, sans que le locatif réel disparaisse pour autant.
 *
 * **B13 et B17 n'apparaissent pas au relevé de recouvrement.** Ils sont
 * néanmoins posés : le décompte des dix-sept locaux vient du relevé
 * physique du bâtiment, pas de la feuille de redevances, et une feuille
 * de recouvrement ne mentionne que ce qui se facture. Ils sont donc des
 * locaux sans espace locatif connu — ce qui n'est pas la même chose que
 * des locaux inexistants, et se corrige depuis l'écran dédié.
 *
 * **Seuls le numéro et la nature sont posés.** L'emplacement dans le
 * bâtiment et la superficie viennent du plan détenu par la
 * coordination : CLAUDE.md interdit les données fictives, et une
 * répartition inventée se retrouverait telle quelle dans les états de
 * parc.
 *
 * Le seeder n'est pas lié à un village en dur : il alimente le village
 * de code VARBAF, et ne fait rien si le Socle n'a pas encore été semé.
 */
class BoutiqueSeeder extends Seeder
{
    /**
     * Nombre de locaux de vente du parc, numérotés B01 à B17.
     */
    protected const NOMBRE_DE_BOUTIQUES = 17;

    /**
     * Emprises louées hors du bâtiment de vente, telles que les nomme
     * l'état de recouvrement des redevances.
     *
     * @var array<string, NatureContenant>
     */
    protected const CONTENANTS_HORS_VENTE = [
        'SS01' => NatureContenant::SOUS_SOL,
        'SS02' => NatureContenant::SOUS_SOL,
        'EV01' => NatureContenant::ESPACE_VERT,
    ];

    public function run(): void
    {
        $village = VillageArtisanal::where('code', 'VARBAF')->first();

        if (! $village) {
            $this->command?->warn('Village VARBAF introuvable : le seeder du Socle doit passer avant celui de l\'Artisanat.');

            return;
        }

        for ($rang = 1; $rang <= self::NOMBRE_DE_BOUTIQUES; $rang++) {
            $this->poser($village->id, 'B'.str_pad((string) $rang, 2, '0', STR_PAD_LEFT), NatureContenant::BOUTIQUE);
        }

        foreach (self::CONTENANTS_HORS_VENTE as $numero => $nature) {
            $this->poser($village->id, $numero, $nature);
        }

        $parc = Boutique::where('village_id', $village->id)->get();
        $vente = $parc->where('nature', NatureContenant::BOUTIQUE)->count();

        $this->command?->info("{$parc->count()} contenants en place pour {$village->nom}, dont {$vente} locaux de vente.");

        foreach (self::CONTENANTS_HORS_VENTE as $numero => $nature) {
            $this->command?->comment("  — {$numero} : {$nature->getLabel()}, loué mais hors du taux d'occupation des boutiques.");
        }

        $this->command?->comment('Emplacements et superficies à renseigner depuis le plan du bâtiment.');
    }

    protected function poser(int $villageId, string $numero, NatureContenant $nature): void
    {
        Boutique::updateOrCreate(
            ['village_id' => $villageId, 'numero' => $numero],
            [
                'nature' => $nature,
                // Laissés nuls à dessein : à reprendre du plan réel.
                'emplacement' => null,
                'superficie' => null,
            ],
        );
    }
}
