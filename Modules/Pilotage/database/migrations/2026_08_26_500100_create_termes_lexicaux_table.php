<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'index inversé : quels termes portent quelles fiches, et à quel poids.
 *
 * **C'est l'index sur `terme` qui fait tout le travail.** Sans lui,
 * répondre à une question obligerait à charger le corpus entier pour le
 * parcourir en mémoire. Avec lui, on ne remonte que les fiches qui
 * partagent au moins un terme avec la question — c'est le mécanisme d'un
 * moteur de recherche, et la raison pour laquelle cette table existe
 * séparément plutôt que sous forme de vecteur sérialisé sur la fiche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('termes_lexicaux', function (Blueprint $table) {
            $table->id();

            $table->foreignId('fiche_id')
                ->constrained('fiches_lexicales')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('terme', 60);

            // Fréquence pondérée : le nombre d'occurrences du terme dans
            // la fiche, chaque champ comptant autant de fois que son
            // poids. Conservée telle quelle pour que le recalcul des
            // poids n'ait jamais à relire les modèles source.
            $table->unsignedInteger('frequence')->default(1);

            // frequence × idf. Le poids brut est stocké plutôt que sa
            // version normalisée : il reste lisible — « le terme miel
            // pèse 4,2 dans cette fiche » — et la normalisation est
            // portée par `fiches_lexicales.norme`.
            $table->double('poids')->default(0);

            $table->unique(['fiche_id', 'terme']);
            $table->index('terme');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('termes_lexicaux');
    }
};
