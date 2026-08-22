<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relie chaque mouvement au référentiel `libelles_mouvement`
 * (`docs/modele-classes.md` : `MouvementCaisse.libelleMouvement`).
 *
 * La saisie manuelle (onglet « Mouvements de caisse ») choisit
 * désormais un libellé du référentiel plutôt qu'un texte libre : le
 * libellé porte à la fois l'intitulé et le sens par défaut. La colonne
 * reste nullable — un libellé est un référentiel ouvert, supprimable
 * (`nullOnDelete`) — l'historique du mouvement ne dépend jamais de sa
 * survie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_caisse', function (Blueprint $table) {
            $table->foreignId('libelle_mouvement_id')
                ->nullable()
                ->after('nature')
                ->constrained('libelles_mouvement')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_caisse', function (Blueprint $table) {
            $table->dropConstrainedForeignId('libelle_mouvement_id');
        });
    }
};
