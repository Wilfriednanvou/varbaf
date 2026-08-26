<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table des notifications persistées.
 *
 * Elle vit à la racine et non dans un module, comme « users », « cache »
 * et les tables de permissions : c'est une table du cadre. Son nom et
 * ses colonnes sont fixés par `Illuminate\Notifications\DatabaseNotification` ;
 * les traduire en français couperait le trait `Notifiable` de sa table.
 *
 * Elle arrive avec la règle 15. L'alerte de rupture est le premier — et
 * à ce jour le seul — usage du canal « database ».
 *
 * **`data` est en `json` et non en `text`.** La migration livrée par
 * Laravel pose un `text`, ce qui suffit tant que personne n'interroge le
 * contenu : le modèle `DatabaseNotification` le convertit en tableau
 * côté PHP. La cloche de Filament, elle, compte les non-lues en SQL avec
 * `data->>'format'`, et PostgreSQL n'a pas d'opérateur `->>` sur du
 * texte — la requête échoue, et avec elle le rendu de la barre
 * supérieure, donc toute page du panneau.
 *
 * Le défaut ne s'est pas vu tout de suite parce que les tests montent
 * les composants Livewire directement : seuls ceux qui font une vraie
 * requête HTTP rendent la barre supérieure, et ce sont exactement les
 * deux qui sont tombés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
