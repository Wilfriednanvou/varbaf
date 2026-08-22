<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brouillard de caisse — journal chronologique de tous les flux (RG-04, RG-05, RG-06).
 *
 * Structure calquée sur `mouvements_stock` : numéro d'ordre séquentiel
 * sans rupture par section, sens, montant, solde après opération,
 * origine de l'écriture, et contre-passation par référence au
 * mouvement annulé.
 *
 * Le solde de caisse est le cumul de ces lignes, jamais une colonne.
 * `solde_apres` est une commodité de lecture recalculable à tout moment.
 *
 * Journal en écriture seule : pas de colonne `updated_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvements_caisse', function (Blueprint $table) {
            $table->id();

            // Séquentiel par section, sans rupture (RG-04).
            $table->unsignedInteger('numero_ordre');

            $table->timestamp('date_operation');

            $table->foreignId('section_id')
                ->constrained('sections_caisse')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('nature', 20);
            $table->string('sens', 10);
            $table->decimal('montant', 14, 2);
            $table->decimal('solde_apres', 14, 2);
            $table->string('libelle');
            $table->string('piece_justificative')->nullable();

            // Origine de l'écriture : Vente, Reversement, etc.
            // Pas de clé étrangère — les modules suivants y inscrivent
            // leurs propres entités sans que la Trésorerie ait à
            // connaître leurs tables.
            $table->string('origine_type', 60)->nullable();
            $table->unsignedBigInteger('origine_id')->nullable();

            $table->foreignId('mouvement_contrepasse_id')
                ->nullable()
                ->constrained('mouvements_caisse')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('saisi_par')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // Journal en écriture seule : pas de updated_at.
            $table->timestamp('created_at')->nullable();

            $table->unique(['section_id', 'numero_ordre']);
            $table->index(['section_id', 'date_operation']);
            $table->index(['origine_type', 'origine_id']);
            $table->index('nature');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_caisse');
    }
};
