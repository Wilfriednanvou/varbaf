<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le sélecteur global d'exercice, dans la barre supérieure du panneau.
 *
 * **Ces tests passent tous par une vraie requête HTTP.** Le sélecteur
 * est rendu par un crochet d'affichage (`FilamentView::registerRenderHook`,
 * branché sur `PanelsRenderHook::TOPBAR_END`), que `Livewire::test()` ne
 * traverse jamais — il monte le composant directement, sans passer par
 * le gabarit de la barre supérieure qui invoque le crochet. C'est la
 * même raison, déjà rencontrée, qui avait caché le bug de la cloche de
 * notifications (dette-technique.md, écart corrigé du 26/08) : « seuls
 * deux tests font une vraie requête HTTP, donc rendent la barre
 * supérieure ». Ce fichier a d'ailleurs débusqué un défaut analogue à
 * l'écriture — `Panel::boot()` publie ses crochets vers `FilamentView`
 * *avant* de démarrer les greffons, ce qu'un `$panel->renderHook()`
 * posé dans un greffon ne peut pas voir.
 */
class SelecteurExerciceTest extends TestCase
{
    use RefreshDatabase;

    protected Exercice $actif;

    protected Exercice $precedent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        Filament::setCurrentPanel('admin');

        $this->actif = Exercice::query()->where('en_cours', true)->firstOrFail();

        // Un second exercice, clôturé, pour que le sélecteur ait
        // effectivement un choix à proposer.
        $anneePrecedente = (string) ((int) $this->actif->libelle - 1);

        $this->precedent = Exercice::create([
            'libelle' => $anneePrecedente,
            'date_debut' => "{$anneePrecedente}-01-01",
            'date_fin' => "{$anneePrecedente}-12-31",
            'village_id' => $this->actif->village_id,
        ]);
        $this->precedent->activer();
        $this->precedent->cloturer();

        // La bascule ci-dessus a désactivé l'exercice de setUp : on le
        // restaure, c'est lui qui doit rester actif pour ces tests.
        // `fresh()` d'abord : sans lui, la copie en mémoire dit encore
        // `en_cours = true` et `activer()` ne verrait rien à écrire.
        $this->actif = $this->actif->fresh();
        $this->actif->activer();
    }

    protected function utilisateurHabilite(): Utilisateur
    {
        $permission = Permission::findOrCreate('lister_exercices', 'web');
        $role = Role::findOrCreate('lecteur_exercices_test', 'web');
        $role->givePermissionTo($permission);

        $utilisateur = Utilisateur::create([
            'name' => 'Habilité',
            'email' => 'habilite-exercice@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
        ]);
        $utilisateur->assignRole($role);

        return $utilisateur;
    }

    protected function utilisateurSansPermission(): Utilisateur
    {
        return Utilisateur::create([
            'name' => 'Sans droit',
            'email' => 'sans-droit-exercice@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
        ]);
    }

    public function test_le_selecteur_apparait_dans_la_barre_superieure(): void
    {
        $this->actingAs($this->utilisateurHabilite());

        $this->get('/admin')
            ->assertSuccessful()
            ->assertSee('wire:name="socle::selecteur-exercice"', false);
    }

    public function test_un_compte_habilite_voit_les_deux_exercices_dans_le_menu(): void
    {
        $this->actingAs($this->utilisateurHabilite());

        $reponse = $this->get('/admin');

        $reponse->assertSee($this->actif->libelle, false);
        $reponse->assertSee($this->precedent->libelle, false);
        $reponse->assertSee('<option', false);
    }

    public function test_un_compte_sans_permission_ne_voit_pas_le_menu_deroulant(): void
    {
        $this->actingAs($this->utilisateurSansPermission());

        $reponse = $this->get('/admin');

        $reponse->assertSuccessful();
        $reponse->assertDontSee('<option', false);
    }
}
