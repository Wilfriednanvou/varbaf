<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Détail d'un reversement, vente par vente.
 *
 * Cette table répond à une question que les seuls totaux ne savent pas
 * traiter : **cette vente a-t-elle déjà été reprise ?**
 *
 * Le cas est réel. Une vente est reversée en campagne 1, l'artisan est
 * payé. Le mois suivant, la vente est annulée. La campagne 2 doit
 * reprendre la somme — c'est ce qui rend un solde négatif possible, et
 * donc RG-20 applicable. Sans trace de la reprise, la campagne 3
 * reprendrait la même annulation, puis la campagne 4, indéfiniment.
 *
 * Trois types de ligne :
 *
 * - `PERIODE` — part due au titre d'une vente du mois de la campagne ;
 * - `REGULARISATION` — part due au titre d'une vente antérieure, saisie
 *   ou retenue trop tard (RG-19) ;
 * - `REPRISE` — montant négatif, annulation d'une vente déjà payée.
 *
 * Le détail sert aussi l'état récapitulatif et le reçu : ils l'affichent
 * au lieu de le recalculer, ce qui garantit qu'un reçu réédité montre
 * exactement ce qui a été payé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_reversement', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reversement_id')
                ->constrained('reversements')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('vente_id')
                ->constrained('ventes')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // PERIODE, REGULARISATION ou REPRISE.
            $table->string('type', 20);

            // Positif pour une part due, négatif pour une reprise.
            $table->bigInteger('montant');

            // RG-19 : « avec mention de sa date d'origine ». Recopiée
            // depuis la vente et figée, comme tout ce qui documente une
            // opération passée — la vente peut être annulée ensuite, la
            // ligne doit continuer à dire de quand elle datait.
            $table->date('date_origine');

            $table->timestamps();

            // Une vente ne compte qu'une fois dans un reversement donné.
            $table->unique(['reversement_id', 'vente_id']);

            // « Cette vente a-t-elle déjà été retenue, et à quel titre ? »
            // — la question que pose chaque préparation de campagne.
            $table->index(['vente_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_reversement');
    }
};
