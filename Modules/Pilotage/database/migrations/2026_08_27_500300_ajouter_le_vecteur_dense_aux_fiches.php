<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le vecteur dense d'une fiche, à côté de son vecteur creux.
 *
 * **Trois colonnes, et aucune n'est décorative.**
 *
 * `vecteur` porte les coordonnées **déjà normées** : le cosinus se
 * réduit alors à un produit scalaire, exactement comme
 * `fiches_lexicales.norme` réduit celui de la branche lexicale.
 *
 * `vecteur_modele` porte le nom du modèle qui l'a produit. Deux modèles
 * d'embeddings définissent des espaces sans rapport : un index bâti
 * avec l'un puis interrogé avec l'autre rend des rapprochements
 * plausibles et faux — le pire des cas, puisque rien ne le signale. Le
 * moteur écarte tout vecteur dont le modèle n'est pas celui configuré,
 * et il ne peut le faire que si la colonne existe.
 *
 * `vecteur_empreinte` reprend l'empreinte de la fiche au moment du
 * calcul. Elle permet de ne pas recalculer ce qui n'a pas bougé : sur
 * un corpus de quelques centaines de fiches, un vecteur coûte un
 * aller-retour au modèle, et une réindexation complète à chaque
 * exécution rendrait la commande inutilisable en démonstration.
 *
 * **`json` et non `jsonb`.** Le vecteur n'est jamais interrogé par
 * PostgreSQL — il est lu en bloc et comparé en PHP. `jsonb` paierait un
 * coût de réécriture binaire à chaque insertion pour une capacité
 * d'indexation dont personne ne se sert ici.
 *
 * **Pas de pgvector.** Le corpus du village compte quelques centaines de
 * fiches. Un produit scalaire sur 768 dimensions × 400 fiches est de
 * l'ordre de trois cent mille multiplications, soit quelques
 * millisecondes en PHP. Une extension PostgreSQL à installer sur le
 * poste du village coûterait, elle, une condition de déploiement de
 * plus. Le jour où le corpus change d'ordre de grandeur, seul
 * `MoteurDense` est à reprendre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiches_lexicales', function (Blueprint $table): void {
            $table->json('vecteur')->nullable()->after('norme');
            $table->string('vecteur_modele', 80)->nullable()->after('vecteur');
            $table->string('vecteur_empreinte', 64)->nullable()->after('vecteur_modele');
        });
    }

    public function down(): void
    {
        Schema::table('fiches_lexicales', function (Blueprint $table): void {
            $table->dropColumn(['vecteur', 'vecteur_modele', 'vecteur_empreinte']);
        });
    }
};
