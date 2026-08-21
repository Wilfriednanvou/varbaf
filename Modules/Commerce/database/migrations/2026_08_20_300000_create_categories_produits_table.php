<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories_produits', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('libelle', 100)->unique();

            // Hiérarchie sur un seul niveau en pratique, mais l'auto-
            // référence n'impose aucune profondeur : la nomenclature du
            // village pourra se subdiviser sans reprise de schéma.
            // La suppression d'un parent est bloquée tant qu'il porte
            // des enfants — perdre le rattachement rendrait les
            // statistiques par famille inexploitables.
            $table->foreignId('categorie_parent_id')
                ->nullable()
                ->constrained('categories_produits')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();

            $table->index('categorie_parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories_produits');
    }
};
