<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel des libellés de mouvement de caisse.
 *
 * Table de paramétrage : les libellés prédéfinis (Vente, Redevance,
 * Location, etc.) alimentent la liste de saisie et les rapports.
 * Supprimable — c'est un libellé de référentiel, pas un enregistrement
 * porteur d'histoire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('libelles_mouvement', function (Blueprint $table) {
            $table->id();

            $table->string('code', 30)->unique();
            $table->string('libelle');
            $table->string('sens', 10)->default('MIXTE');
            $table->boolean('actif')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('libelles_mouvement');
    }
};
