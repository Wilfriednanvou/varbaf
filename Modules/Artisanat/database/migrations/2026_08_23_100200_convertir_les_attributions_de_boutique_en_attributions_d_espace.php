<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'attribution change de maille : elle portait la boutique, elle porte
 * désormais l'espace locatif.
 *
 * **Aucune reprise de données.** La table est vide à ce stade du projet
 * — aucun seeder n'y écrit et la saisie des contrats réels n'a pas
 * commencé — et la procédure du dépôt est `migrate:fresh --seed`. La
 * clé étrangère est donc posée non nulle d'emblée, plutôt que nullable
 * puis resserrée : une colonne « obligatoire mais nullable » finit
 * toujours par accueillir la ligne qu'elle était censée interdire.
 *
 * La redevance passe en entier au passage : le franc CFA n'a pas de
 * subdivision, et un montant convenu ne se divise pas davantage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attributions_boutiques', function (Blueprint $table) {
            $table->foreignId('espace_locatif_id')
                ->constrained('espaces_locatifs')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });

        Schema::table('attributions_boutiques', function (Blueprint $table) {
            $table->dropForeign(['boutique_id']);
            $table->dropIndex('attributions_boutiques_boutique_id_statut_date_debut_index');
            $table->dropColumn('boutique_id');
        });

        DB::statement(
            'alter table attributions_boutiques '
            .'alter column redevance_convenue type integer using round(redevance_convenue)::integer'
        );

        Schema::rename('attributions_boutiques', 'attributions_espaces');

        Schema::table('attributions_espaces', function (Blueprint $table) {
            // Index de service du contrôle de chevauchement : la requête
            // de vérification filtre sur l'espace et le statut avant de
            // comparer les dates.
            $table->index(['espace_locatif_id', 'statut', 'date_debut']);
        });
    }

    public function down(): void
    {
        Schema::table('attributions_espaces', function (Blueprint $table) {
            $table->dropIndex('attributions_espaces_espace_locatif_id_statut_date_debut_index');
        });

        Schema::rename('attributions_espaces', 'attributions_boutiques');

        DB::statement(
            'alter table attributions_boutiques '
            .'alter column redevance_convenue type numeric(12, 2)'
        );

        Schema::table('attributions_boutiques', function (Blueprint $table) {
            $table->dropForeign(['espace_locatif_id']);
            $table->dropColumn('espace_locatif_id');

            $table->foreignId('boutique_id')
                ->constrained('boutiques')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->index(['boutique_id', 'statut', 'date_debut']);
        });
    }
};
