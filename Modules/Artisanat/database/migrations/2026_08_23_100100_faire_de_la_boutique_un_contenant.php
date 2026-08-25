<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La boutique se dépouille de ce qui appartenait à l'espace locatif.
 *
 * Trois colonnes s'en vont :
 *
 * - `tarif_metre_carre` et `redevance_mensuelle`, parce que la redevance
 *   n'est plus un produit de la surface par un tarif mais un montant
 *   convenu espace par espace ;
 * - `etat`, parce qu'un local qui abrite plusieurs artisans n'est ni
 *   « libre » ni « occupé » : ce sont ses espaces qui le sont.
 *
 * Ce qui reste est ce qui est vraiment de la boutique : son numéro, sa
 * place dans le bâtiment, sa surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            // PostgreSQL emporte l'index de `etat` avec la colonne.
            $table->dropColumn(['tarif_metre_carre', 'redevance_mensuelle', 'etat']);
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->decimal('tarif_metre_carre', 12, 2)->nullable();
            $table->decimal('redevance_mensuelle', 12, 2)->nullable();
            $table->string('etat', 20)->default('DISPONIBLE');
            $table->index('etat');
        });
    }
};
