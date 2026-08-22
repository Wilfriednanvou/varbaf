<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Règle 14 — la validation d'un produit relève du chef de section
 * Production ; le coordonnateur peut suppléer en son absence, mais le
 * journal doit conserver l'identité du validateur réel.
 *
 * `valide_par` référence le compte qui a effectivement fait passer le
 * produit à VALIDE — jamais un choix dans une liste, toujours le compte
 * connecté au moment de l'action (RG-24 du même esprit que la
 * trésorerie). La clé passe à null si le compte disparaît : la date de
 * validation reste, elle, portée par `valide_le`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->foreignId('valide_par')
                ->nullable()
                ->after('statut_validation')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('valide_le')
                ->nullable()
                ->after('valide_par');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('valide_par');
            $table->dropColumn('valide_le');
        });
    }
};
