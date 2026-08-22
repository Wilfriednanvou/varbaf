<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute la contrainte de clé étrangère sur `ventes.campagne_reversement_id`.
 *
 * La colonne existe depuis la migration du Commerce, posée sans
 * contrainte parce que `campagnes_reversement` n'existait pas encore.
 * Même procédé que pour `section_caisse_id` : une migration
 * additionnelle de la Trésorerie complète le lien sans toucher à celle
 * du Commerce.
 *
 * `nullOnDelete` est un filet qui ne se déclenchera jamais : la colonne
 * n'est écrite qu'à la validation (RG-21), et une campagne validée ne se
 * supprime pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->foreign('campagne_reversement_id')
                ->references('id')
                ->on('campagnes_reversement')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->dropForeign(['campagne_reversement_id']);
        });
    }
};
