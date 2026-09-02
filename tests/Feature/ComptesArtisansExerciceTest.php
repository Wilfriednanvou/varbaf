<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Artisanat\Enums\StatutParticipationArtisan;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Socle\Services\ContexteExercice;
use Modules\Tresorerie\Filament\Pages\ComptesArtisans;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `ComptesArtisans` restreint la liste aux artisans participant à
 * l'exercice consulté, sans toucher au calcul du solde dû (RG-15,
 * cumulé — voir le commentaire de `comptesFiltres()`).
 */
class ComptesArtisansExerciceTest extends TestCase
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

    public function test_seuls_les_artisans_de_l_exercice_consulte_figurent_dans_les_totaux(): void
    {
        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        $artisanActif = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $this->precedent->activer();
        $artisanPrecedent = Artisan::create([
            'nom' => 'Tchouta',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);
        $this->actif = $this->actif->fresh();
        $this->actif->activer();

        $page = Livewire::test(ComptesArtisans::class);
        $this->assertSame(1, $page->instance()->totaux()['artisans']);

        app(ContexteExercice::class)->definir($this->precedent);

        $page = Livewire::test(ComptesArtisans::class);
        $this->assertSame(1, $page->instance()->totaux()['artisans']);
    }

    public function test_un_artisan_desactive_n_est_plus_compte(): void
    {
        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        $artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $artisan->participationsExercices()
            ->where('exercice_id', $this->actif->id)
            ->update(['statut' => StatutParticipationArtisan::DESACTIVE->value]);

        $page = Livewire::test(ComptesArtisans::class);

        $this->assertSame(0, $page->instance()->totaux()['artisans']);
    }
}
