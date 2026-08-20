<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('matricule', 20)->unique();
            $table->string('nom', 100);
            $table->string('prenom', 100)->nullable();
            $table->string('sexe', 10)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('fonction', 100)->nullable();
            $table->date('date_prise_service')->nullable();
            $table->boolean('actif')->default(true);

            $table->foreignId('village_id')
                ->constrained('villages_artisanaux')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(['village_id', 'actif']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
