<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute la contrainte de clé étrangère sur `ventes.section_caisse_id`.
 *
 * La colonne existe déjà (posée par le module Commerce sans contrainte,
 * car la table `sections_caisse` n'existait pas encore). Cette
 * migration additionnelle complète le lien sans toucher à la migration
 * du Commerce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->foreign('section_caisse_id')
                ->references('id')
                ->on('sections_caisse')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->dropForeign(['section_caisse_id']);
        });
    }
};
