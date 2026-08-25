<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deux colonnes deviennent facultatives, parce qu'un registre de ventes
 * n'est pas un registre de contrats.
 *
 * La reprise du registre transcrit reconstitue l'occupation du parc à
 * partir des ventes : elle sait qu'un artisan vendait depuis tel local,
 * et à partir de quelle date. Elle ne sait rien du secteur d'activité
 * sous lequel il est enregistré, ni de la redevance négociée avec la
 * coordination — le registre ne porte ni l'un ni l'autre.
 *
 * Trois issues étaient possibles. Inventer un secteur « divers » aurait
 * pollué un référentiel dont le seeder dit explicitement qu'il fait
 * autorité, et fait apparaître un quinzième secteur dans tous les états
 * sectoriels. Poser une redevance au plancher du barème aurait été pire
 * encore : elle est **figée sur l'attribution** et sert de base aux
 * échéances, si bien qu'un montant inventé se serait retrouvé facturé.
 * Reste la troisième : laisser la colonne vide, et le dire.
 *
 * `null` se lit ici « non renseigné », jamais « nul » ni « aucun ». Le
 * rapport d'import compte les enregistrements dans cet état, et les
 * formulaires Filament continuent d'exiger les deux valeurs : la saisie
 * humaine reste complète, seule la reprise a le droit d'être lacunaire.
 *
 * Le barème 2 000 – 60 000 F n'est pas affaibli :
 * `AttributionEspace::garantirRedevanceDansLeBareme()` ne contrôle qu'un
 * montant renseigné, et continue de refuser tout montant hors bornes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artisans', function (Blueprint $table) {
            // La clé étrangère n'est pas touchée : seule la nullité de
            // la colonne change.
            $table->unsignedBigInteger('corps_metier_id')->nullable()->change();
        });

        Schema::table('attributions_espaces', function (Blueprint $table) {
            $table->integer('redevance_convenue')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('artisans', function (Blueprint $table) {
            $table->unsignedBigInteger('corps_metier_id')->nullable(false)->change();
        });

        Schema::table('attributions_espaces', function (Blueprint $table) {
            $table->integer('redevance_convenue')->nullable(false)->change();
        });
    }
};
