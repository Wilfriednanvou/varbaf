<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Artisanat\Filament\Resources\AttributionEspaceResource\Pages\ManageAttributionsEspaces;
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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `AttributionEspaceResource` filtre par exercice consulté — même
 * pattern que `DepotResourceExerciceTest`, voir son commentaire.
 *
 * Les deux attributions doivent chacune être créées pendant que leur
 * propre exercice est encore actif : le modèle refuse déjà toute
 * écriture sur un exercice clôturé (`garantirConditionsDAttribution()`,
 * couvert par `AttributionEspaceTest`), donc l'ordre des opérations
 * suit celui d'une vraie clôture.
 */
class AttributionEspaceResourceExerciceTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $precedent;

    protected Exercice $actif;

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

    public function test_seules_les_attributions_de_l_exercice_consulte_apparaissent(): void
    {
        $this->precedent = Exercice::create([
            'libelle' => '2025',
            'date_debut' => '2025-01-01',
            'date_fin' => '2025-12-31',
            'village_id' => $this->village->id,
        ]);
        $this->precedent->activer();

        $attributionPrecedente = $this->uneAttribution($this->precedent, 'B01');

        $this->actif = Exercice::create([
            'libelle' => '2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'village_id' => $this->village->id,
        ]);
        $this->actif->activer();
        $this->precedent = $this->precedent->fresh();
        $this->precedent->cloturer();

        $attributionActive = $this->uneAttribution($this->actif, 'B02');

        Livewire::test(ManageAttributionsEspaces::class)
            ->assertCanSeeTableRecords([$attributionActive])
            ->assertCanNotSeeTableRecords([$attributionPrecedente]);

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageAttributionsEspaces::class)
            ->assertCanSeeTableRecords([$attributionPrecedente])
            ->assertCanNotSeeTableRecords([$attributionActive]);
    }

    public function test_le_bouton_nouvelle_attribution_disparait_hors_de_l_actif(): void
    {
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

        Livewire::test(ManageAttributionsEspaces::class)
            ->assertActionVisible('create');

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageAttributionsEspaces::class)
            ->assertActionHidden('create');
    }
}
