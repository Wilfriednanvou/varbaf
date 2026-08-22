<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RG-27 — un mouvement destiné à une journée déjà arrêtée n'est pas
 * refusé : il est enregistré à la date du jour, et `date_origine`
 * conserve la date qu'il aurait dû porter. Nul dans l'immense majorité
 * des cas — seul un mouvement reporté la renseigne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_caisse', function (Blueprint $table) {
            $table->date('date_origine')->nullable()->after('date_operation');
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_caisse', function (Blueprint $table) {
            $table->dropColumn('date_origine');
        });
    }
};
