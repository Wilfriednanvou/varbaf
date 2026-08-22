<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mise en avant d'un artisan sur le portail, pour une période donnée.
 *
 * Une période plutôt qu'un simple drapeau : la coordination prépare ses
 * mises en avant à l'avance et veut qu'elles s'éteignent d'elles-mêmes.
 * Un drapeau obligerait quelqu'un à penser à le retirer, et personne n'y
 * pense jamais.
 *
 * `date_fin` nulle vaut « sans terme » — même convention que les
 * attributions de boutique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artisans_vedettes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artisan_id')
                ->constrained('artisans')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->date('date_debut');
            $table->date('date_fin')->nullable();

            $table->text('texte');

            $table->unsignedInteger('ordre_affichage')->default(0);

            $table->foreignId('cree_par')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['date_debut', 'date_fin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artisans_vedettes');
    }
};
