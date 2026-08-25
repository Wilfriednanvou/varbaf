<?php

namespace Modules\Artisanat\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Point d'entrée unique du module Artisanat.
 *
 * L'ordre est contraint : le référentiel des secteurs avant les artisans
 * qui s'y rattachent, le parc de boutiques avant le découpage en espaces
 * locatifs, et les espaces avant les attributions qui les occupent.
 */
class ArtisanatDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CorpsMetierSeeder::class,
            BoutiqueSeeder::class,
            EspaceLocatifSeeder::class,
        ]);
    }
}
