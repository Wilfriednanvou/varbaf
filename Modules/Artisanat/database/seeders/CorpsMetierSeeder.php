<?php

namespace Modules\Artisanat\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Artisanat\Models\CorpsMetier;

/**
 * Les quatorze secteurs officiels du Village Artisanal Régional de
 * Bafoussam.
 *
 * **Ce n'est plus une nomenclature composée.** La liste précédente
 * décrivait les filières artisanales de l'Ouest telles qu'un observateur
 * les décrirait : elle mêlait des techniques (perlage, forge) et des
 * matériaux (corne, bambou), et ne recoupait pas le découpage sous
 * lequel le village s'organise réellement. Les secteurs ci-dessous sont
 * ceux de la structure : ce sont eux qui servent de clé de rattachement
 * des artisans et de regroupement des statistiques, et c'est sous ces
 * noms que la coordination lit ses états.
 *
 * Idempotent, et **autoritaire** : les secteurs disparus de la liste
 * sont retirés, sauf s'ils portent encore des artisans — auquel cas le
 * seeder le signale plutôt que de casser un rattachement.
 */
class CorpsMetierSeeder extends Seeder
{
    public function run(): void
    {
        $secteurs = $this->secteurs();

        foreach ($secteurs as $secteur) {
            CorpsMetier::updateOrCreate(
                ['code' => $secteur['code']],
                ['libelle' => $secteur['libelle'], 'description' => $secteur['description']],
            );
        }

        $this->retirerLesSecteursObsoletes(array_column($secteurs, 'code'));

        $this->command?->info(count($secteurs).' secteurs d\'activité en place.');
    }

    /**
     * @param  array<int, string>  $codesRetenus
     */
    protected function retirerLesSecteursObsoletes(array $codesRetenus): void
    {
        $obsoletes = CorpsMetier::query()
            ->whereNotIn('code', $codesRetenus)
            ->withCount('artisans')
            ->get();

        foreach ($obsoletes as $secteur) {
            if ($secteur->artisans_count > 0) {
                $this->command?->warn(
                    "Le secteur « {$secteur->libelle} » ne figure plus dans la liste officielle mais porte "
                    ."{$secteur->artisans_count} artisan(s) : rattachez-les à un secteur officiel avant de le retirer."
                );

                continue;
            }

            $secteur->delete();
        }
    }

    /**
     * @return array<int, array{code: string, libelle: string, description: string}>
     */
    protected function secteurs(): array
    {
        return [
            ['code' => 'SCU', 'libelle' => 'Sculpture', 'description' => 'Masques, statuettes, trônes, portes sculptées, tabourets'],
            ['code' => 'BRZ', 'libelle' => 'Bronze', 'description' => 'Coulée à la cire perdue : statuettes, bracelets, objets rituels'],
            ['code' => 'AGR', 'libelle' => 'Agroalimentaire', 'description' => 'Transformation des produits du terroir : miel, café, épices, huiles'],
            ['code' => 'TEX', 'libelle' => 'Textile', 'description' => 'Confection et façonnage des étoffes, dont le ndop et les tenues traditionnelles'],
            ['code' => 'COS', 'libelle' => 'Cosmétiques', 'description' => 'Savons, beurres, huiles et soins à base de matières premières locales'],
            ['code' => 'DEC', 'libelle' => 'Décoration', 'description' => 'Objets et pièces d\'aménagement intérieur'],
            ['code' => 'CUI', 'libelle' => 'Peau et cuir', 'description' => 'Travail du cuir : sacs, sandales, ceintures, articles de bureau'],
            ['code' => 'BRD', 'libelle' => 'Broderie traditionnelle', 'description' => 'Broderie sur tenues traditionnelles et articles de décoration'],
            ['code' => 'ARP', 'libelle' => 'Arts plastiques', 'description' => 'Toiles, panneaux décoratifs et arts graphiques d\'inspiration locale'],
            ['code' => 'MED', 'libelle' => 'Produits médicinaux', 'description' => 'Préparations de la pharmacopée traditionnelle'],
            ['code' => 'REC', 'libelle' => 'Recyclage des objets', 'description' => 'Création à partir de matériaux de récupération'],
            ['code' => 'MEN', 'libelle' => 'Menuiserie', 'description' => 'Mobilier et ouvrages en bois'],
            ['code' => 'TIS', 'libelle' => 'Tissage', 'description' => 'Tissage de pagnes et d\'étoffes traditionnelles'],
            ['code' => 'VAN', 'libelle' => 'Vannerie', 'description' => 'Tressage de fibres végétales : paniers, nattes, corbeilles, chapeaux'],
        ];
    }
}
