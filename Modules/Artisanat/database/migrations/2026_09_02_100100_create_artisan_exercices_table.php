<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Artisanat\Enums\StatutParticipationArtisan;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artisan_exercices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artisan_id')
                ->constrained('artisans')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('exercice_id')
                ->constrained('exercices')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('statut', 20)->default(StatutParticipationArtisan::ACTIF->value);
            $table->date('date_activation');
            $table->date('date_desactivation')->nullable();
            $table->text('motif_desactivation')->nullable();

            $table->timestamps();

            // Un artisan ne peut avoir qu'une seule participation par
            // exercice : c'est la ligne qui porte son statut, pas une
            // suite d'evenements a agreger.
            $table->unique(['artisan_id', 'exercice_id']);
            $table->index(['exercice_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artisan_exercices');
    }
};
