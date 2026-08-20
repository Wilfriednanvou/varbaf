<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entreprises_artisanales', function (Blueprint $table) {
            $table->id();
            $table->string('raison_sociale');
            // Le numéro de contribuable est unique quand il est
            // renseigné, mais beaucoup d'artisans n'en ont pas : la
            // colonne est nullable, et PostgreSQL n'oppose pas
            // l'unicité à plusieurs valeurs nulles.
            $table->string('numero_contribuable', 30)->nullable()->unique();
            $table->string('telephone', 30)->nullable();
            $table->string('adresse')->nullable();

            $table->foreignId('village_id')
                ->constrained('villages_artisanaux')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();

            $table->index('raison_sociale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entreprises_artisanales');
    }
};
