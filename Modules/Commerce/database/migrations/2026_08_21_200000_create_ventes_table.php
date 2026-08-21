<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ventes au comptoir.
 *
 * Les montants sont en francs CFA, monnaie sans subdivision : ils sont
 * entiers par construction. La colonne reste en `decimal(12,2)` pour
 * rester homogène avec le reste du schéma, mais aucune valeur n'y
 * portera jamais de centimes — la commission est arrondie à l'unité et
 * la part artisan obtenue par différence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventes', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique();
            $table->timestamp('date_vente');

            // === MONTANTS FIGÉS À L'ENREGISTREMENT (RG-10) ===
            $table->decimal('montant_total', 12, 2);
            $table->decimal('taux_commission', 5, 2);
            $table->decimal('montant_commission', 12, 2);
            $table->decimal('part_artisan', 12, 2);

            $table->string('mode_reglement', 20)->default('ESPECES');

            // === CLIENT : entièrement facultatif ===
            $table->string('nom_client')->nullable();
            $table->string('contact_client')->nullable();
            $table->boolean('accepte_notifications')->default(false);
            $table->string('provenance_client', 20)->nullable();

            $table->string('etat', 20)->default('VALIDEE');
            $table->text('motif_annulation')->nullable();
            $table->timestamp('date_annulation')->nullable();

            // Une vente, une boutique (RG-08). L'artisan est figé sur la
            // vente : si le produit change de boutique ou d'artisan
            // ensuite, l'historique ne bouge pas.
            $table->foreignId('boutique_id')
                ->constrained('boutiques')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('artisan_id')
                ->constrained('artisans')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Agent du village ayant réalisé la vente (RG-14).
            $table->foreignId('vendeur_id')
                ->constrained('agents')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('exercice_id')
                ->constrained('exercices')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Rattachements à la Trésorerie : les tables n'existent pas
            // encore. Les colonnes sont posées sans contrainte de clé
            // étrangère ; le module 4 ajoutera les contraintes par une
            // migration additionnelle, sans toucher à celle-ci.
            $table->unsignedBigInteger('section_caisse_id')->nullable();
            $table->unsignedBigInteger('campagne_reversement_id')->nullable();

            $table->timestamps();

            $table->index(['exercice_id', 'etat']);
            $table->index(['artisan_id', 'etat']);
            $table->index(['boutique_id', 'date_vente']);
            // Sélection des ventes d'une campagne de reversement
            // (RG-17) : ventes validées non encore rattachées.
            $table->index(['campagne_reversement_id', 'etat', 'date_vente']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventes');
    }
};
