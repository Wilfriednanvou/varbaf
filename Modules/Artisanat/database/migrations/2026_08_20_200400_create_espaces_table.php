<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('espaces', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100);
            $table->string('type', 30);
            $table->unsignedSmallInteger('capacite')->nullable();
            $table->decimal('tarif_journalier', 12, 2)->nullable();
            $table->boolean('disponible')->default(true);

            $table->foreignId('village_id')
                ->constrained('villages_artisanaux')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['village_id', 'nom']);
            $table->index(['type', 'disponible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('espaces');
    }
};
