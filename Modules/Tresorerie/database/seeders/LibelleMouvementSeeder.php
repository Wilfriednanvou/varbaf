<?php

namespace Modules\Tresorerie\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Tresorerie\Models\LibelleMouvement;

/**
 * Référentiel des libellés de mouvement prédéfinis.
 *
 * Ces libellés alimentent la liste de saisie et les rapports. Ils
 * sont modifiables et supprimables depuis l'écran de gestion.
 */
class LibelleMouvementSeeder extends Seeder
{
    public function run(): void
    {
        $libelles = [
            ['code' => 'VENTE', 'libelle' => 'Vente de produits artisanaux', 'sens' => 'ENTREE'],
            ['code' => 'REDEVANCE', 'libelle' => 'Redevance boutique', 'sens' => 'ENTREE'],
            ['code' => 'LOCATION', 'libelle' => 'Location d\'espace', 'sens' => 'ENTREE'],
            ['code' => 'FORMATION', 'libelle' => 'Frais de formation', 'sens' => 'ENTREE'],
            ['code' => 'DEPENSE', 'libelle' => 'Dépense de fonctionnement', 'sens' => 'SORTIE'],
            ['code' => 'REVERSEMENT', 'libelle' => 'Reversement part artisan', 'sens' => 'SORTIE'],
        ];

        foreach ($libelles as $libelle) {
            LibelleMouvement::updateOrCreate(
                ['code' => $libelle['code']],
                $libelle,
            );
        }

        $this->command->info(count($libelles).' libellés de mouvement en place.');
    }
}
