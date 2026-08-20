<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Artisanat\Filament\Resources\ArtisanResource;
use Modules\Artisanat\Filament\Resources\ArtisanResource\Pages\ManageArtisans;
use Modules\Artisanat\Models\Artisan;
use Modules\Socle\Models\Utilisateur;
use Tests\TestCase;

/**
 * Éprouve les habilitations avec un compte qui n'est pas
 * super-utilisateur — le contrôle exigé par la checklist de
 * docs/conventions-filament.md.
 *
 * Le scénario est celui d'un chef de section Promotion et
 * Commercialisation : il consulte le fichier des artisans pour vendre,
 * mais n'a pas à en créer. Le test vérifie que la porte de création
 * lui est effectivement fermée, et pas seulement invisible.
 *
 * Un des tests ci-dessous constate volontairement un comportement que
 * l'audit du 20 août a relevé : faute de Policy, la couche
 * d'autorisation de Filament laisse passer tout le monde, et c'est le
 * `->visible()` de chaque action qui tient la porte. Le figer dans un
 * test évite qu'on le redécouvre après le gel du code.
 */
class HabilitationArtisanTest extends TestCase
{
    use RefreshDatabase;

    protected Utilisateur $chefDeSection;

    protected function setUp(): void
    {
        parent::setUp();

        // Le jeu d'amorçage complet : village, exercice, rôles et
        // permissions réels. Éprouver les habilitations sur des rôles
        // inventés ne prouverait rien sur ceux qui seront déployés.
        $this->seed();

        Filament::setCurrentPanel('admin');

        $this->chefDeSection = Utilisateur::create([
            'name' => 'Chef de section Promotion',
            'email' => 'promotion@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
        ]);

        $this->chefDeSection->assignRole('chef_section_promotion_commercialisation');
    }

    public function test_le_jeu_de_permissions_du_role_est_bien_celui_attendu(): void
    {
        $this->assertFalse(
            $this->chefDeSection->estSuperUtilisateur(),
            'Le scénario ne vaut que pour un compte non super-utilisateur.'
        );

        $this->assertTrue($this->chefDeSection->can('lister_artisans'));
        $this->assertFalse($this->chefDeSection->can('ajouter_artisan'));
        $this->assertFalse($this->chefDeSection->can('modifier_artisan'));
        $this->assertFalse($this->chefDeSection->can('supprimer_artisan'));
    }

    public function test_la_liste_des_artisans_reste_accessible(): void
    {
        $this->actingAs($this->chefDeSection);

        $this->assertTrue(ArtisanResource::canAccess());
    }

    public function test_un_compte_sans_permission_de_lecture_n_atteint_pas_la_ressource(): void
    {
        $visiteur = Utilisateur::create([
            'name' => 'Compte sans rôle',
            'email' => 'sans-role@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
        ]);

        $this->actingAs($visiteur);

        $this->assertFalse(ArtisanResource::canAccess());
    }

    public function test_le_bouton_de_creation_est_masque(): void
    {
        $this->actingAs($this->chefDeSection);

        Livewire::test(ManageArtisans::class)
            ->assertSuccessful()
            ->assertActionHidden('create');
    }

    /**
     * Le point qui compte : masquer un bouton ne prouve rien si l'on
     * peut déclencher l'action par une requête forgée. Filament refuse
     * de monter une action masquée — `isDisabled()` retourne vrai dès
     * que `isHidden()` l'est — et c'est ce refus qui fait office de
     * garde, à défaut de Policy.
     */
    public function test_l_action_de_creation_ne_peut_pas_etre_declenchee_de_force(): void
    {
        $this->actingAs($this->chefDeSection);

        Livewire::test(ManageArtisans::class)
            ->mountAction('create')
            ->assertActionNotMounted();

        $this->assertSame(0, Artisan::count(), 'Aucun artisan ne doit avoir été créé.');
    }

    /**
     * Constat, pas approbation : sans Policy déclarée, la couche
     * d'autorisation de Filament autorise la création à n'importe quel
     * compte du panneau. Si ce test se met un jour à échouer, c'est
     * qu'une Policy a été ajoutée — bonne nouvelle, à répercuter dans
     * docs/dette-technique.md.
     */
    public function test_l_absence_de_policy_rend_la_couche_filament_permissive(): void
    {
        $this->actingAs($this->chefDeSection);

        $this->assertTrue(
            ArtisanResource::canCreate(),
            'Comportement attendu tant qu\'aucune Policy n\'est déclarée : '
            .'la sécurité repose alors entièrement sur le ->visible() de chaque action.'
        );
    }

    public function test_le_super_utilisateur_conserve_l_acces_a_la_creation(): void
    {
        $administrateur = Utilisateur::where('email', 'admin@varbaf.local')->firstOrFail();

        $this->actingAs($administrateur);

        $this->assertTrue($administrateur->estSuperUtilisateur());

        Livewire::test(ManageArtisans::class)
            ->assertSuccessful()
            ->assertActionVisible('create');
    }
}
