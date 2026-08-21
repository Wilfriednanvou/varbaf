<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document de dépôt.
 *
 * Le village devient dépositaire de biens qui ne lui appartiennent
 * pas. Le journal de stock enregistre les quantités ; ce document
 * engage les deux parties. Sans lui, une contestation sur un objet
 * disparu n'a aucun arbitre : ni l'artisan ni le village ne peuvent
 * établir ce qui avait été confié, ni quand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depots', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique();
            $table->date('date_depot');

            $table->string('statut', 20)->default('BROUILLON');
            $table->text('observations')->nullable();

            // Qui a reçu les biens et signé la décharge côté village :
            // constaté à la validation, jamais choisi.
            $table->foreignId('valide_par')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->timestamp('date_validation')->nullable();

            $table->foreignId('artisan_id')
                ->constrained('artisans')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('boutique_id')
                ->constrained('boutiques')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('exercice_id')
                ->constrained('exercices')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(['artisan_id', 'statut']);
            $table->index(['date_depot', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depots');
    }
};
