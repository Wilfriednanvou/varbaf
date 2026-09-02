<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Commerce\Enums\StatutParticipationProduit;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produit_exercices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('produit_id')
                ->constrained('produits')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('exercice_id')
                ->constrained('exercices')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('statut', 20)->default(StatutParticipationProduit::ACTIF->value);

            $table->timestamps();

            // Meme regle que artisan_exercices : une seule ligne par
            // couple produit/exercice.
            $table->unique(['produit_id', 'exercice_id']);
            $table->index(['exercice_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_exercices');
    }
};
