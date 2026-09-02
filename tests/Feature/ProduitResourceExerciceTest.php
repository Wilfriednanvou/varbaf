<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\StatutParticipationProduit;
use Modules\Commerce\Filament\Resources\ProduitResource\Pages\ManageProduits;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Produit;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Socle\Services\ContexteExercice;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `ProduitResource` filtre par participation à l'exercice consulté,
 * via `produit_exercices` — même principe et même motif que
 * `ArtisanResourceExerciceTest`, voir son commentaire.
 */
class ProduitResourceExerciceTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $actif;

    protected Exercice $precedent;

    protected Artisan $artisan;

    protected Boutique $boutique;

    protected CategorieProduit $categorie;

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

        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        $this->artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $this->boutique = Boutique::create(['numero' => 'B-12', 'village_id' => $this->village->id]);
        $this->categorie = CategorieProduit::create(['code' => 'VAN-PAN', 'libelle' => 'Paniers']);

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

    protected function unProduit(string $designation): Produit
    {
        return Produit::create([
            'designation' => $designation,
            'prix_unitaire' => 4000,
            'categorie_id' => $this->categorie->id,
            'artisan_id' => $this->artisan->id,
            'boutique_id' => $this->boutique->id,
        ]);
    }

    public function test_un_produit_cree_pendant_l_exercice_actif_obtient_une_participation_active(): void
    {
        $produit = $this->unProduit('Panier tressé');

        $participation = $produit->participationsExercices()->firstOrFail();

        $this->assertSame($this->actif->id, $participation->exercice_id);
        $this->assertSame(StatutParticipationProduit::ACTIF, $participation->statut);
    }

    public function test_seuls_les_produits_participant_a_l_exercice_consulte_apparaissent(): void
    {
        $produitActif = $this->unProduit('Panier tressé');

        $this->precedent->activer();
        $produitPrecedent = $this->unProduit('Chapeau en raphia');
        $this->actif = $this->actif->fresh();
        $this->actif->activer();

        Livewire::test(ManageProduits::class)
            ->assertCanSeeTableRecords([$produitActif])
            ->assertCanNotSeeTableRecords([$produitPrecedent]);

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageProduits::class)
            ->assertCanSeeTableRecords([$produitPrecedent])
            ->assertCanNotSeeTableRecords([$produitActif]);
    }

    public function test_sans_aucune_participation_bootstrappee_la_liste_n_est_pas_filtree(): void
    {
        $produit = $this->unProduit('Panier tressé');

        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(ManageProduits::class)
            ->assertCanSeeTableRecords([$produit]);
    }
}
