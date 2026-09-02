<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Artisanat\Enums\StatutParticipationArtisan;
use Modules\Artisanat\Filament\Resources\ArtisanResource\Pages\ManageArtisans;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Socle\Services\ContexteExercice;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `ArtisanResource` filtre par participation à l'exercice consulté,
 * via `artisan_exercices` — pas par une colonne `exercice_id` directe,
 * puisque l'artisan est une identité permanente (règle 4).
 *
 * Deux différences avec les ressources déjà bornées (Depot, Vente…) :
 * la portée passe par `whereHas()` plutôt que `where()`, et rien
 * n'empêche de créer ou modifier un artisan en consultant un exercice
 * qui n'est pas l'actif — seule sa *participation* est datée, jamais
 * son identité.
 */
class ArtisanResourceExerciceTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $actif;

    protected Exercice $precedent;

    protected CorpsMetier $corpsMetier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->village = VillageArtisanal::create([
            'code' => 'VARBAF',
            'nom' => 'Village Artisanal Régional de Bafoussam',
            'categorie' => CategorieVillage::REGIONAL,
            'region' => 'Ouest',
            'actif' => true,
        ]);

        $this->actif = Exercice::create([
            'libelle' => '2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'village_id' => $this->village->id,
        ]);
        $this->actif->activer();

        $this->precedent = Exercice::create([
            'libelle' => '2025',
            'date_debut' => '2025-01-01',
            'date_fin' => '2025-12-31',
            'village_id' => $this->village->id,
        ]);

        $this->corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        Role::create(['name' => Utilisateur::ROLE_SUPER_UTILISATEUR, 'guard_name' => 'web']);

        $utilisateur = Utilisateur::create([
            'name' => 'Alice Ngassa',
            'email' => 'alice@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
        ]);
        $utilisateur->assignRole(Utilisateur::ROLE_SUPER_UTILISATEUR);

        $this->actingAs($utilisateur);

        Filament::setCurrentPanel('admin');
    }

    public function test_un_artisan_cree_pendant_l_exercice_actif_obtient_une_participation_active(): void
    {
        $artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $this->corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $participation = $artisan->participationsExercices()->firstOrFail();

        $this->assertSame($this->actif->id, $participation->exercice_id);
        $this->assertSame(StatutParticipationArtisan::ACTIF, $participation->statut);
    }

    public function test_seuls_les_artisans_participant_a_l_exercice_consulte_apparaissent(): void
    {
        $artisanActif = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $this->corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        // Créé pendant que 2025 était l'actif, pour que sa seule
        // participation automatique vise 2025 — et non les deux, ce
        // qu'obtiendrait une participation 2025 ajoutée à la main sans
        // désactiver celle que le crochet aurait posée sur 2026.
        $this->precedent->activer();
        $artisanPrecedent = Artisan::create([
            'nom' => 'Tchouta',
            'corps_metier_id' => $this->corpsMetier->id,
            'village_id' => $this->village->id,
        ]);
        $this->actif = $this->actif->fresh();
        $this->actif->activer();

        Livewire::test(ManageArtisans::class)
            ->assertCanSeeTableRecords([$artisanActif])
            ->assertCanNotSeeTableRecords([$artisanPrecedent]);

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageArtisans::class)
            ->assertCanSeeTableRecords([$artisanPrecedent])
            ->assertCanNotSeeTableRecords([$artisanActif]);
    }

    public function test_un_artisan_desactive_pour_l_exercice_consulte_n_apparait_pas(): void
    {
        $artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $this->corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $artisan->participationsExercices()
            ->where('exercice_id', $this->actif->id)
            ->update(['statut' => StatutParticipationArtisan::DESACTIVE->value]);

        Livewire::test(ManageArtisans::class)
            ->assertCanNotSeeTableRecords([$artisan]);
    }

    public function test_sans_aucune_participation_bootstrappee_la_liste_n_est_pas_filtree(): void
    {
        // Aucun artisan n'existe encore pour l'exercice 2025 : la table
        // de jonction est vide pour lui. Le repli doit montrer tout,
        // comme avant l'introduction de cette table — pas une liste
        // vide qui laisserait croire qu'aucun artisan n'existe.
        $artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $this->corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageArtisans::class)
            ->assertCanSeeTableRecords([$artisan]);
    }
}
