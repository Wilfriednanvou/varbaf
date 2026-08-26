<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le vocabulaire du corpus : un terme, sa fréquence documentaire, son IDF.
 *
 * Cette table pourrait se recalculer à la volée depuis `termes_lexicaux`
 * par un `group by`. Elle est matérialisée parce qu'une question entrante
 * doit être pondérée avec le **même** IDF que le corpus : sans elle, il
 * faudrait agréger tout l'index à chaque interrogation, pour un résultat
 * qui ne change qu'à la réindexation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vocabulaire_lexical', function (Blueprint $table) {
            $table->id();

            $table->string('terme', 60)->unique();

            // Nombre de fiches portant le terme.
            $table->unsignedInteger('documents')->default(0);

            // log(1 + N / documents). La forme lissée reste strictement
            // positive même pour un terme présent dans toutes les fiches,
            // là où le log(N / df) classique le ramènerait à zéro et
            // ferait disparaître le terme du calcul.
            $table->double('idf')->default(0);

            $table->index('idf');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vocabulaire_lexical');
    }
};
