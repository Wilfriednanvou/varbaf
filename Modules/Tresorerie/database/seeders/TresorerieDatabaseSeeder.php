<?php

namespace Modules\Tresorerie\Database\Seeders;

use Illuminate\Database\Seeder;

class TresorerieDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CaisseSeeder::class,
            LibelleMouvementSeeder::class,
            SectionCaisseSeeder::class,
        ]);
    }
}
