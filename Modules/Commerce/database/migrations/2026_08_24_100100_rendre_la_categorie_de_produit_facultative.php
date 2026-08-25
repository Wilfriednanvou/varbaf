<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La famille du produit devient facultative, pour la même raison que le
 * secteur de l'artisan côté Artisanat.
 *
 * Le registre transcrit donne une désignation — « Miel », « Croquette »,
 * « bambou de chine » — et un conditionnement. Il ne dit pas à quelle
 * famille de la nomenclature l'objet appartient, et la désignation ne
 * permet pas de le déduire sans risque : « Chire » ou « caft-reg » ne
 * se rangent nulle part par lecture automatique.
 *
 * Classer au jugé aurait produit une nomenclature qui a l'air complète
 * et qui est fausse — le pire des deux mondes, puisque plus personne ne
 * reprendrait ensuite un classement qui semble fait. La colonne reste
 * vide, le rapport d'import dit combien de produits sont à classer, et
 * la section Production tranche depuis l'écran du catalogue.
 *
 * Le formulaire Filament continue d'exiger la catégorie : seule la
 * reprise a le droit de laisser la colonne vide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->unsignedBigInteger('categorie_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->unsignedBigInteger('categorie_id')->nullable(false)->change();
        });
    }
};
