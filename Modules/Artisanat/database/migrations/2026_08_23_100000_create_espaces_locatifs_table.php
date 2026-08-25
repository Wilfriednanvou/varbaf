<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'unité réellement louée du village.
 *
 * Une boutique en abrite un ou plusieurs : c'est ce que le relevé du
 * parc réel a fait apparaître, et c'est la maille sur laquelle porte
 * désormais l'attribution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('espaces_locatifs', function (Blueprint $table) {
            $table->id();

            // Dérivé de la boutique à la création : B01 donne B0101,
            // B0102… Jamais saisi, jamais réécrit.
            $table->string('code', 20);

            // Nom d'usage, quand il en existe un : « côté rue »,
            // « fond gauche ». Facultatif — le code suffit à désigner.
            $table->string('libelle', 120)->nullable();

            $table->string('etat', 20)->default('DISPONIBLE');

            $table->foreignId('boutique_id')
                ->constrained('boutiques')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();

            // Le code n'est unique qu'à l'intérieur d'une boutique. Il
            // l'est de fait dans tout le village puisqu'il commence par
            // le numéro du local, mais c'est le couple qui porte la
            // garantie.
            $table->unique(['boutique_id', 'code']);
            $table->index('etat');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('espaces_locatifs');
    }
};
