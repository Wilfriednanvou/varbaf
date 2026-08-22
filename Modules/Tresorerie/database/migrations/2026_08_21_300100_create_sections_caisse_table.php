<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sections de caisse — exercices d'une caisse (RG-01, RG-02, RG-07).
 *
 * Une section couvre un exercice comptable entier. Une seule section
 * ouverte par caisse à tout moment. Le solde d'ouverture est égal au
 * solde de clôture de la section précédente. La clôture est
 * irréversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections_caisse', function (Blueprint $table) {
            $table->id();

            $table->foreignId('caisse_id')
                ->constrained('caisses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('libelle');
            $table->timestamp('date_ouverture');
            $table->timestamp('date_cloture')->nullable();
            $table->decimal('solde_ouverture', 14, 2)->default(0);
            $table->decimal('solde_cloture', 14, 2)->nullable();

            $table->string('etat', 20)->default('OUVERTE');

            $table->foreignId('ouverte_par')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('cloturee_par')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreignId('village_id')
                ->constrained('villages_artisanaux')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('exercice_id')
                ->constrained('exercices')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();

            // Index pour la recherche de la section ouverte d'une caisse
            $table->index(['caisse_id', 'etat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sections_caisse');
    }
};
