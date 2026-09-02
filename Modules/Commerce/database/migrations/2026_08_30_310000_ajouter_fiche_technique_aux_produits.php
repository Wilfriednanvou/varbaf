<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La fiche technique du produit, telle que l'artisan la rédige.
 *
 * **Pourquoi du `jsonb` et non des colonnes.** Les trois fiches réelles
 * relevées au village n'ont pas deux rubriques comparables : le tabouret
 * porte hauteur, diamètre, poids et couleur sous « Caractéristiques
 * techniques » ; l'huile de palmiste porte indice d'acide, indice de
 * peroxyde, point de fusion et densité sous le même intitulé. Même titre,
 * contenus sans commune mesure — et quatorze corps de métier au village.
 * Une colonne par attribut donnerait une table vide aux trois quarts,
 * qui s'allongerait à chaque métier nouveau. Le document décide de ses
 * rubriques ; la base les accueille telles quelles.
 *
 * Forme retenue : une liste **ordonnée** de `{rubrique, contenu}`, et non
 * un objet indexé par rubrique. L'ordre est porteur de sens dans ces
 * documents — l'identification vient avant le procédé, le producteur
 * ferme la fiche — et deux rubriques peuvent porter le même intitulé.
 *
 * `fiche_technique` garde le document d'origine. Ce n'est pas une
 * redondance : les rubriques extraites sont une lecture, la pièce reste
 * la source. Le jour où l'analyse s'améliore, on rejoue sur la pièce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            // `jsonb` et non `json` : PostgreSQL sait indexer le premier
            // et le compare structurellement. Le projet a déjà payé le
            // prix de l'inverse — `notifications.data` posée en `text`
            // par la migration standard de Laravel faisait planter toute
            // page du panneau, la cloche de Filament interrogeant
            // `data->>'format'`.
            $table->jsonb('caracteristiques')->nullable()->after('description');

            $table->string('fiche_technique')->nullable()->after('photo');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn(['caracteristiques', 'fiche_technique']);
        });
    }
};
