<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reversements — un enregistrement par artisan et par campagne (RG-18).
 *
 * Trois montants distincts plutôt qu'un seul, parce qu'ils ne racontent
 * pas la même chose et que l'artisan a le droit de les distinguer sur
 * son reçu :
 *
 * - `montant_periode` : les ventes du mois de la campagne ;
 * - `montant_regularisation` : les ventes antérieures rattrapées
 *   (RG-19), les reprises d'annulations déjà payées, et le report du
 *   solde négatif de la campagne précédente (RG-20). Seul montant qui
 *   peut être négatif ;
 * - `montant_paye` : ce qui sort effectivement de la caisse, soit
 *   `max(0, periode + regularisation)` — jamais négatif, on ne réclame
 *   pas d'argent à un artisan (RG-20) ;
 * - `solde_reporte` : `min(0, periode + regularisation)`, la dette qui
 *   passe à la campagne suivante jusqu'à absorption.
 *
 * `montant_paye` et `solde_reporte` sont déduits des deux premiers,
 * jamais saisis : c'est le service de campagne qui les calcule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reversements', function (Blueprint $table) {
            $table->id();

            // Une préparation abandonnée s'efface avec sa campagne. Une
            // campagne validée, elle, ne s'efface jamais — la garde est
            // portée par le modèle `CampagneReversement`, de sorte que
            // cette cascade ne peut atteindre que des lignes encore en
            // préparation.
            $table->foreignId('campagne_id')
                ->constrained('campagnes_reversement')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('artisan_id')
                ->constrained('artisans')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // RG-12 bis : entiers. `montant_regularisation` et
            // `solde_reporte` acceptent le négatif, les deux autres non
            // — la garde est applicative, la colonne reste signée pour
            // ne pas transformer une erreur de calcul en erreur SQL
            // illisible.
            $table->bigInteger('montant_periode')->default(0);
            $table->bigInteger('montant_regularisation')->default(0);
            $table->bigInteger('montant_paye')->default(0);
            $table->bigInteger('solde_reporte')->default(0);

            $table->timestamp('date_paiement')->nullable();

            $table->string('statut', 20)->default('A_PAYER');

            // Le décaissement correspondant au brouillard. Nul tant que
            // la campagne n'est pas validée, et nul pour un artisan dont
            // le solde est négatif : RG-20 ne décaisse rien dans ce cas.
            $table->foreignId('mouvement_caisse_id')
                ->nullable()
                ->constrained('mouvements_caisse')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();

            // RG-18 : « une campagne génère un décaissement distinct par
            // artisan » — donc une ligne, et une seule, par artisan et
            // par campagne.
            $table->unique(['campagne_id', 'artisan_id']);

            $table->index('artisan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reversements');
    }
};
