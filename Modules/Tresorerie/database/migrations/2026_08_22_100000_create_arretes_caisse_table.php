<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arrêté de caisse journalier (§7.7 de la spécification, RG-25 à RG-27).
 *
 * Contrôle physique quotidien : le caissier compte la caisse, le
 * système compare au solde théorique issu du brouillard. Ce n'est pas
 * un conteneur de mouvements — la table ne référence aucune écriture,
 * elle constate un état à une date donnée.
 *
 * Un seul arrêté par caisse et par jour (RG-25) : contrainte portée par
 * l'unicité `(caisse_id, date_arrete)`, doublée d'un contrôle dans le
 * modèle pour un message lisible plutôt qu'une erreur SQL brute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arretes_caisse', function (Blueprint $table) {
            $table->id();

            $table->foreignId('caisse_id')
                ->constrained('caisses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Rattachement informatif à la section active au moment de
            // l'arrêté — l'unicité porte sur la caisse, pas la section.
            $table->foreignId('section_id')
                ->constrained('sections_caisse')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->date('date_arrete');

            // RG-12 bis : entiers, comme tous les montants de caisse.
            $table->bigInteger('solde_theorique');
            $table->bigInteger('solde_physique');
            $table->bigInteger('ecart');

            // Obligatoire si l'écart est non nul (RG-26) — contrôlé
            // dans le modèle, pas seulement par l'écran.
            $table->text('commentaire_ecart')->nullable();

            $table->foreignId('arrete_par')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('date_validation');

            $table->timestamps();

            $table->unique(['caisse_id', 'date_arrete']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arretes_caisse');
    }
};
