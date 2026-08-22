<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RG-01 : une seule section ouverte par caisse. Le contrôle applicatif
 * (hook `creating` du modèle) reste la première ligne de défense, mais
 * un index unique partiel garantit la règle même contre une écriture
 * qui contournerait le modèle (import, requête directe).
 *
 * RG-12 bis : les montants de caisse sont des entiers, comme ceux de la
 * vente — voir la migration Commerce équivalente sur `ventes` et
 * `lignes_vente`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections_caisse', function (Blueprint $table) {
            $table->bigInteger('solde_ouverture')->default(0)->change();
            $table->bigInteger('solde_cloture')->nullable()->change();
        });

        DB::statement(
            'CREATE UNIQUE INDEX sections_caisse_une_ouverte_par_caisse '
            ."ON sections_caisse (caisse_id) WHERE etat = 'OUVERTE'"
        );

        Schema::table('mouvements_caisse', function (Blueprint $table) {
            $table->bigInteger('montant')->change();
            $table->bigInteger('solde_apres')->change();
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sections_caisse_une_ouverte_par_caisse');

        Schema::table('sections_caisse', function (Blueprint $table) {
            $table->decimal('solde_ouverture', 14, 2)->default(0)->change();
            $table->decimal('solde_cloture', 14, 2)->nullable()->change();
        });

        Schema::table('mouvements_caisse', function (Blueprint $table) {
            $table->decimal('montant', 14, 2)->change();
            $table->decimal('solde_apres', 14, 2)->change();
        });
    }
};
