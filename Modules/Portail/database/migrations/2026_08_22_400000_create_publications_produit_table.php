<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche portail d'un produit — couche de diffusion, pas de duplication.
 *
 * La table ne recopie ni la désignation, ni le prix, ni le stock : ces
 * valeurs vivent sur `produits` et n'ont aucune raison d'exister deux
 * fois. Elle ne porte que ce qui est propre à la vitrine — une photo de
 * mise en avant, un texte commercial, un ordre d'affichage — et la
 * décision de publier.
 *
 * **Publication à non publié par défaut.** Créer la fiche ne met rien
 * en ligne : c'est un brouillon qu'on prépare, puis qu'on publie. Le
 * défaut de la colonne le dit, le modèle le redit.
 *
 * **Deux verrous, deux responsabilités.** `publie` est la décision de
 * mise en ligne ; le statut `EXPOSE` du produit reste la porte d'entrée
 * (`StatutValidationProduit::estPubliable()`). Retirer un produit de la
 * vitrine — EXPOSE → VALIDE — le dépublie donc effectivement, sans
 * qu'aucune fiche n'ait à être touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publications_produit', function (Blueprint $table) {
            $table->id();

            // Une fiche par produit. La fiche disparaît avec le produit
            // qu'elle décrit — elle n'a aucune existence propre.
            $table->foreignId('produit_id')
                ->unique()
                ->constrained('produits')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->boolean('publie')->default(false);

            // Photo de mise en avant, distincte de la photo du produit :
            // la vitrine cadre et éclaire autrement qu'une fiche de
            // gestion. Nulle, la publication retombe sur celle du produit.
            $table->string('photo')->nullable();

            $table->text('description_commerciale')->nullable();

            $table->unsignedInteger('ordre_affichage')->default(0);

            // Une trace se constate : qui a mis en ligne, et quand.
            $table->foreignId('publie_par')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('date_publication')->nullable();

            $table->timestamps();

            // Le catalogue public trie par ordre d'affichage parmi les
            // seules fiches publiées : c'est sa requête de tous les jours.
            $table->index(['publie', 'ordre_affichage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publications_produit');
    }
};
