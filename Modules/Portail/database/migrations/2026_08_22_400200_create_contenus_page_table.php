<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Textes de présentation du portail, éditables depuis le panneau.
 *
 * Une clé libre plutôt qu'une énumération figée : la coordination doit
 * pouvoir ajouter un encart sans qu'on redéploie le code. Le portail
 * demande une clé et affiche ce qu'il trouve — un contenu manquant se
 * traduit par une section absente, jamais par une erreur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contenus_page', function (Blueprint $table) {
            $table->id();

            // Ex. « accueil.intro », « village.presentation ».
            $table->string('cle', 60)->unique();

            $table->string('titre');
            $table->text('corps');

            // Désactiver plutôt que supprimer : un texte retiré de la
            // vitrine se remet en ligne sans avoir à être réécrit.
            $table->boolean('actif')->default(true);

            $table->unsignedInteger('ordre_affichage')->default(0);

            $table->foreignId('modifie_par')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['actif', 'ordre_affichage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contenus_page');
    }
};
