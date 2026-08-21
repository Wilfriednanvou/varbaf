<?php

namespace Modules\Commerce\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Point d'entrée unique du module Commerce.
 *
 * Ni produits ni ventes ne sont semés : ils viendront du registre réel
 * transcrit par la coordination. Seuls le référentiel des catégories et
 * le taux de commission, sans lesquels rien ne fonctionne, sont posés.
 */
class CommerceDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CategorieProduitSeeder::class,
            TauxCommissionSeeder::class,
        ]);
    }
}
