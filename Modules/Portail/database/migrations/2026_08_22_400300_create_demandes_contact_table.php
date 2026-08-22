<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages reçus par le formulaire de contact du portail.
 *
 * Seule table du système écrite par un visiteur anonyme. Ce qu'il a
 * écrit est figé dès l'enregistrement : le modèle n'autorise ensuite
 * que les colonnes de traitement. Un message qu'on pourrait retoucher
 * avant de le traiter ne prouverait rien de ce qui a été demandé.
 *
 * `adresse_ip` est conservée comme trace technique en cas d'abus, pas
 * comme donnée de contact : c'est `contact` que la coordination utilise
 * pour répondre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_contact', function (Blueprint $table) {
            $table->id();

            $table->string('nom');
            $table->string('contact');
            $table->string('sujet')->nullable();
            $table->text('message');

            $table->string('statut', 20)->default('NOUVELLE');

            $table->foreignId('traitee_par')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('date_traitement')->nullable();
            $table->text('note_traitement')->nullable();

            $table->ipAddress('adresse_ip')->nullable();

            $table->timestamps();

            // L'écran de suivi liste les demandes non traitées d'abord.
            $table->index(['statut', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_contact');
    }
};
