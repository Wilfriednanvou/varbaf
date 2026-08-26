<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le corpus indexé : une ligne par objet décrit.
 *
 * Cette table est **entièrement dérivée**. Elle ne porte aucun fait
 * métier et se reconstruit à tout moment par `varbaf:indexer`. La
 * perdre ne perd rien — c'est ce qui autorise à la vider et à la
 * réécrire sans précaution particulière, contrairement à tout le reste
 * du système.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiches_lexicales', function (Blueprint $table) {
            $table->id();

            $table->string('type', 20);

            // Volontairement sans clé étrangère : la table référence des
            // produits et des artisans, qui vivent dans deux modules
            // distincts. Une relation polymorphe classique obligerait le
            // Pilotage à connaître les classes qu'il référence ; le
            // couple (type, source_id) suffit et respecte la dépendance
            // descendante. Même raisonnement que l'arbitrage A-07 sur
            // `MouvementCaisse.origine_type`.
            $table->unsignedBigInteger('source_id');

            // Ce que la liste des sources affiche à côté d'une réponse.
            $table->string('titre');

            // La fiche lisible, conservée pour présenter un extrait avec
            // la réponse : une réponse sans sa source n'est pas
            // vérifiable.
            $table->text('texte')->nullable();

            $table->unsignedInteger('nombre_termes')->default(0);

            // Norme euclidienne du vecteur de poids, pré-calculée. Le
            // cosinus se réduit alors à un produit scalaire divisé par
            // le produit de deux normes déjà connues.
            $table->double('norme')->default(0);

            // Empreinte SHA-256 du texte composé. Elle ne sert pas à
            // éviter un recalcul des poids — l'IDF dépend du corpus
            // entier et se recalcule toujours — mais à savoir quelles
            // fiches ont réellement changé, et à ne pas les retokeniser
            // pour rien.
            $table->string('empreinte', 64)->nullable();

            $table->timestamp('indexee_le')->nullable();

            $table->timestamps();

            $table->unique(['type', 'source_id']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiches_lexicales');
    }
};
