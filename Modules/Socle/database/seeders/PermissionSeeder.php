<?php

namespace Modules\Socle\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Socle\Models\Utilisateur;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Référentiel des permissions et des profils d'habilitation.
 *
 * Ce seeder est le point de vérité unique des permissions du projet :
 * chaque module ajoute ses lignes dans le tableau ci-dessous plutôt que
 * de créer son propre seeder, ce qui rend la matrice des habilitations
 * lisible d'un seul coup d'œil au moment de la soutenance.
 *
 * Le seeder est idempotent : il utilise updateOrCreate, si bien qu'un
 * nouveau passage après ajout d'un module n'écrase aucune attribution
 * existante.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Le registre de spatie met les permissions en cache. Sans
        // purge préalable, un migrate:fresh --seed relit un cache
        // pointant sur des identifiants qui n'existent plus.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions() as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name'], 'guard_name' => 'web'],
                ['module' => $permission['module'], 'description' => $permission['description']],
            );
        }

        foreach ($this->roles() as $nom => $definition) {
            $role = Role::updateOrCreate(
                ['name' => $nom, 'guard_name' => 'web'],
                ['description' => $definition['description']],
            );

            // Le super-utilisateur ne reçoit aucune permission nominale :
            // le Gate::before du SocleServiceProvider les lui accorde
            // toutes. Lui en attribuer ferait double emploi et laisserait
            // croire que la liste est limitative.
            if ($nom === Utilisateur::ROLE_SUPER_UTILISATEUR) {
                continue;
            }

            $role->syncPermissions($definition['permissions']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<int, array{name: string, module: string, description: string}>
     */
    protected function permissions(): array
    {
        return [
            // === SOCLE : villages ===
            ['name' => 'lister_villages', 'module' => 'SOCLE', 'description' => 'Consulter les villages artisanaux'],
            ['name' => 'ajouter_village', 'module' => 'SOCLE', 'description' => 'Créer un village artisanal'],
            ['name' => 'modifier_village', 'module' => 'SOCLE', 'description' => 'Modifier un village artisanal'],
            ['name' => 'supprimer_village', 'module' => 'SOCLE', 'description' => 'Supprimer un village artisanal'],

            // === SOCLE : exercices ===
            ['name' => 'lister_exercices', 'module' => 'SOCLE', 'description' => 'Consulter les exercices'],
            ['name' => 'ajouter_exercice', 'module' => 'SOCLE', 'description' => 'Créer un exercice'],
            ['name' => 'modifier_exercice', 'module' => 'SOCLE', 'description' => 'Modifier un exercice'],
            ['name' => 'supprimer_exercice', 'module' => 'SOCLE', 'description' => 'Supprimer un exercice'],
            ['name' => 'activer_exercice', 'module' => 'SOCLE', 'description' => 'Rendre un exercice courant'],
            ['name' => 'cloturer_exercice', 'module' => 'SOCLE', 'description' => 'Clôturer définitivement un exercice'],

            // === SOCLE : agents ===
            ['name' => 'lister_agents', 'module' => 'SOCLE', 'description' => 'Consulter les agents'],
            ['name' => 'ajouter_agent', 'module' => 'SOCLE', 'description' => 'Créer un agent'],
            ['name' => 'modifier_agent', 'module' => 'SOCLE', 'description' => 'Modifier un agent'],
            ['name' => 'supprimer_agent', 'module' => 'SOCLE', 'description' => 'Supprimer un agent'],

            // === SÉCURITÉ : utilisateurs ===
            ['name' => 'lister_utilisateurs', 'module' => 'SOCLE', 'description' => 'Consulter les comptes utilisateurs'],
            ['name' => 'ajouter_utilisateur', 'module' => 'SOCLE', 'description' => 'Créer un compte utilisateur'],
            ['name' => 'modifier_utilisateur', 'module' => 'SOCLE', 'description' => 'Modifier un compte utilisateur'],
            ['name' => 'supprimer_utilisateur', 'module' => 'SOCLE', 'description' => 'Supprimer un compte utilisateur'],

            // === SÉCURITÉ : rôles ===
            ['name' => 'lister_roles', 'module' => 'SOCLE', 'description' => 'Consulter les rôles'],
            ['name' => 'ajouter_role', 'module' => 'SOCLE', 'description' => 'Créer un rôle'],
            ['name' => 'modifier_role', 'module' => 'SOCLE', 'description' => 'Modifier un rôle et ses permissions'],
            ['name' => 'supprimer_role', 'module' => 'SOCLE', 'description' => 'Supprimer un rôle'],

            // === SÉCURITÉ : audit ===
            ['name' => 'lister_journaux_audit', 'module' => 'SOCLE', 'description' => 'Consulter le journal d\'audit'],
        ];
    }

    /**
     * Profils livrés avec le Socle.
     *
     * La séparation des rôles exigée par le cahier des charges se lit
     * ici : le profil qui saisira les ventes n'est pas celui qui
     * clôturera une section de caisse ni celui qui validera une
     * campagne de reversement. Les profils métier seront complétés par
     * les modules Commerce et Trésorerie, sans jamais élargir le
     * périmètre du coordonnateur au-delà du pilotage.
     *
     * @return array<string, array{description: string, permissions: array<int, string>}>
     */
    protected function roles(): array
    {
        return [
            Utilisateur::ROLE_SUPER_UTILISATEUR => [
                'description' => 'Administrateur technique : toutes les permissions, y compris celles des modules à venir',
                'permissions' => [],
            ],
            'coordonnateur' => [
                'description' => 'Coordonnateur du village : pilote le référentiel et consulte l\'audit',
                'permissions' => [
                    'lister_villages', 'modifier_village',
                    'lister_exercices', 'ajouter_exercice', 'modifier_exercice', 'activer_exercice', 'cloturer_exercice',
                    'lister_agents', 'ajouter_agent', 'modifier_agent',
                    'lister_utilisateurs',
                    'lister_roles',
                    'lister_journaux_audit',
                ],
            ],
            'agent_commercial' => [
                'description' => 'Agent commercial : saisit les ventes, consulte le référentiel en lecture',
                'permissions' => [
                    'lister_villages',
                    'lister_exercices',
                    'lister_agents',
                ],
            ],
            'caissier' => [
                'description' => 'Caissier : tient la caisse, consulte le référentiel en lecture',
                'permissions' => [
                    'lister_villages',
                    'lister_exercices',
                    'lister_agents',
                ],
            ],
        ];
    }
}
