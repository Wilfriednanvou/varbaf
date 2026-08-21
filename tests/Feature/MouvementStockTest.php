<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\SensMouvementStock;
use Modules\Commerce\Enums\TypeMouvementStock;
use Modules\Commerce\Events\SeuilAlerteFranchi;
use Modules\Commerce\Exceptions\MouvementStockImmuableException;
use Modules\Commerce\Exceptions\StockInsuffisantException;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\MouvementStock;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Services\ServiceMouvementStock;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * Journal de stock : les trois garanties du service.
 *
 * Numérotation sans rupture, impossibilité d'un stock négatif,
 * immuabilité avec correction par contre-passation. Ce sont les mêmes
 * que celles du brouillard de caisse à venir — les éprouver ici, où les
 * enjeux sont moindres, c'est les avoir rôdées avant la trésorerie.
 */
class MouvementStockTest extends TestCase
{
    use RefreshDatabase;

    protected Produit $produit;

    protected ServiceMouvementStock $service;

    protected function setUp(): void
    {
        parent::setUp();

        $village = VillageArtisanal::create([
            'code' => 'VARBAF',
            'nom' => 'Village Artisanal Régional de Bafoussam',
            'categorie' => CategorieVillage::REGIONAL,
            'region' => 'Ouest',
            'actif' => true,
        ]);

        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        $artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $village->id,
        ]);

        $boutique = Boutique::create(['numero' => 'B-12', 'village_id' => $village->id]);
        $categorie = CategorieProduit::create(['code' => 'VAN-PAN', 'libelle' => 'Paniers']);

        $this->produit = Produit::create([
            'designation' => 'Panier tressé en raphia',
            'prix_unitaire' => 8000,
            'seuil_alerte' => 3,
            'categorie_id' => $categorie->id,
            'artisan_id' => $artisan->id,
            'boutique_id' => $boutique->id,
        ]);

        $this->service = app(ServiceMouvementStock::class);
    }

    public function test_un_produit_sans_mouvement_a_un_stock_nul(): void
    {
        $this->assertSame(0, $this->produit->getQuantiteEnStock());
    }

    public function test_le_solde_est_le_cumul_des_entrees_et_des_sorties(): void
    {
        $this->service->deposer($this->produit, 10);
        $this->service->retirer($this->produit, 3, 'Reprise partielle');
        $this->service->deposer($this->produit, 5);

        $this->assertSame(12, $this->produit->getQuantiteEnStock());
    }

    public function test_la_numerotation_est_sequentielle_et_sans_rupture(): void
    {
        $premier = $this->service->deposer($this->produit, 10);
        $deuxieme = $this->service->retirer($this->produit, 2, 'Reprise');
        $troisieme = $this->service->constaterPerte($this->produit, 1, 'Casse au transport');

        $this->assertSame(1, $premier->numero_ordre);
        $this->assertSame(2, $deuxieme->numero_ordre);
        $this->assertSame(3, $troisieme->numero_ordre);
    }

    public function test_le_solde_apres_est_calcule_a_chaque_ligne(): void
    {
        $this->assertSame(10, $this->service->deposer($this->produit, 10)->solde_apres);
        $this->assertSame(6, $this->service->retirer($this->produit, 4, 'Reprise')->solde_apres);
        $this->assertSame(5, $this->service->constaterPerte($this->produit, 1, 'Casse')->solde_apres);
    }

    /**
     * La contrainte demandée : un artisan ne reprend que ce qui reste.
     */
    public function test_un_retrait_ne_peut_pas_depasser_le_solde(): void
    {
        $this->service->deposer($this->produit, 5);

        $this->expectException(StockInsuffisantException::class);

        $this->service->retirer($this->produit, 6, 'Tentative de reprise excessive');
    }

    public function test_une_sortie_sur_un_stock_vide_est_refusee(): void
    {
        $this->expectException(StockInsuffisantException::class);

        $this->service->retirer($this->produit, 1, 'Reprise impossible');
    }

    public function test_un_retrait_egal_au_solde_est_accepte(): void
    {
        $this->service->deposer($this->produit, 5);
        $this->service->retirer($this->produit, 5, 'Reprise totale');

        $this->assertSame(0, $this->produit->getQuantiteEnStock());
    }

    public function test_une_quantite_nulle_ou_negative_est_refusee(): void
    {
        $this->expectException(StockInsuffisantException::class);

        $this->service->deposer($this->produit, 0);
    }

    public function test_un_mouvement_ne_peut_pas_etre_modifie(): void
    {
        $mouvement = $this->service->deposer($this->produit, 10);

        $this->expectException(MouvementStockImmuableException::class);

        $mouvement->update(['quantite' => 99]);
    }

    public function test_un_mouvement_ne_peut_pas_etre_supprime(): void
    {
        $mouvement = $this->service->deposer($this->produit, 10);

        $this->expectException(MouvementStockImmuableException::class);

        $mouvement->delete();
    }

    public function test_la_contrepassation_annule_sans_effacer(): void
    {
        $depot = $this->service->deposer($this->produit, 10);
        $correction = $this->service->contrepasser($depot, 'Erreur de quantité à la saisie');

        $this->assertSame(0, $this->produit->getQuantiteEnStock());
        $this->assertSame(SensMouvementStock::SORTIE, $correction->sens);
        $this->assertSame(TypeMouvementStock::CORRECTION, $correction->type);
        $this->assertSame($depot->id, $correction->mouvement_contrepasse_id);

        // Le mouvement d'origine est toujours là, inchangé.
        $this->assertSame(10, $depot->fresh()->quantite);
        $this->assertSame(2, MouvementStock::count());
    }

    public function test_un_mouvement_ne_se_contrepasse_pas_deux_fois(): void
    {
        $depot = $this->service->deposer($this->produit, 10);
        $this->service->contrepasser($depot, 'Première correction');

        $this->expectException(MouvementStockImmuableException::class);

        $this->service->contrepasser($depot, 'Seconde correction');
    }

    public function test_une_contrepassation_ne_se_contrepasse_pas(): void
    {
        $depot = $this->service->deposer($this->produit, 10);
        $correction = $this->service->contrepasser($depot, 'Correction');

        $this->expectException(MouvementStockImmuableException::class);

        $this->service->contrepasser($correction, 'Correction de la correction');
    }

    /**
     * Le piège signalé : émettre à chaque mouvement tant que le stock
     * est sous le seuil noierait les sections sous les alertes.
     */
    public function test_l_alerte_part_au_franchissement_et_pas_a_chaque_mouvement(): void
    {
        Event::fake([SeuilAlerteFranchi::class]);

        $this->service->deposer($this->produit, 10);
        Event::assertNotDispatched(SeuilAlerteFranchi::class);

        // 10 → 4 : toujours au-dessus du seuil de 3.
        $this->service->retirer($this->produit, 6, 'Vente');
        Event::assertNotDispatched(SeuilAlerteFranchi::class);

        // 4 → 3 : franchissement.
        $this->service->retirer($this->produit, 1, 'Vente');
        Event::assertDispatchedTimes(SeuilAlerteFranchi::class, 1);

        // 3 → 1 : déjà sous le seuil, aucune nouvelle alerte.
        $this->service->retirer($this->produit, 2, 'Vente');
        Event::assertDispatchedTimes(SeuilAlerteFranchi::class, 1);
    }

    public function test_l_alerte_repart_apres_un_reapprovisionnement(): void
    {
        Event::fake([SeuilAlerteFranchi::class]);

        $this->service->deposer($this->produit, 10);
        $this->service->retirer($this->produit, 7, 'Ventes');   // 10 → 3 : franchissement
        Event::assertDispatchedTimes(SeuilAlerteFranchi::class, 1);

        $this->service->deposer($this->produit, 10);            // 3 → 13 : remontée
        $this->service->retirer($this->produit, 10, 'Ventes');  // 13 → 3 : nouveau franchissement
        Event::assertDispatchedTimes(SeuilAlerteFranchi::class, 2);
    }

    public function test_un_produit_non_surveille_ne_declenche_aucune_alerte(): void
    {
        Event::fake([SeuilAlerteFranchi::class]);

        $this->produit->update(['seuil_alerte' => null]);

        $this->service->deposer($this->produit, 10);
        $this->service->retirer($this->produit, 10, 'Reprise totale');

        Event::assertNotDispatched(SeuilAlerteFranchi::class);
    }

    public function test_le_catalogue_sait_lister_les_produits_sous_le_seuil(): void
    {
        $this->assertSame(0, Produit::sousLeSeuil()->count());

        $this->service->deposer($this->produit, 10);
        $this->assertSame(0, Produit::sousLeSeuil()->count());

        $this->service->retirer($this->produit, 8, 'Ventes');
        $this->assertSame(1, Produit::sousLeSeuil()->count());
        $this->assertTrue($this->produit->estSousLeSeuil());
    }

    public function test_la_disponibilite_croise_le_statut_et_le_stock(): void
    {
        $this->service->deposer($this->produit, 5);

        // En stock, mais pas encore validé.
        $this->assertFalse($this->produit->estDisponible(1));

        $this->produit->changerStatut(\Modules\Commerce\Enums\StatutValidationProduit::VALIDE);
        $produit = $this->produit->fresh();

        $this->assertTrue($produit->estDisponible(5));
        $this->assertFalse($produit->estDisponible(6));
    }
}
