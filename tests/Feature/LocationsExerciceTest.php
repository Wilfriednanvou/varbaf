<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\AttributionEspace;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Artisanat\Models\EspaceLocatif;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Socle\Services\ContexteExercice;
use Modules\Tresorerie\Filament\Pages\Locations;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `Locations` filtre par exercice consulté — même pattern que
 * `AttributionEspaceResourceExerciceTest`, dont elle reprend le même
 * rattachement (`exercice_id` sur `AttributionEspace`).
 */
class LocationsExerciceTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Artisan $artisan;

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

        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        $this->artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
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

    protected function uneAttribution(Exercice $exercice, string $numeroBoutique): AttributionEspace
    {
        $boutique = Boutique::create(['numero' => $numeroBoutique, 'village_id' => $this->village->id]);
        $espace = EspaceLocatif::create(['boutique_id' => $boutique->id]);

        return AttributionEspace::create([
            'date_debut' => $exercice->date_debut,
            'redevance_convenue' => 15000,
            'artisan_id' => $this->artisan->id,
            'espace_locatif_id' => $espace->id,
            'exercice_id' => $exercice->id,
        ]);
    }

    public function test_seules_les_locations_de_l_exercice_consulte_apparaissent(): void
    {
        $precedent = Exercice::create([
            'libelle' => '2025',
            'date_debut' => '2025-01-01',
            'date_fin' => '2025-12-31',
            'village_id' => $this->village->id,
        ]);
        $precedent->activer();

        $attributionPrecedente = $this->uneAttribution($precedent, 'B01');

        $actif = Exercice::create([
            'libelle' => '2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'village_id' => $this->village->id,
        ]);
        $actif->activer();
        $precedent = $precedent->fresh();
        $precedent->cloturer();

        $attributionActive = $this->uneAttribution($actif, 'B02');

        Livewire::test(Locations::class)
            ->assertCanSeeTableRecords([$attributionActive])
            ->assertCanNotSeeTableRecords([$attributionPrecedente]);

        app(ContexteExercice::class)->definir($precedent);

        Livewire::test(Locations::class)
            ->assertCanSeeTableRecords([$attributionPrecedente])
            ->assertCanNotSeeTableRecords([$attributionActive]);
    }
}
