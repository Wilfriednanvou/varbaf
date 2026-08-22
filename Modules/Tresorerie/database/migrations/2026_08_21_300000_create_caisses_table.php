<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table des caisses du village (RG-22).
 *
 * Chaque caisse est rattachée à un caissier responsable et à un
 * village. Dans les faits le village n'a sans doute qu'une seule
 * caisse, mais le modèle n'impose pas cette limite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caisses', function (Blueprint $table) {
            $table->id();

            $table->string('code', 30)->unique();
            $table->string('libelle');

            $table->foreignId('caissier_responsable_id')
                ->nullable()
                ->constrained('agents')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->string('etat', 20)->default('ACTIVE');

            $table->foreignId('village_id')
                ->constrained('villages_artisanaux')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caisses');
    }
};
