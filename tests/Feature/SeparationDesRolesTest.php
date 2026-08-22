<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RG-23 : l'ouverture et la clôture d'une section de caisse, ainsi que
 * la validation d'une campagne de reversement, sont réservées à un
 * profil habilité distinct de celui de l'agent de saisie.
 *
 * Le test porte sur la vente — seule séparation aboutie à ce jour,
 * commentée comme telle dans `PermissionSeeder::roles()`. Le cumul
 * saisie/clôture côté brouillard de caisse (`coordonnateur` et
 * `chef_section_administrative_financiere` détiennent aujourd'hui à la
 * fois `saisir_mouvement_caisse` et `ouvrir_section_caisse` /
 * `cloturer_section_caisse`, faute de rôle de caissier distinct depuis
 * son retrait) reste un écart RG-23 documenté dans
 * `docs/dette-technique.md`, pas une règle éprouvée ici.
 */
class SeparationDesRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Le jeu de rôles réel : éprouver la séparation sur des rôles
        // inventés ne prouverait rien sur ceux qui seront déployés.
        $this->seed();
    }

    public function test_aucun_role_saisissant_une_vente_ne_peut_l_annuler(): void
    {
        $rolesAvecSaisie = Role::query()
            ->whereHas('permissions', fn ($requete) => $requete->where('name', 'ajouter_vente'))
            ->pluck('name');

        $this->assertNotEmpty($rolesAvecSaisie, 'Au moins un rôle doit pouvoir saisir une vente.');

        foreach ($rolesAvecSaisie as $nomRole) {
            $this->assertFalse(
                Role::findByName($nomRole)->hasPermissionTo('annuler_vente'),
                "Le rôle « {$nomRole} » saisit les ventes : il ne doit pas pouvoir les annuler (RG-23)."
            );
        }
    }

    public function test_aucun_role_saisissant_une_vente_ne_peut_ouvrir_ou_cloturer_une_section_de_caisse(): void
    {
        $rolesAvecSaisie = Role::query()
            ->whereHas('permissions', fn ($requete) => $requete->where('name', 'ajouter_vente'))
            ->pluck('name');

        foreach ($rolesAvecSaisie as $nomRole) {
            $role = Role::findByName($nomRole);

            $this->assertFalse(
                $role->hasPermissionTo('ouvrir_section_caisse'),
                "Le rôle « {$nomRole} » saisit les ventes : il ne doit pas pouvoir ouvrir une section de caisse (RG-23)."
            );
            $this->assertFalse(
                $role->hasPermissionTo('cloturer_section_caisse'),
                "Le rôle « {$nomRole} » saisit les ventes : il ne doit pas pouvoir clôturer une section de caisse (RG-23)."
            );
        }
    }
}
