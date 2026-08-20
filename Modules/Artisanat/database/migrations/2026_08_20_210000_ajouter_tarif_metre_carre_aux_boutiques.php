<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Règle 12 de CLAUDE.md : la redevance mensuelle d'une boutique se
 * calcule à partir de sa superficie et du tarif au mètre carré.
 *
 * La colonne `redevance_mensuelle` existait déjà et n'est pas
 * supprimée : elle devient une valeur dérivée, recalculée par le modèle
 * à chaque écriture et jamais saisie. La conserver en base plutôt que
 * de la réduire à un accesseur permet de trier et de filtrer dessus en
 * SQL — ce dont l'écran du parc et les futurs échéanciers ont besoin.
 * Le compromis est assumé : dérivée mais matérialisée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->decimal('tarif_metre_carre', 12, 2)
                ->nullable()
                ->after('superficie');
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropColumn('tarif_metre_carre');
        });
    }
};
