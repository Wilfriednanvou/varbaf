<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journaux_audit', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->string('module', 30);
            $table->string('entite', 60)->nullable();
            $table->unsignedBigInteger('entite_id')->nullable();
            $table->json('donnees')->nullable();

            // Le compte peut disparaître, la trace reste : la clé
            // étrangère passe à null, le nom figé reste lisible.
            $table->foreignId('utilisateur_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->string('nom_utilisateur')->nullable();
            $table->string('adresse_ip', 45)->nullable();

            // Pas de colonne « updated_at » : une ligne d'audit ne se
            // modifie pas.
            $table->timestamp('created_at')->nullable();

            $table->index(['module', 'created_at']);
            $table->index(['entite', 'entite_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journaux_audit');
    }
};
