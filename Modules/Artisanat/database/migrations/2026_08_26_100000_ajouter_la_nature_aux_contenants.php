<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le sous-sol et l'espace vert entrent dans le parc locatif.
 *
 * **Ce que le relevé de recouvrement a établi.** La table `boutiques`
 * ne contenait que des locaux de vente, parce que le sous-sol et
 * l'espace vert avaient été déclarés hors périmètre : on les croyait
 * dépourvus d'espace locatif. L'état de recouvrement des redevances
 * 2026 du village les dément — trois espaces attribués, nommés,
 * facturés, dont un à 60 000 FCFA par mois, soit le plus cher du parc.
 *
 * `boutiques` devient donc la table des contenants, et `nature` dit
 * lequel est un local de vente. La distinction n'est pas cosmétique :
 * le taux d'occupation que la coordination présente à sa tutelle porte
 * sur les boutiques, pas sur le locatif entier.
 *
 * Le défaut à `BOUTIQUE` conserve le sens des lignes déjà en place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->string('nature', 20)
                ->default('BOUTIQUE')
                ->after('numero');

            $table->index('nature');
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            // PostgreSQL emporte l'index avec la colonne.
            $table->dropColumn('nature');
        });
    }
};
