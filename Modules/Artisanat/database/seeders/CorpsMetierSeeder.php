<?php

namespace Modules\Artisanat\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Artisanat\Models\CorpsMetier;

/**
 * Nomenclature des corps de métier pratiqués dans la région de l'Ouest.
 *
 * La liste reprend les filières effectivement représentées au village :
 * elle sert de référentiel de rattachement des artisans et de clé de
 * regroupement des statistiques. Elle est volontairement courte — un
 * artisan doit s'y reconnaître sans hésiter — et complétable depuis
 * l'écran des corps de métier.
 *
 * Idempotent : rejouable après ajout d'une filière sans rien écraser.
 */
class CorpsMetierSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->corpsMetiers() as $corps) {
            CorpsMetier::updateOrCreate(
                ['code' => $corps['code']],
                ['libelle' => $corps['libelle'], 'description' => $corps['description']],
            );
        }

        $this->command?->info(count($this->corpsMetiers()).' corps de métier en place.');
    }

    /**
     * @return array<int, array{code: string, libelle: string, description: string}>
     */
    protected function corpsMetiers(): array
    {
        return [
            ['code' => 'VAN', 'libelle' => 'Vannerie', 'description' => 'Tressage de fibres végétales : paniers, nattes, corbeilles, chapeaux'],
            ['code' => 'POT', 'libelle' => 'Poterie et céramique', 'description' => 'Façonnage et cuisson de l\'argile : jarres, canaris, vaisselle'],
            ['code' => 'SCB', 'libelle' => 'Sculpture sur bois', 'description' => 'Masques, statuettes, trônes, portes sculptées, tabourets'],
            ['code' => 'TIS', 'libelle' => 'Tissage', 'description' => 'Tissage de pagnes et d\'étoffes traditionnelles'],
            ['code' => 'NDO', 'libelle' => 'Ndop et teinture', 'description' => 'Confection et teinture à l\'indigo du tissu ndop'],
            ['code' => 'BRO', 'libelle' => 'Fonderie du bronze', 'description' => 'Coulée à la cire perdue : statuettes, bracelets, objets rituels'],
            ['code' => 'PER', 'libelle' => 'Perlage', 'description' => 'Ornementation à la perle : calebasses, statues, coiffes, colliers'],
            ['code' => 'BRD', 'libelle' => 'Broderie', 'description' => 'Broderie sur tenues traditionnelles et articles de décoration'],
            ['code' => 'FOR', 'libelle' => 'Forge et ferronnerie', 'description' => 'Travail du fer : outillage agricole, grilles, objets décoratifs'],
            ['code' => 'MAR', 'libelle' => 'Maroquinerie et cordonnerie', 'description' => 'Travail du cuir : sacs, sandales, ceintures, articles de bureau'],
            ['code' => 'BAM', 'libelle' => 'Mobilier en bambou et rotin', 'description' => 'Fauteuils, tables, séparations et luminaires en bambou de Chine et rotin'],
            ['code' => 'BIJ', 'libelle' => 'Bijouterie', 'description' => 'Bijoux en métal, corne, graines et matériaux locaux'],
            ['code' => 'COR', 'libelle' => 'Travail de la corne et de l\'os', 'description' => 'Peignes, bracelets, cornes à boire, objets de décoration'],
            ['code' => 'PEI', 'libelle' => 'Peinture et arts graphiques', 'description' => 'Toiles, panneaux décoratifs et arts graphiques d\'inspiration locale'],
        ];
    }
}
