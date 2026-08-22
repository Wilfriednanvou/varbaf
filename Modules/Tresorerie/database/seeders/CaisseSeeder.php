<?php

namespace Modules\Tresorerie\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Tresorerie\Models\Caisse;

/**
 * Crée la caisse principale du village.
 *
 * En pratique le village n'a probablement qu'une seule caisse. Si
 * d'autres sont nécessaires, elles seront créées depuis l'écran de
 * gestion des caisses.
 */
class CaisseSeeder extends Seeder
{
    public function run(): void
    {
        $village = VillageArtisanal::query()->firstOrFail();

        Caisse::updateOrCreate(
            ['code' => 'CAISSE-PRINCIPALE'],
            [
                'libelle' => 'Caisse principale',
                'etat' => 'ACTIVE',
                'village_id' => $village->id,
            ],
        );

        $this->command->info('Caisse principale en place.');
    }
}
