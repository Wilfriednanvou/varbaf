<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercices', function (Blueprint $table) {
            $table->id();
            $table->string('libelle', 50);
            $table->date('date_debut');
            $table->date('date_fin');
            $table->boolean('en_cours')->default(false);
            $table->boolean('cloture')->default(false);

            $table->foreignId('village_id')
                ->constrained('villages_artisanaux')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['village_id', 'libelle']);
        });

        // Un seul exercice en cours par village, imposé par la base.
        // L'index unique partiel est propre à PostgreSQL ; le projet
        // n'en supporte pas d'autre, et cette garantie vaut mieux
        // qu'un simple contrôle applicatif contournable par une
        // écriture directe ou une commande artisan.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX exercices_un_seul_en_cours_par_village
            ON exercices (village_id)
            WHERE en_cours
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('exercices');
    }
};
