<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complète l'attribution des trois champs ajoutés au dossier de
 * conception : début de facturation, complétude du dossier, validateur.
 *
 * `date_debut_facturation` matérialise la gratuité du premier mois
 * (règle 12 de CLAUDE.md). Elle est dérivée de `date_debut` et
 * recalculée par le modèle, mais stockée : les échéanciers et les états
 * de redevance devront la requêter directement, et un décalage entre
 * la date affichée et la date facturée serait invérifiable après coup.
 *
 * `validee_par` référence le compte qui a constaté la complétude du
 * dossier — demande timbrée, attestation communale, images des œuvres,
 * plan de localisation de l'atelier, copie CNI. La clé passe à null si
 * le compte disparaît : la trace de la date de validation reste portée
 * par le journal d'audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attributions_boutiques', function (Blueprint $table) {
            $table->date('date_debut_facturation')
                ->nullable()
                ->after('date_debut');

            $table->boolean('dossier_complet')
                ->default(false)
                ->after('periodicite');

            $table->foreignId('validee_par')
                ->nullable()
                ->after('dossier_complet')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attributions_boutiques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('validee_par');
            $table->dropColumn(['date_debut_facturation', 'dossier_complet']);
        });
    }
};
