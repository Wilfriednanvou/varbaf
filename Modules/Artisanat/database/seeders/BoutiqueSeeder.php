<?php

namespace Modules\Artisanat\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Artisanat\Models\Boutique;
use Modules\Socle\Models\VillageArtisanal;

/**
 * Parc de boutiques du Village Artisanal Régional de Bafoussam, repris
 * du relevé réel.
 *
 * **Dix-sept locaux, et non vingt-quatre.** Le chiffre de vingt-quatre
 * venait d'un décompte qui incluait le sous-sol et l'espace vert. Ni
 * l'un ni l'autre n'est un local de vente attribué à un artisan : ils
 * sortent du périmètre, et l'import les écarte explicitement plutôt que
 * de les laisser gonfler le parc — un taux d'occupation calculé sur
 * vingt-quatre serait faux d'un tiers.
 *
 * **Seul le numéro est posé.** L'emplacement dans le bâtiment et la
 * superficie viennent du plan détenu par la coordination : CLAUDE.md
 * interdit les données fictives, et une répartition inventée se
 * retrouverait telle quelle dans les états de parc.
 *
 * Le seeder n'est pas lié à un village en dur : il alimente le village
 * de code VARBAF, et ne fait rien si le Socle n'a pas encore été semé.
 */
class BoutiqueSeeder extends Seeder
{
    /**
     * Nombre de locaux de vente du parc.
     */
    protected const NOMBRE_DE_BOUTIQUES = 17;

    /**
     * Ce que l'import écarte, et pourquoi.
     *
     * Consigné ici et affiché à chaque passage : une exclusion qu'on ne
     * voit plus finit par se relire comme un oubli.
     *
     * @var array<string, string>
     */
    protected const EXCLUSIONS = [
        'Sous-sol' => 'Réserve et locaux techniques : aucun espace locatif attribué à un artisan.',
        'Espace vert' => 'Emprise extérieure du site : ni local de vente, ni surface louable.',
    ];

    public function run(): void
    {
        $village = VillageArtisanal::where('code', 'VARBAF')->first();

        if (! $village) {
            $this->command?->warn('Village VARBAF introuvable : le seeder du Socle doit passer avant celui de l\'Artisanat.');

            return;
        }

        for ($rang = 1; $rang <= self::NOMBRE_DE_BOUTIQUES; $rang++) {
            Boutique::updateOrCreate(
                [
                    'village_id' => $village->id,
                    'numero' => 'B'.str_pad((string) $rang, 2, '0', STR_PAD_LEFT),
                ],
                [
                    // Laissés nuls à dessein : à reprendre du plan réel.
                    'emplacement' => null,
                    'superficie' => null,
                ],
            );
        }

        $total = Boutique::where('village_id', $village->id)->count();

        $this->command?->info("{$total} boutiques en place pour {$village->nom}.");

        $this->command?->comment('Exclusions volontaires du périmètre :');

        foreach (self::EXCLUSIONS as $exclusion => $motif) {
            $this->command?->comment("  — {$exclusion} : {$motif}");
        }

        $this->command?->comment('Emplacements et superficies à renseigner depuis le plan du bâtiment.');
    }
}
