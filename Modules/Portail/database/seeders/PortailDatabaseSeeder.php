<?php

namespace Modules\Portail\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Amorçage du module Portail — le sixième et dernier de la chaîne.
 *
 * Il ne sème que les textes éditoriaux du site public. Les publications
 * de produits et les artisans vedettes ne sont pas semés : ce sont des
 * choix de mise en avant, qui appartiennent à la coordination et se
 * font depuis le panneau. Les inventer ici mettrait en vitrine des
 * pièces que personne n'a décidé d'y mettre.
 */
class PortailDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ContenuPageSeeder::class,
        ]);
    }
}
