<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue des produits déposés par les artisans.
 *
 * **Aucune colonne de quantité.** La règle 3 de CLAUDE.md fait du stock
 * un solde calculé à partir de `mouvements_stock` — table qui arrive
 * avec la tranche suivante. Ne pas créer la colonne est le seul moyen
 * sûr de tenir la règle : une colonne `quantite_disponible` finirait
 * tôt ou tard incrémentée à la main quelque part, et divergerait
 * silencieusement du journal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produits', function (Blueprint $table) {
            $table->id();

            // Générée à la création, jamais saisie, jamais modifiée
            // ensuite (RG-09) : elle sert à l'étiquetage physique.
            $table->string('reference', 30)->unique();

            $table->string('designation');
            $table->text('description')->nullable();
            $table->decimal('prix_unitaire', 12, 2);

            // Seuil de déclenchement de l'alerte de rupture (règle 14).
            // Nul = produit non surveillé.
            $table->unsignedInteger('seuil_alerte')->nullable();

            $table->string('statut_validation', 20)->default('SOUMIS');
            $table->boolean('piece_unique')->default(false);
            $table->string('photo')->nullable();
            $table->boolean('actif')->default(true);

            $table->foreignId('categorie_id')
                ->constrained('categories_produits')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('artisan_id')
                ->constrained('artisans')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('boutique_id')
                ->constrained('boutiques')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();

            // L'écran de vente part de la boutique puis filtre les
            // produits vendables : c'est l'index qui porte RG-09 bis.
            $table->index(['boutique_id', 'statut_validation', 'actif']);
            $table->index(['artisan_id', 'actif']);
            $table->index('categorie_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
