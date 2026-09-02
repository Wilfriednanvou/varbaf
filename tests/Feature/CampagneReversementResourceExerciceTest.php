<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Socle\Services\ContexteExercice;
use Modules\Tresorerie\Filament\Resources\CampagneReversementResource\Pages\ManageCampagnesReversement;
use Modules\Tresorerie\Models\CampagneReversement;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `CampagneReversementResource` filtre par exercice consulté — même
 * pattern que `DepotResourceExerciceTest`, voir son commentaire.
 */
class CampagneReversementResourceExerciceTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $actif;

    protected Exercice $precedent;

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

        $this->actif = $this->actif->fresh();
        $this->actif->activer();

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

    protected function uneCampagne(Exercice $exercice, string $periode, string $dateArrete): CampagneReversement
    {
        return CampagneReversement::create([
            'periode' => $periode,
            'date_arrete' => $dateArrete,
            'exercice_id' => $exercice->id,
        ]);
    }

    public function test_seules_les_campagnes_de_l_exercice_consulte_apparaissent(): void
    {
        $campagnePrecedente = $this->uneCampagne($this->precedent, '2025-12', '2025-12-31');
        $campagneActive = $this->uneCampagne($this->actif, '2026-01', '2026-01-31');

        Livewire::test(ManageCampagnesReversement::class)
            ->assertCanSeeTableRecords([$campagneActive])
            ->assertCanNotSeeTableRecords([$campagnePrecedente]);

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageCampagnesReversement::class)
            ->assertCanSeeTableRecords([$campagnePrecedente])
            ->assertCanNotSeeTableRecords([$campagneActive]);
    }

    public function test_preparer_et_valider_disparaissent_hors_de_l_actif(): void
    {
        $campagnePrecedente = $this->uneCampagne($this->precedent, '2025-12', '2025-12-31');

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageCampagnesReversement::class)
            ->assertTableActionHidden('preparer', $campagnePrecedente)
            ->assertTableActionHidden('valider', $campagnePrecedente);
    }

    public function test_le_bouton_ouvrir_une_campagne_disparait_hors_de_l_actif(): void
    {
        Livewire::test(ManageCampagnesReversement::class)
            ->assertActionVisible('create');

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageCampagnesReversement::class)
            ->assertActionHidden('create');
    }
}
