<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complète les tables livrées par Laravel et par spatie/laravel-permission
 * plutôt que de les réécrire.
 *
 * La table « users » reste celle de Laravel : les tables « sessions » et
 * « password_reset_tokens » la référencent, et la remplacer par une table
 * « utilisateurs » obligerait à reprendre la migration de base pour un
 * gain purement cosmétique. Le modèle Utilisateur du Socle s'y branche
 * via $table.
 *
 * Les colonnes « module » et « description » ajoutées aux permissions
 * servent au regroupement de l'écran de gestion des rôles : sans elles,
 * une liste de plus de cent permissions serait illisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('actif')->default(true)->after('password');

            $table->foreignId('agent_id')
                ->nullable()
                ->after('actif')
                ->constrained('agents')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        Schema::table(config('permission.table_names.permissions'), function (Blueprint $table) {
            $table->string('module', 30)->nullable()->after('guard_name');
            $table->string('description')->nullable()->after('module');
        });

        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->string('description')->nullable()->after('guard_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
            $table->dropColumn('actif');
        });

        Schema::table(config('permission.table_names.permissions'), function (Blueprint $table) {
            $table->dropColumn(['module', 'description']);
        });

        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
