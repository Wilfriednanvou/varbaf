<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-12 bis — le franc CFA n'a pas de subdivision : tous les montants
 * sont des entiers. Les colonnes de vente restaient en `decimal(12,2)`
 * par simple homogénéité de schéma ; elles passent en `bigInteger` pour
 * que la contrainte soit portée par la base, et non plus seulement par
 * la discipline du code applicatif.
 *
 * Le taux de commission n'est pas concerné : c'est un pourcentage, pas
 * un montant, et il reste décimal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->bigInteger('montant_total')->change();
            $table->bigInteger('montant_commission')->change();
            $table->bigInteger('part_artisan')->change();
        });

        Schema::table('lignes_vente', function (Blueprint $table) {
            $table->bigInteger('prix_unitaire')->change();
            $table->bigInteger('montant_ligne')->change();
        });
    }

    public function down(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->decimal('montant_total', 12, 2)->change();
            $table->decimal('montant_commission', 12, 2)->change();
            $table->decimal('part_artisan', 12, 2)->change();
        });

        Schema::table('lignes_vente', function (Blueprint $table) {
            $table->decimal('prix_unitaire', 12, 2)->change();
            $table->decimal('montant_ligne', 12, 2)->change();
        });
    }
};
