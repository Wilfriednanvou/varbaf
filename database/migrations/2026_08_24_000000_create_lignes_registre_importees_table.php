<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace de reprise du registre de ventes transcrit.
 *
 * **Pourquoi une table plutôt qu'une heuristique.** La commande
 * `varbaf:importer` doit être relançable sans créer de doublon. On
 * pourrait chercher, avant chaque écriture, s'il existe déjà une vente
 * du même artisan, du même montant, à la même date — mais le registre
 * contient de vraies répétitions : le même miel, à 2 500 F, vendu deux
 * fois le même jour dans la même boutique est une situation ordinaire,
 * pas un doublon. Une comparaison sur le contenu confondrait les deux
 * cas, et le choix qu'elle ferait serait faux dans un sens ou dans
 * l'autre.
 *
 * L'empreinte porte donc sur la **ligne source** — son rang dans le
 * fichier et son contenu brut — et non sur ce qu'elle a produit. Deux
 * lignes identiques restent deux lignes distinctes ; une même ligne
 * relue reste la même ligne.
 *
 * **Elle sert aussi de pièce.** Chaque ligne y consigne ce qu'elle est
 * devenue et ce qui a été signalé à son sujet. Le rapport d'import
 * n'est donc pas un affichage éphémère : il se rejoue en SQL des mois
 * plus tard, quand quelqu'un demandera d'où sort telle vente de 2023.
 *
 * Cette table est de niveau applicatif et non modulaire : la reprise
 * traverse l'Artisanat et le Commerce, et n'appartient donc à aucun
 * des deux.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_registre_importees', function (Blueprint $table) {
            $table->id();

            // Nom du fichier repris, sans son chemin : le registre peut
            // être relu depuis un autre répertoire sans que les lignes
            // déjà importées cessent d'être reconnues.
            $table->string('fichier', 120);

            // Rang de la ligne dans le fichier, en-tête exclu.
            $table->unsignedInteger('numero_ligne');

            // Empreinte du couple (rang, contenu brut). C'est elle qui
            // porte l'idempotence.
            $table->string('empreinte', 64);

            $table->string('statut', 20);

            // Ce que la ligne a produit. Nul quand elle n'a pas pu
            // aboutir : une ligne signalée reste tracée.
            $table->unsignedBigInteger('vente_id')->nullable();
            $table->unsignedBigInteger('produit_id')->nullable();
            $table->unsignedBigInteger('artisan_id')->nullable();
            $table->unsignedBigInteger('espace_locatif_id')->nullable();

            // Anomalies relevées sur la ligne, une par entrée.
            $table->json('anomalies')->nullable();

            $table->timestamps();

            // Le garde-fou réel de l'idempotence : la base refuse la
            // seconde écriture même si le contrôle applicatif est
            // contourné.
            $table->unique('empreinte');
            $table->index(['fichier', 'numero_ligne']);
            $table->index('statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_registre_importees');
    }
};
