<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal de stock (règle 3 de CLAUDE.md).
 *
 * Structure volontairement calquée sur le brouillard de caisse à venir :
 * numéro d'ordre séquentiel sans rupture, sens, montant — ici une
 * quantité — solde après opération, origine de l'écriture, et
 * contre-passation par référence au mouvement annulé. Les enjeux sont
 * moindres qu'en trésorerie, ce qui en fait le bon endroit pour rôder
 * le patron avant le module 4.
 *
 * La quantité en stock d'un produit est le cumul de ces lignes, jamais
 * une colonne. `solde_apres` est une commodité de lecture recalculable
 * à tout moment, pas une source de vérité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvements_stock', function (Blueprint $table) {
            $table->id();

            // Séquentiel par produit, sans rupture : c'est ce qui rend
            // le journal d'un article vérifiable ligne à ligne.
            $table->unsignedInteger('numero_ordre');

            $table->timestamp('date_mouvement');

            $table->foreignId('produit_id')
                ->constrained('produits')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('sens', 10);
            $table->string('type', 20);
            $table->unsignedInteger('quantite');
            $table->integer('solde_apres');
            $table->text('motif')->nullable();

            // Origine de l'écriture : dépôt, vente, retrait. Volontai-
            // rement non contraint par une clé étrangère — les modules
            // suivants y inscriront leurs propres entités sans que le
            // Commerce ait à connaître leurs tables.
            $table->string('origine_type', 60)->nullable();
            $table->unsignedBigInteger('origine_id')->nullable();

            $table->foreignId('mouvement_contrepasse_id')
                ->nullable()
                ->constrained('mouvements_stock')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('saisi_par')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // Journal en écriture seule : pas de colonne « updated_at ».
            $table->timestamp('created_at')->nullable();

            $table->unique(['produit_id', 'numero_ordre']);
            $table->index(['produit_id', 'date_mouvement']);
            $table->index(['origine_type', 'origine_id']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_stock');
    }
};
