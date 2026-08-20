<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('villages_artisanaux', function (Blueprint $table) {
            $table->id();

            // Identification
            $table->string('code', 20)->unique();
            $table->string('nom');
            $table->string('categorie', 30)->default('REGIONAL');
            $table->string('region', 100);

            // Coordonnées
            $table->string('adresse')->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('email')->nullable();

            // Capacité
            $table->unsignedSmallInteger('nombre_boutiques')->default(0);
            $table->decimal('superficie', 12, 2)->nullable();

            // Statut
            $table->boolean('actif')->default(true);

            $table->timestamps();

            $table->index('actif');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('villages_artisanaux');
    }
};
