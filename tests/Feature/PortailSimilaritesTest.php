<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Services\ServiceMouvementStock;
use Modules\Pilotage\Services\ServiceIndexationLexicale;
use Modules\Portail\Models\PublicationProduit;
use Modules\Portail\Services\ServiceRecommandationPortail;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * Le bloc « produits similaires » de la fiche publique.
 *
 * Ce test ne réexamine pas les règles de publication : `PortailPublicationTest`
 * s'en charge. Il vérifie que la recommandation **leur est soumise** —
 * c'est-à-dire qu'un produit qu'un visiteur ne peut pas voir ailleurs sur
 * le portail ne réapparaît pas ici par la bande.
 *
 * Il vérifie aussi le cas inverse, celui qu'on casse sans y penser : un
 * produit épuisé reste suggéré, parce que le portail l'annonce « sur
 * commande » et non « indisponible ».
 */
class PortailSimilaritesTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected CorpsMetier $vannerie;

    protected CategorieProduit $paniers;

    protected Boutique $boutique;

    protected Artisan $autorise;

    protected Artisan $sansAutorisation;

    protected ServiceRecommandationPortail $recommandation;

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

        $this->vannerie = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);
        $this->paniers = CategorieProduit::create(['code' => 'VAN-PAN', 'libelle' => 'Paniers']);
        $this->boutique = Boutique::create(['numero' => 'B-12', 'village_id' => $this->village->id]);

        $this->autorise = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $this->vannerie->id,
            'village_id' => $this->village->id,
            'autorisation_publication' => true,
        ]);

        $this->sansAutorisation = Artisan::create([
            'nom' => 'Fotso',
            'corps_metier_id' => $this->vannerie->id,
            'village_id' => $this->village->id,
            'autorisation_publication' => false,
        ]);

        $this->recommandation = app(ServiceRecommandationPortail::class);
    }

    protected function creerProduit(
        string $designation,
        ?Artisan $artisan = null,
        int $stock = 5,
        StatutValidationProduit $statut = StatutValidationProduit::EXPOSE,
    ): Produit {
        $produit = Produit::create([
            'designation' => $designation,
            'prix_unitaire' => 4000,
            'categorie_id' => $this->paniers->id,
            'artisan_id' => ($artisan ?? $this->autorise)->id,
            'boutique_id' => $this->boutique->id,
        ]);

        $produit->changerStatut(StatutValidationProduit::VALIDE);

        if ($statut === StatutValidationProduit::EXPOSE) {
            $produit->changerStatut(StatutValidationProduit::EXPOSE);
        }

        if ($stock > 0) {
            app(ServiceMouvementStock::class)->deposer($produit->fresh(), $stock);
        }

        return $produit->fresh();
    }

    protected function publier(Produit $produit, bool $publie = true): PublicationProduit
    {
        return PublicationProduit::create([
            'produit_id' => $produit->id,
            'publie' => $publie,
        ]);
    }

    protected function indexer(): void
    {
        app(ServiceIndexationLexicale::class)->reindexer();
    }

    // =================================================================
    //  CE QUI EST MONTRÉ
    // =================================================================

    public function test_un_produit_publie_et_proche_est_suggere(): void
    {
        $reference = $this->publier($this->creerProduit('Panier tressé'));
        $voisin = $this->creerProduit('Panier de marché');
        $this->publier($voisin);

        $this->indexer();

        $similaires = $this->recommandation->produitsSimilaires($reference);

        $this->assertSame([$voisin->id], $similaires->pluck('produit_id')->all());
    }

    public function test_la_fiche_courante_ne_se_suggere_pas_elle_meme(): void
    {
        $reference = $this->publier($this->creerProduit('Panier tressé'));
        $this->publier($this->creerProduit('Panier de marché'));

        $this->indexer();

        $this->assertNotContains(
            $reference->produit_id,
            $this->recommandation->produitsSimilaires($reference)->pluck('produit_id')->all(),
        );
    }

    public function test_le_moteur_qui_repond_est_nomme(): void
    {
        $this->publier($this->creerProduit('Panier tressé'));
        $this->indexer();

        $this->assertSame('Similarité lexicale (TF-IDF)', $this->recommandation->nomDuMoteur());
    }

    // =================================================================
    //  CE QUI NE L'EST PAS — LES RÈGLES DE PUBLICATION S'APPLIQUENT
    // =================================================================

    public function test_un_produit_non_publie_n_est_pas_suggere(): void
    {
        $reference = $this->publier($this->creerProduit('Panier tressé'));

        // Fiche existante mais hors ligne : le catalogue l'ignore, la
        // recommandation doit l'ignorer aussi.
        $horsLigne = $this->creerProduit('Panier de marché');
        $this->publier($horsLigne, publie: false);

        $this->indexer();

        $this->assertTrue($this->recommandation->produitsSimilaires($reference)->isEmpty());
    }

    public function test_un_produit_sans_fiche_de_publication_n_est_pas_suggere(): void
    {
        $reference = $this->publier($this->creerProduit('Panier tressé'));

        // Aucune fiche du tout : le produit existe au catalogue interne,
        // pas sur le portail.
        $this->creerProduit('Panier de marché');

        $this->indexer();

        $this->assertTrue($this->recommandation->produitsSimilaires($reference)->isEmpty());
    }

    public function test_un_produit_dont_l_artisan_n_autorise_pas_la_publication_n_est_pas_suggere(): void
    {
        $reference = $this->publier($this->creerProduit('Panier tressé'));

        $duNonAutorise = $this->creerProduit('Panier de marché', $this->sansAutorisation);

        // La fiche est créée alors que l'artisan consent encore : c'est
        // le retrait du consentement qui doit faire disparaître le
        // produit, sans qu'on repasse sur la publication.
        $this->sansAutorisation->update(['autorisation_publication' => true]);
        $this->publier($duNonAutorise);
        $this->sansAutorisation->update(['autorisation_publication' => false]);

        $this->indexer();

        $this->assertTrue(
            $this->recommandation->produitsSimilaires($reference)->isEmpty(),
            'Retirer le consentement retire aussi les suggestions.',
        );
    }

    public function test_un_produit_retire_de_la_vitrine_n_est_plus_suggere(): void
    {
        $reference = $this->publier($this->creerProduit('Panier tressé'));
        $voisin = $this->creerProduit('Panier de marché');
        $publication = $this->publier($voisin);

        $this->indexer();

        $this->assertCount(1, $this->recommandation->produitsSimilaires($reference));

        $publication->update(['publie' => false]);

        $this->assertTrue(
            $this->recommandation->produitsSimilaires($reference)->isEmpty(),
            'Le retrait est immédiat : la visibilité se lit à chaque requête, pas à l\'indexation.',
        );
    }

    // =================================================================
    //  LE STOCK : « SUR COMMANDE », PAS « MASQUÉ »
    // =================================================================

    public function test_un_produit_epuise_reste_suggere(): void
    {
        $reference = $this->publier($this->creerProduit('Panier tressé'));

        $epuise = $this->creerProduit('Panier de marché', stock: 0);
        $this->publier($epuise);

        $this->indexer();

        $this->assertSame(
            [$epuise->id],
            $this->recommandation->produitsSimilaires($reference)->pluck('produit_id')->all(),
            'Sur le portail, un produit épuisé est annoncé « sur commande » — le masquer contredirait le catalogue.',
        );
    }

    // =================================================================
    //  LA PAGE
    // =================================================================

    public function test_la_fiche_publique_affiche_le_bloc_des_produits_similaires(): void
    {
        $reference = $this->publier($this->creerProduit('Panier tressé'));
        
        $autreArtisan = Artisan::create([
            'nom' => 'Njoya',
            'corps_metier_id' => $this->vannerie->id,
            'village_id' => $this->village->id,
            'autorisation_publication' => true,
        ]);
        
        $voisin = $this->creerProduit('Panier de marché', $autreArtisan);
        $this->publier($voisin);

        $this->indexer();

        $this->get(route('portail.produit', $reference->produit->reference))
            ->assertOk()
            ->assertSee('Dans le même esprit')
            ->assertSee('Panier de marché');
    }

    public function test_la_fiche_publique_n_affiche_aucun_bloc_sans_suggestion(): void
    {
        $reference = $this->publier($this->creerProduit('Panier tressé'));

        $this->indexer();

        $this->get(route('portail.produit', $reference->produit->reference))
            ->assertOk()
            ->assertDontSee('Dans le même esprit');
    }

    public function test_la_fiche_publique_reste_lisible_sans_index(): void
    {
        $reference = $this->publier($this->creerProduit('Panier tressé'));
        $this->publier($this->creerProduit('Panier de marché'));

        // Volontairement pas d'indexation : une fiche produit doit
        // s'afficher même si personne n'a jamais lancé varbaf:indexer.
        $this->get(route('portail.produit', $reference->produit->reference))
            ->assertOk()
            ->assertDontSee('Dans le même esprit');
    }

    public function test_aucune_quantite_en_stock_n_apparait_dans_le_bloc(): void
    {
        $reference = $this->publier($this->creerProduit('Panier tressé', stock: 5));
        $voisin = $this->creerProduit('Panier de marché', stock: 17);
        $this->publier($voisin);

        $this->indexer();

        $this->get(route('portail.produit', $reference->produit->reference))
            ->assertOk()
            ->assertDontSee('17');
    }
}
