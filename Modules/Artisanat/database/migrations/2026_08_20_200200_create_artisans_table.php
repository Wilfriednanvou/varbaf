<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artisans', function (Blueprint $table) {
            $table->id();
            $table->string('matricule', 20)->unique();
            $table->string('nom', 100);
            $table->string('prenom', 100)->nullable();
            $table->string('sexe', 10)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('adresse')->nullable();
            $table->string('departement_origine', 60)->nullable();

            // Enregistrement au répertoire communal des artisans.
            $table->string('numero_enregistrement', 40)->nullable();

            $table->string('photo')->nullable();
            $table->boolean('actif')->default(true);

            // Consentement de l'artisan à voir son nom, sa photo et ses
            // produits paraître sur le portail public. Par défaut à
            // faux : la publication est un choix explicite, pas un
            // effet de bord de l'enregistrement au village.
            $table->boolean('autorisation_publication')->default(false);

            $table->foreignId('corps_metier_id')
                ->constrained('corps_metiers')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('entreprise_id')
                ->nullable()
                ->constrained('entreprises_artisanales')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreignId('village_id')
                ->constrained('villages_artisanaux')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(['village_id', 'actif']);
            $table->index('corps_metier_id');
            $table->index('autorisation_publication');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artisans');
    }
};
