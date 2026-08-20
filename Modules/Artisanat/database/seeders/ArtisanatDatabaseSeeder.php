<?php

namespace Modules\Artisanat\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Point d'entrée unique du module Artisanat.
 *
 * L'ordre est contraint : le référentiel des métiers avant les artisans
 * qui s'y rattachent, le parc de boutiques avant les attributions.
 */
class ArtisanatDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CorpsMetierSeeder::class,
            BoutiqueSeeder::class,
        ]);
    }
}
