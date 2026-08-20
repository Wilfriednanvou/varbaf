<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boutiques', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20);
            $table->decimal('superficie', 10, 2)->nullable();
            $table->string('emplacement', 60)->nullable();

            // Montant de référence de la boutique. La redevance
            // réellement due est celle figée sur l'attribution : si la
            // coordination révise le tarif du parc, les contrats en
            // cours ne bougent pas.
            $table->decimal('redevance_mensuelle', 12, 2)->nullable();

            $table->string('etat', 20)->default('DISPONIBLE');

            $table->foreignId('village_id')
                ->constrained('villages_artisanaux')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();

            // Le numéro n'est unique qu'à l'intérieur d'un village :
            // deux villages peuvent tous deux avoir une boutique B-01.
            $table->unique(['village_id', 'numero']);
            $table->index('etat');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boutiques');
    }
};
