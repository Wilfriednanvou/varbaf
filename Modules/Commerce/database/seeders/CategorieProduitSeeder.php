<?php

namespace Modules\Commerce\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Commerce\Models\CategorieProduit;

/**
 * Nomenclature des produits du village, sur deux niveaux.
 *
 * Distincte des corps de métier : un même artisan vannier produit des
 * paniers et des nattes, qui se rangent et se comptent séparément. Le
 * corps de métier qualifie la personne, la catégorie qualifie l'objet.
 *
 * Idempotent : rejouable après ajout d'une famille.
 */
class CategorieProduitSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->nomenclature() as $famille) {
            $parent = CategorieProduit::updateOrCreate(
                ['code' => $famille['code']],
                ['libelle' => $famille['libelle'], 'categorie_parent_id' => null],
            );

            foreach ($famille['sous_familles'] as $code => $libelle) {
                CategorieProduit::updateOrCreate(
                    ['code' => $code],
                    ['libelle' => $libelle, 'categorie_parent_id' => $parent->id],
                );
            }
        }

        $this->command?->info(CategorieProduit::count().' catégories de produits en place.');
    }

    /**
     * @return array<int, array{code: string, libelle: string, sous_familles: array<string, string>}>
     */
    protected function nomenclature(): array
    {
        return [
            [
                'code' => 'SCU',
                'libelle' => 'Sculpture et statuaire',
                'sous_familles' => [
                    'SCU-MAS' => 'Masques',
                    'SCU-STA' => 'Statues et statuettes',
                    'SCU-TRO' => 'Trônes et sièges d\'apparat',
                ],
            ],
            [
                'code' => 'BRO',
                'libelle' => 'Bronze et métaux',
                'sous_familles' => [
                    'BRO-OBJ' => 'Objets rituels en bronze',
                    'BRO-BIJ' => 'Bijoux en métal',
                    'BRO-FER' => 'Ferronnerie décorative',
                ],
            ],
            [
                'code' => 'TER' ,
                'libelle' => 'Terre et céramique',
                'sous_familles' => [
                    'TER-JAR' => 'Jarres et canaris',
                    'TER-VAI' => 'Vaisselle et arts de la table',
                ],
            ],
            [
                'code' => 'TEX',
                'libelle' => 'Textile',
                'sous_familles' => [
                    'TEX-NDO' => 'Ndop et tissus teints',
                    'TEX-PAG' => 'Pagnes tissés',
                    'TEX-BRO' => 'Articles brodés',
                ],
            ],
            [
                'code' => 'VAN',
                'libelle' => 'Vannerie',
                'sous_familles' => [
                    'VAN-PAN' => 'Paniers et corbeilles',
                    'VAN-NAT' => 'Nattes et tapis',
                    'VAN-CHA' => 'Chapeaux et couvre-chefs',
                ],
            ],
            [
                'code' => 'MOB',
                'libelle' => 'Mobilier',
                'sous_familles' => [
                    'MOB-BAM' => 'Mobilier en bambou et rotin',
                    'MOB-BOI' => 'Mobilier en bois',
                ],
            ],
            [
                'code' => 'PER',
                'libelle' => 'Perlage et parure',
                'sous_familles' => [
                    'PER-CAL' => 'Calebasses perlées',
                    'PER-COI' => 'Coiffes et parures',
                ],
            ],
            [
                'code' => 'CUI',
                'libelle' => 'Cuir et corne',
                'sous_familles' => [
                    'CUI-MAR' => 'Maroquinerie',
                    'CUI-COR' => 'Objets en corne et en os',
                ],
            ],
        ];
    }
}
