<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campagnes de reversement mensuelles (RG-16 à RG-21).
 *
 * Une campagne est un lot : elle retient les ventes validées non encore
 * rattachées, en déduit une part due par artisan, et décaisse. Elle naît
 * en préparation — état de travail, librement recalculable et
 * abandonnable — puis devient validée, ce qui rattache définitivement
 * les ventes retenues (RG-21) et interdit tout second reversement des
 * mêmes ventes.
 *
 * `montant_total` et `nombre_beneficiaires` sont des totaux figés à la
 * validation, pas des valeurs saisies : ils constatent ce que la
 * campagne a effectivement décaissé, pour que l'état récapitulatif
 * réédité dans deux ans montre le lot tel qu'il a eu lieu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campagnes_reversement', function (Blueprint $table) {
            $table->id();

            // Le mois concerné, porté par son premier jour. Une date se
            // trie, se compare et se borne ; une chaîne « 2026-08 » ne
            // fait aucune de ces trois choses correctement.
            $table->date('periode');

            // RG-17 : date de sélection. Toute vente validée qui lui est
            // antérieure ou égale entre dans la campagne, quelle que
            // soit sa propre période.
            $table->date('date_arrete');

            // Horodatage du dernier calcul de préparation. Nul tant que
            // la campagne n'a jamais été préparée.
            $table->timestamp('date_generation')->nullable();

            // RG-12 bis : le franc CFA n'a pas de subdivision.
            $table->bigInteger('montant_total')->default(0);
            $table->unsignedInteger('nombre_beneficiaires')->default(0);

            $table->string('statut', 20)->default('EN_PREPARATION');

            $table->foreignId('exercice_id')
                ->constrained('exercices')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Traçabilité (RG-24). Deux comptes distincts dans le cas
            // normal : RG-23 sépare qui prépare de qui valide.
            $table->foreignId('generee_par')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreignId('validee_par')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('date_validation')->nullable();

            $table->timestamps();

            // RG-16 : les reversements sont mensuels. Une seule campagne
            // par mois et par exercice — deux campagnes concurrentes se
            // disputeraient les mêmes ventes, et la première validée
            // priverait la seconde de sa matière sans prévenir.
            $table->unique(['exercice_id', 'periode']);

            $table->index('statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campagnes_reversement');
    }
};
