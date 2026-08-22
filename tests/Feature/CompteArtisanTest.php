<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Models\TauxCommission;
use Modules\Commerce\Services\ServiceMouvementStock;
use Modules\Commerce\Services\ServiceVente;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\SectionCaisse;
use Modules\Tresorerie\Services\ServiceCompteArtisan;
use Tests\TestCase;

/**
 * Situation financière d'un artisan (RG-15).
 *
 * Le solde dû n'est jamais stocké : `ServiceCompteArtisan` le recalcule
 * à chaque appel depuis les ventes validées. Annuler une vente doit
 * donc immédiatement faire baisser le solde, sans étape de recalcul
 * séparée — c'est exactement ce que ce test vérifie.
 */
class CompteArtisanTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;
    protected Artisan $artisan;
    protected Produit $produit;
    protected ServiceVente $venteService;
    protected ServiceCompteArtisan $compteService;

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

        Exercice::create([
            'libelle' => '2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'en_cours' => true,
            'village_id' => $this->village->id,
        ]);

        TauxCommission::create([
            'taux' => 15.00,
            'date_effet' => '2026-01-01',
            'reference_decision' => 'Note de service de test',
            'village_id' => $this->village->id,
        ]);

        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        $this->artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $boutique = Boutique::create(['numero' => 'B-12', 'village_id' => $this->village->id]);
        $categorie = CategorieProduit::create(['code' => 'VAN-PAN', 'libelle' => 'Paniers']);

        $this->produit = Produit::create([
            'designation' => 'Panier tressé',
            'prix_unitaire' => 4000,
            'categorie_id' => $categorie->id,
            'artisan_id' => $this->artisan->id,
            'boutique_id' => $boutique->id,
        ]);
        $this->produit->changerStatut(StatutValidationProduit::VALIDE);
        app(ServiceMouvementStock::class)->deposer($this->produit->fresh(), 10);
        $this->produit = $this->produit->fresh();

        $agent = Agent::create([
            'nom' => 'Ngassa',
            'prenom' => 'Alice',
            'fonction' => 'Agent commercial',
            'actif' => true,
            'village_id' => $this->village->id,
        ]);

        $vendeur = Utilisateur::create([
            'name' => 'Alice Ngassa',
            'email' => 'alice@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
            'agent_id' => $agent->id,
        ]);

        $this->actingAs($vendeur);

        $caisse = Caisse::create([
            'code' => 'CAISSE-TEST',
            'libelle' => 'Caisse de test',
            'etat' => 'ACTIVE',
            'village_id' => $this->village->id,
        ]);

        SectionCaisse::create([
            'caisse_id' => $caisse->id,
            'libelle' => 'Section test',
            'date_ouverture' => now(),
            'solde_ouverture' => 0,
            'etat' => 'OUVERTE',
            'ouverte_par' => $vendeur->id,
            'village_id' => $this->village->id,
            'exercice_id' => Exercice::query()->where('en_cours', true)->value('id'),
        ]);

        $this->venteService = app(ServiceVente::class);
        $this->compteService = app(ServiceCompteArtisan::class);
    }

    public function test_le_solde_du_est_la_somme_des_parts_artisan_des_ventes_validees(): void
    {
        // 4000 F à 15 % : commission 600, part artisan 3400.
        $this->venteService->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 1],
        ]);

        $this->assertSame(3400, $this->compteService->totalVendu($this->artisan));
        $this->assertSame(0, $this->compteService->totalReverse($this->artisan));
        $this->assertSame(3400, $this->compteService->soldeDu($this->artisan));
    }

    public function test_le_solde_additionne_plusieurs_ventes(): void
    {
        $this->venteService->enregistrer([['produit_id' => $this->produit->id, 'quantite' => 1]]);
        $this->venteService->enregistrer([['produit_id' => $this->produit->id, 'quantite' => 2]]);

        // 3400 (une unité) + 6800 (deux unités) = 10200.
        $this->assertSame(10200, $this->compteService->soldeDu($this->artisan));
    }

    public function test_le_solde_est_recalcule_apres_annulation_d_une_vente(): void
    {
        $premiere = $this->venteService->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 1],
        ]);
        $this->venteService->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 1],
        ]);

        $this->assertSame(6800, $this->compteService->soldeDu($this->artisan));

        $this->venteService->annuler($premiere, 'Client revenu sur son achat');

        // La vente annulée sort du calcul : il ne reste que la seconde.
        $this->assertSame(3400, $this->compteService->soldeDu($this->artisan));
    }

    public function test_un_artisan_sans_vente_a_un_solde_nul(): void
    {
        $autreArtisan = Artisan::create([
            'nom' => 'Fotso',
            'corps_metier_id' => $this->artisan->corps_metier_id,
            'village_id' => $this->village->id,
        ]);

        $this->assertSame(0, $this->compteService->soldeDu($autreArtisan));
    }
}
