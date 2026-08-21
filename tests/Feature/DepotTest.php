<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\StatutDepot;
use Modules\Commerce\Exceptions\DepotFigeException;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Depot;
use Modules\Commerce\Models\LigneDepot;
use Modules\Commerce\Models\MouvementStock;
use Modules\Commerce\Models\Produit;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * Le dépôt est un document autant qu'un mouvement.
 *
 * Ces tests portent sur ce qui distingue les deux : le figement des
 * lignes à la validation, l'irréversibilité de la décharge, et le fait
 * que rien n'entre en stock tant que le document est en brouillon.
 */
class DepotTest extends TestCase
{
    use RefreshDatabase;

    protected Artisan $artisan;

    protected Boutique $boutique;

    protected Exercice $exercice;

    protected Produit $produit;

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

        $this->exercice = Exercice::create([
            'libelle' => '2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'en_cours' => true,
            'village_id' => $village->id,
        ]);

        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        $this->artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $village->id,
        ]);

        $this->boutique = Boutique::create(['numero' => 'B-12', 'village_id' => $village->id]);
        $categorie = CategorieProduit::create(['code' => 'VAN-PAN', 'libelle' => 'Paniers']);

        $this->produit = Produit::create([
            'designation' => 'Panier tressé en raphia',
            'prix_unitaire' => 8000,
            'categorie_id' => $categorie->id,
            'artisan_id' => $this->artisan->id,
            'boutique_id' => $this->boutique->id,
        ]);
    }

    protected function creerDepot(int $quantite = 10): Depot
    {
        $depot = Depot::create([
            'date_depot' => '2026-03-01',
            'artisan_id' => $this->artisan->id,
            'boutique_id' => $this->boutique->id,
            'exercice_id' => $this->exercice->id,
        ]);

        LigneDepot::create([
            'depot_id' => $depot->id,
            'produit_id' => $this->produit->id,
            'quantite' => $quantite,
        ]);

        return $depot->fresh();
    }

    public function test_le_numero_est_genere_par_annee(): void
    {
        $premier = $this->creerDepot();

        $this->assertMatchesRegularExpression('/^DEP-\d{4}-0001$/', $premier->numero);
    }

    public function test_un_depot_nait_en_brouillon_sans_toucher_au_stock(): void
    {
        $depot = $this->creerDepot();

        $this->assertSame(StatutDepot::BROUILLON, $depot->statut);
        $this->assertSame(0, $this->produit->getQuantiteEnStock());
        $this->assertSame(0, MouvementStock::count());
    }

    public function test_la_ligne_recopie_la_reference_et_la_designation_du_produit(): void
    {
        $depot = $this->creerDepot();
        $ligne = $depot->lignes()->first();

        $this->assertSame($this->produit->reference, $ligne->reference_produit);
        $this->assertSame('Panier tressé en raphia', $ligne->designation);
    }

    public function test_la_validation_fait_entrer_les_articles_en_stock(): void
    {
        $depot = $this->creerDepot(10);

        $depot->valider();

        $this->assertSame(StatutDepot::VALIDE, $depot->fresh()->statut);
        $this->assertSame(10, $this->produit->getQuantiteEnStock());
        $this->assertSame(1, MouvementStock::count());
        $this->assertNotNull($depot->fresh()->date_validation);
    }

    public function test_un_depot_sans_ligne_ne_peut_pas_etre_valide(): void
    {
        $depot = Depot::create([
            'date_depot' => '2026-03-01',
            'artisan_id' => $this->artisan->id,
            'boutique_id' => $this->boutique->id,
            'exercice_id' => $this->exercice->id,
        ]);

        $this->expectException(DepotFigeException::class);

        $depot->valider();
    }

    public function test_un_depot_valide_ne_se_valide_pas_deux_fois(): void
    {
        $depot = $this->creerDepot();
        $depot->valider();

        $this->expectException(DepotFigeException::class);

        $depot->fresh()->valider();
    }

    /**
     * Le cœur de l'affaire : après signature, l'exemplaire papier
     * détenu par l'artisan et celui du village doivent rester
     * identiques.
     */
    public function test_un_depot_valide_ne_peut_plus_etre_modifie(): void
    {
        $depot = $this->creerDepot();
        $depot->valider();

        $this->expectException(DepotFigeException::class);

        $depot->fresh()->update(['observations' => 'Ajout après signature']);
    }

    public function test_les_lignes_d_un_depot_valide_sont_figees(): void
    {
        $depot = $this->creerDepot();
        $depot->valider();

        $ligne = $depot->fresh()->lignes()->first();

        $this->expectException(DepotFigeException::class);

        $ligne->update(['quantite' => 99]);
    }

    public function test_une_ligne_d_un_depot_valide_ne_peut_pas_etre_supprimee(): void
    {
        $depot = $this->creerDepot();
        $depot->valider();

        $ligne = $depot->fresh()->lignes()->first();

        $this->expectException(DepotFigeException::class);

        $ligne->delete();
    }

    public function test_un_depot_valide_ne_peut_plus_etre_supprime(): void
    {
        $depot = $this->creerDepot();
        $depot->valider();

        $this->expectException(DepotFigeException::class);

        $depot->fresh()->delete();
    }

    public function test_un_brouillon_reste_librement_modifiable_et_supprimable(): void
    {
        $depot = $this->creerDepot();

        $depot->update(['observations' => 'Pièces en bon état']);
        $this->assertSame('Pièces en bon état', $depot->fresh()->observations);

        $depot->lignes()->first()->update(['quantite' => 4]);
        $this->assertSame(4, $depot->fresh()->lignes()->first()->quantite);

        $depot->fresh()->delete();
        $this->assertSame(0, Depot::count());
    }

    public function test_le_total_des_articles_additionne_les_lignes(): void
    {
        $depot = $this->creerDepot(7);

        $this->assertSame(7, $depot->nombreArticles());
    }
}
