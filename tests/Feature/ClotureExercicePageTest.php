<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Artisanat\Enums\StatutParticipationArtisan;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Enums\StatutExercice;
use Modules\Socle\Filament\Pages\ClotureExercice;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Tresorerie\Enums\EtatSectionCaisse;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\SectionCaisse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * L'assistant de clôture — la pièce qui assemble `Exercice::cloturer()`,
 * `VerrousDeCloture` et `RegistreDeReconduction` en une seule opération
 * engagée (étape 5 du plan multi-exercice).
 */
class ClotureExercicePageTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $exercice;

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

        $this->exercice = Exercice::create([
            'libelle' => '2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'village_id' => $this->village->id,
        ]);
        $this->exercice->activer();

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

    protected function nouvelExercice(): array
    {
        return [
            'nouveauLibelle' => '2027',
            'nouveauDateDebut' => '2027-01-01',
            'nouveauDateFin' => '2027-12-31',
        ];
    }

    public function test_un_compte_sans_permission_n_atteint_pas_la_page(): void
    {
        $sansRole = Utilisateur::create([
            'name' => 'Sans droit',
            'email' => 'sans-droit@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
        ]);
        $this->actingAs($sansRole);

        $this->assertFalse(ClotureExercice::canAccess());
    }

    public function test_le_montage_preselectionne_tous_les_artisans_actifs(): void
    {
        $artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $this->corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        Livewire::test(ClotureExercice::class)
            ->assertSet('selections.artisans', [$artisan->id]);
    }

    public function test_confirmer_est_refuse_si_une_section_de_caisse_reste_ouverte(): void
    {
        $caisse = Caisse::create([
            'code' => 'CAISSE-PRINCIPALE',
            'libelle' => 'Caisse principale',
            'etat' => 'ACTIVE',
            'village_id' => $this->village->id,
        ]);

        SectionCaisse::create([
            'caisse_id' => $caisse->id,
            'libelle' => 'Section ouverte',
            'date_ouverture' => now(),
            'solde_ouverture' => 0,
            'etat' => EtatSectionCaisse::OUVERTE,
            'ouverte_par' => auth()->id(),
            'village_id' => $this->village->id,
            'exercice_id' => $this->exercice->id,
        ]);

        Livewire::test(ClotureExercice::class)
            ->set($this->nouvelExercice())
            ->call('confirmer');

        $this->assertFalse($this->exercice->fresh()->cloture);
        $this->assertNull(Exercice::where('libelle', '2027')->first());
    }

    public function test_confirmer_cloture_l_ancien_active_le_nouveau_et_reconduit_la_selection(): void
    {
        $artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $this->corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        Livewire::test(ClotureExercice::class)
            ->set($this->nouvelExercice())
            ->call('confirmer');

        $ancien = $this->exercice->fresh();
        $this->assertTrue($ancien->cloture);
        $this->assertSame(StatutExercice::CLOTURE, $ancien->statut);

        $nouveau = Exercice::where('libelle', '2027')->firstOrFail();
        $this->assertSame(StatutExercice::ACTIF, $nouveau->statut);

        $participation = $artisan->participationsExercices()->where('exercice_id', $nouveau->id)->firstOrFail();
        $this->assertSame(StatutParticipationArtisan::RECONDUIT, $participation->statut);
    }

    public function test_un_artisan_desselectionne_n_est_pas_reconduit(): void
    {
        $reconduit = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $this->corpsMetier->id,
            'village_id' => $this->village->id,
        ]);
        $ecarte = Artisan::create([
            'nom' => 'Fokou',
            'corps_metier_id' => $this->corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        Livewire::test(ClotureExercice::class)
            ->set($this->nouvelExercice())
            ->set('selections.artisans', [$reconduit->id])
            ->call('confirmer');

        $nouveau = Exercice::where('libelle', '2027')->firstOrFail();

        $this->assertTrue(
            $reconduit->participationsExercices()->where('exercice_id', $nouveau->id)->exists(),
        );
        $this->assertFalse(
            $ecarte->participationsExercices()->where('exercice_id', $nouveau->id)->exists(),
        );
    }

    public function test_un_exercice_en_preparation_du_meme_libelle_est_reutilise_sans_doublon(): void
    {
        Exercice::create([
            'libelle' => '2027',
            'date_debut' => '2027-01-01',
            'date_fin' => '2027-12-31',
            'village_id' => $this->village->id,
        ]);

        Livewire::test(ClotureExercice::class)
            ->set($this->nouvelExercice())
            ->call('confirmer');

        $this->assertSame(1, Exercice::where('libelle', '2027')->count());
    }
}
