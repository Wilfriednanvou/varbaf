<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des taux de commission (RG-11).
 *
 * Le taux n'est pas un paramètre que l'on écrase : c'est une suite
 * d'actes datés. Le taux appliqué à une vente est celui en vigueur à
 * sa date de vente, puis figé sur la vente elle-même (RG-10). Cette
 * table doit donc permettre de rejouer, des années plus tard, le
 * calcul d'une commission ancienne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taux_commissions', function (Blueprint $table) {
            $table->id();

            // Exprimé en pourcentage — 15.00 vaut quinze pour cent —
            // conformément à docs/modele-classes.md, dont la formule
            // divise explicitement par cent.
            $table->decimal('taux', 5, 2);

            $table->date('date_effet');
            $table->string('reference_decision')->nullable();

            // Qui a saisi la décision : constaté, jamais choisi.
            $table->foreignId('saisi_par')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreignId('village_id')
                ->constrained('villages_artisanaux')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();

            // Deux taux à la même date pour le même village rendraient
            // « le taux en vigueur » ambigu, donc le calcul de
            // commission indéterministe.
            $table->unique(['village_id', 'date_effet']);
            $table->index(['village_id', 'date_effet']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taux_commissions');
    }
};
