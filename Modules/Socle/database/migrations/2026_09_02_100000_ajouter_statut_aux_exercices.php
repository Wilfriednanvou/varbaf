<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Socle\Enums\StatutExercice;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercices', function (Blueprint $table) {
            // Colonne ajoutee a cote de `en_cours`/`cloture`, pas a leur
            // place : les deux booleens restent la source ecrite par le
            // formulaire et par activer()/cloturer(), `statut` s'en
            // deduit. Voir le crochet `saving` du modele.
            $table->string('statut', 20)->default(StatutExercice::EN_PREPARATION->value)->after('cloture');
        });

        // Retrouve pour les exercices deja en base ce que le crochet
        // aurait pose s'il avait toujours existe. ARCHIVE n'a pas
        // d'equivalent dans les deux booleens : aucun exercice existant
        // ne peut donc l'obtenir ici, ce qui est le comportement juste —
        // l'archivage est une decision explicite, jamais retrouvee.
        DB::table('exercices')->where('cloture', true)->update(['statut' => StatutExercice::CLOTURE->value]);
        DB::table('exercices')->where('cloture', false)->where('en_cours', true)->update(['statut' => StatutExercice::ACTIF->value]);
    }

    public function down(): void
    {
        Schema::table('exercices', function (Blueprint $table) {
            $table->dropColumn('statut');
        });
    }
};
