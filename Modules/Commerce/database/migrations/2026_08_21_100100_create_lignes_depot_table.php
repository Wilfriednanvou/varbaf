<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Articles confiés au village, ligne à ligne.
 *
 * La référence et la désignation sont **figées** à la validation, comme
 * sur une ligne de vente (RG-10). La décharge signée par l'artisan doit
 * rester relisable des années plus tard : si un produit est renommé
 * entre-temps, l'exemplaire papier détenu par l'artisan et l'écran du
 * village cesseraient de concorder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_depot', function (Blueprint $table) {
            $table->id();

            $table->foreignId('depot_id')
                ->constrained('depots')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Référence technique vers le produit, conservée pour les
            // rapprochements ; ce sont les valeurs figées ci-dessous
            // qui font foi sur le document.
            $table->foreignId('produit_id')
                ->constrained('produits')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('reference_produit', 30);
            $table->string('designation');
            $table->unsignedInteger('quantite');

            $table->timestamps();

            // Un même produit ne figure qu'une fois par dépôt : deux
            // lignes pour le même article rendraient la décharge
            // ambiguë au moment de la restitution.
            $table->unique(['depot_id', 'produit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_depot');
    }
};
