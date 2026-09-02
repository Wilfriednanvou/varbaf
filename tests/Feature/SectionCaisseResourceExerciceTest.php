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
use Modules\Tresorerie\Filament\Resources\SectionCaisseResource\Pages\ManageSectionsCaisse;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\SectionCaisse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `SectionCaisseResource` filtre par exercice consulté — même pattern
 * que `DepotResourceExerciceTest`, voir son commentaire.
 */
class SectionCaisseResourceExerciceTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $actif;

    protected Exercice $precedent;

    protected Caisse $caisse;

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

        $this->caisse = Caisse::create([
            'code' => 'CAISSE-PRINCIPALE',
            'libelle' => 'Caisse principale',
            'etat' => 'ACTIVE',
            'village_id' => $this->village->id,
        ]);

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

    protected function uneSection(Exercice $exercice, string $etat): SectionCaisse
    {
        return SectionCaisse::create([
            'caisse_id' => $this->caisse->id,
            'libelle' => "Section {$exercice->libelle}",
            'date_ouverture' => $exercice->date_debut,
            'solde_ouverture' => 0,
            'etat' => $etat,
            'ouverte_par' => auth()->id(),
            'village_id' => $this->village->id,
            'exercice_id' => $exercice->id,
        ]);
    }

    public function test_seules_les_sections_de_l_exercice_consulte_apparaissent(): void
    {
        $sectionPrecedente = $this->uneSection($this->precedent, 'CLOTUREE');
        $sectionActive = $this->uneSection($this->actif, 'OUVERTE');

        Livewire::test(ManageSectionsCaisse::class)
            ->assertCanSeeTableRecords([$sectionActive])
            ->assertCanNotSeeTableRecords([$sectionPrecedente]);

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageSectionsCaisse::class)
            ->assertCanSeeTableRecords([$sectionPrecedente])
            ->assertCanNotSeeTableRecords([$sectionActive]);
    }

    public function test_le_bouton_ouvrir_une_section_disparait_hors_de_l_actif(): void
    {
        Livewire::test(ManageSectionsCaisse::class)
            ->assertActionVisible('create');

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageSectionsCaisse::class)
            ->assertActionHidden('create');
    }
}
