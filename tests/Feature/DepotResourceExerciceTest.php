<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Filament\Resources\DepotResource\Pages\ManageDepots;
use Modules\Commerce\Models\Depot;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Socle\Services\ContexteExercice;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `DepotResource` filtre par exercice consulté et refuse d'écrire hors
 * de l'actif — première application du contexte d'exercice (étape 3) à
 * un écran de saisie réel, au-delà du tableau de bord.
 */
class DepotResourceExerciceTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $actif;

    protected Exercice $precedent;

    protected Artisan $artisan;

    protected Boutique $boutique;

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
        $this->precedent->activer();
        $this->precedent->cloturer();

        // `fresh()` avant de réactiver : sans lui, la copie en mémoire
        // dit encore `en_cours = true` et `activer()` ne verrait rien à
        // écrire (voir le même motif dans SelecteurExerciceTest).
        $this->actif = $this->actif->fresh();
        $this->actif->activer();

        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        $this->artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $this->boutique = Boutique::create(['numero' => 'B-12', 'village_id' => $this->village->id]);

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

    protected function unDepot(Exercice $exercice): Depot
    {
        return Depot::create([
            'date_depot' => $exercice->date_debut,
            'artisan_id' => $this->artisan->id,
            'boutique_id' => $this->boutique->id,
            'exercice_id' => $exercice->id,
        ]);
    }

    public function test_seuls_les_depots_de_l_exercice_consulte_apparaissent(): void
    {
        $depotActif = $this->unDepot($this->actif);
        $depotPrecedent = $this->unDepot($this->precedent);

        Livewire::test(ManageDepots::class)
            ->assertCanSeeTableRecords([$depotActif])
            ->assertCanNotSeeTableRecords([$depotPrecedent]);

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageDepots::class)
            ->assertCanSeeTableRecords([$depotPrecedent])
            ->assertCanNotSeeTableRecords([$depotActif]);
    }

    public function test_le_bouton_nouveau_depot_disparait_hors_de_l_actif(): void
    {
        Livewire::test(ManageDepots::class)
            ->assertActionVisible('create');

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageDepots::class)
            ->assertActionHidden('create');
    }

    public function test_modifier_et_supprimer_disparaissent_hors_de_l_actif(): void
    {
        $depotPrecedent = $this->unDepot($this->precedent);

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageDepots::class)
            ->assertTableActionHidden('edit', $depotPrecedent)
            ->assertTableActionHidden('delete', $depotPrecedent);
    }
}
