<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lignes de vente, entièrement figées (RG-10).
 *
 * Référence, désignation et prix unitaire sont recopiés depuis le
 * produit à l'enregistrement et ne sont plus jamais relus depuis le
 * référentiel. C'est ce qui garantit qu'un reçu réimprimé dans deux ans
 * affichera le prix effectivement payé, et non le tarif du jour.
 *
 * `produit_id` reste présent comme référence technique — pour les
 * rapprochements et le journal de stock — mais ne fait pas foi sur le
 * document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_vente', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vente_id')
                ->constrained('ventes')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('produit_id')
                ->constrained('produits')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('reference_produit', 30);
            $table->string('designation');
            $table->decimal('prix_unitaire', 12, 2);
            $table->unsignedInteger('quantite');
            $table->decimal('montant_ligne', 12, 2);

            $table->timestamps();

            $table->index('produit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_vente');
    }
};
