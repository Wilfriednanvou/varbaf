<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Artisanat\Database\Seeders\ArtisanatDatabaseSeeder;
use Modules\Commerce\Database\Seeders\CommerceDatabaseSeeder;
use Modules\Socle\Database\Seeders\SocleDatabaseSeeder;
use Modules\Tresorerie\Database\Seeders\TresorerieDatabaseSeeder;

/**
 * Orchestrateur des seeders.
 *
 * Un appel par module, dans l'ordre de dépendance du tableau de
 * CLAUDE.md. Aucun contenu métier ici : chaque module reste responsable
 * de ses propres données d'amorçage.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SocleDatabaseSeeder::class,
            ArtisanatDatabaseSeeder::class,
            CommerceDatabaseSeeder::class,
            TresorerieDatabaseSeeder::class,
        ]);
    }
}
