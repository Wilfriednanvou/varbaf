<?php

namespace Modules\Socle\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Point d'entrée unique du module Socle.
 *
 * L'ordre est contraint : les rôles doivent exister avant qu'un compte
 * puisse s'en voir attribuer un.
 */
class SocleDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            SuperUtilisateurSeeder::class,
        ]);
    }
}
