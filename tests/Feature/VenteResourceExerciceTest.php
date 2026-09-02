<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Filament\Resources\VenteResource\Pages\ManageVentes;
use Modules\Commerce\Models\Vente;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Socle\Services\ContexteExercice;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `VenteResource` filtre par exercice consulté — même pattern que
 * `DepotResourceExerciceTest`, voir son commentaire. Les ventes sont
 * ici créées directement (hors `ServiceVente`) : ce fichier porte sur
 * la portée de l'écran, pas sur le figement ou le calcul de la
 * commission, déjà couverts ailleurs.
 */
class VenteResourceExerciceTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $actif;

    protected Exercice $precedent;

    protected Artisan $artisan;

    protected Boutique $boutique;

    protected Agent $agent;

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

        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        $this->artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $this->boutique = Boutique::create(['numero' => 'B-12', 'village_id' => $this->village->id]);

        $this->agent = Agent::create([
            'nom' => 'Ngassa',
            'prenom' => 'Alice',
            'fonction' => 'Agent commercial',
            'actif' => true,
            'village_id' => $this->village->id,
        ]);

        Role::create(['name' => Utilisateur::ROLE_SUPER_UTILISATEUR, 'guard_name' => 'web']);

        $utilisateur = Utilisateur::create([
            'name' => 'Alice Ngassa',
            'email' => 'alice@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
            'agent_id' => $this->agent->id,
        ]);
        $utilisateur->assignRole(Utilisateur::ROLE_SUPER_UTILISATEUR);

        $this->actingAs($utilisateur);

        Filament::setCurrentPanel('admin');
    }

    protected function uneVente(Exercice $exercice): Vente
    {
        return Vente::create([
            'date_vente' => $exercice->date_debut,
            'boutique_id' => $this->boutique->id,
            'artisan_id' => $this->artisan->id,
            'exercice_id' => $exercice->id,
            'vendeur_id' => $this->agent->id,
            'montant_total' => 4000,
            'taux_commission' => 15,
            'montant_commission' => 600,
            'part_artisan' => 3400,
        ]);
    }

    public function test_seules_les_ventes_de_l_exercice_consulte_apparaissent(): void
    {
        $venteActive = $this->uneVente($this->actif);
        $ventePrecedente = $this->uneVente($this->precedent);

        Livewire::test(ManageVentes::class)
            ->assertCanSeeTableRecords([$venteActive])
            ->assertCanNotSeeTableRecords([$ventePrecedente]);

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageVentes::class)
            ->assertCanSeeTableRecords([$ventePrecedente])
            ->assertCanNotSeeTableRecords([$venteActive]);
    }

    public function test_annuler_disparait_hors_de_l_actif(): void
    {
        $ventePrecedente = $this->uneVente($this->precedent);

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageVentes::class)
            ->assertTableActionHidden('annuler', $ventePrecedente);
    }
}
