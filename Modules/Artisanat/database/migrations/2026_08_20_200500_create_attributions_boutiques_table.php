<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attributions_boutiques', function (Blueprint $table) {
            $table->id();
            $table->date('date_debut');

            // Nulle tant que l'attribution court sans terme convenu.
            $table->date('date_fin')->nullable();

            // Montant figé au moment de l'attribution : c'est lui qui
            // fait foi pour les encaissements, pas le tarif courant de
            // la boutique.
            $table->decimal('redevance_convenue', 12, 2);
            $table->string('periodicite', 20)->default('MENSUELLE');

            $table->string('statut', 20)->default('ACTIVE');
            $table->text('motif_resiliation')->nullable();

            $table->foreignId('artisan_id')
                ->constrained('artisans')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('boutique_id')
                ->constrained('boutiques')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('exercice_id')
                ->constrained('exercices')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();

            // Index de service du contrôle de chevauchement : la
            // requête de vérification filtre sur la boutique et le
            // statut avant de comparer les dates.
            $table->index(['boutique_id', 'statut', 'date_debut']);
            $table->index(['artisan_id', 'statut']);
            $table->index('exercice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attributions_boutiques');
    }
};
