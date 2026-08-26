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
use Modules\Pilotage\Recommandation\CriteresDeVoisinage;
use Modules\Pilotage\Recommandation\ProduitVoisin;
use Modules\Pilotage\Services\ServiceAnalyseCatalogue;
use Modules\Pilotage\Services\ServiceIndexationLexicale;
use Modules\Pilotage\Services\ServiceRecommandationProduit;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * Éprouve la recommandation de produits par similarité lexicale et les
 * deux lectures du catalogue qui en découlent.
 *
 * Le catalogue est fabriqué ici : il faut des ressemblances connues à
 * l'avance pour vérifier qu'un produit de même catégorie remonte avant
 * un produit d'un autre rayon. Fixtures de test, non données d'amorçage.
 */
class RecommandationProduitTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Boutique $boutique;

    protected CorpsMetier $vannerie;

    protected CorpsMetier $sculpture;

    protected CategorieProduit $paniers;

    protected CategorieProduit $masques;

    protected Artisan $vannier;

    protected Artisan $sculpteur;

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

        $this->vannerie = CorpsMetier::create([
            'code' => 'VAN',
            'libelle' => 'Vannerie',
            'description' => 'Tressage de fibres végétales',
        ]);

        $this->sculpture = CorpsMetier::create([
            'code' => 'SCU',
            'libelle' => 'Sculpture',
            'description' => 'Taille du bois et de la pierre',
        ]);

        $this->vannier = Artisan::create([
            'nom' => 'Kamdem',
            'prenom' => 'Jean',
            'corps_metier_id' => $this->vannerie->id,
            'village_id' => $this->village->id,
            'autorisation_publication' => true,
        ]);

        $this->sculpteur = Artisan::create([
            'nom' => 'Tchinda',
            'prenom' => 'Paul',
            'corps_metier_id' => $this->sculpture->id,
            'village_id' => $this->village->id,
            'autorisation_publication' => true,
        ]);

        $this->boutique = Boutique::create(['numero' => 'B-04', 'village_id' => $this->village->id]);

        $this->paniers = CategorieProduit::create(['code' => 'PAN', 'libelle' => 'Paniers']);
        $this->masques = CategorieProduit::create(['code' => 'MAS', 'libelle' => 'Masques']);
    }

    // =================================================================
    //  FIXTURES
    // =================================================================

    protected function produit(
        string $designation,
        ?CategorieProduit $categorie = null,
        ?Artisan $artisan = null,
        ?string $description = null,
        StatutValidationProduit $statut = StatutValidationProduit::EXPOSE,
        bool $actif = true,
    ): Produit {
        $produit = Produit::create([
            'designation' => $designation,
            'description' => $description,
            'prix_unitaire' => 10000,
            'categorie_id' => ($categorie ?? $this->paniers)->id,
            'artisan_id' => ($artisan ?? $this->vannier)->id,
            'boutique_id' => $this->boutique->id,
        ]);

        // Le statut se franchit par étapes (règle 14) : on suit le
        // chemin normal plutôt que d'écrire la colonne à la main.
        if ($statut !== StatutValidationProduit::SOUMIS) {
            $produit->changerStatut(StatutValidationProduit::VALIDE);

            if ($statut === StatutValidationProduit::EXPOSE) {
                $produit->changerStatut(StatutValidationProduit::EXPOSE);
            }
        }

        if (! $actif) {
            $produit->update(['actif' => false]);
        }

        return $produit->fresh();
    }

    protected function approvisionner(Produit $produit, int $quantite = 5): void
    {
        app(ServiceMouvementStock::class)->deposer($produit->fresh(), $quantite);
    }

    protected function indexer(): void
    {
        app(ServiceIndexationLexicale::class)->reindexer();
    }

    /**
     * @return \Illuminate\Support\Collection<int, ProduitVoisin>
     */
    protected function voisinsDe(Produit $produit, ?CriteresDeVoisinage $criteres = null)
    {
        return app(ServiceRecommandationProduit::class)->voisins($produit, $criteres);
    }

    protected function criteres(
        ?int $limite = null,
        ?float $seuil = null,
        ?float $bonus = null,
        ?bool $stock = null,
    ): CriteresDeVoisinage {
        return CriteresDeVoisinage::depuisLaConfiguration(
            limite: $limite,
            seuil: $seuil,
            bonusMemeArtisan: $bonus,
            exclureStockEpuise: $stock,
        );
    }

    // =================================================================
    //  PERTINENCE
    // =================================================================

    public function test_un_produit_de_la_meme_categorie_remonte_avant_un_autre_rayon(): void
    {
        $reference = $this->produit('Panier tressé', $this->paniers);

        // Désignation identique de part et d'autre : seule la catégorie
        // les sépare, donc seule elle peut expliquer l'écart de rang.
        $memeRayon = $this->produit('Panier rond', $this->paniers);
        $autreRayon = $this->produit('Panier rond', $this->masques);

        $this->indexer();

        $voisins = $this->voisinsDe($reference, $this->criteres(seuil: 0.0))->keyBy('produitId');

        $this->assertGreaterThan(
            $voisins[$autreRayon->id]->similarite,
            $voisins[$memeRayon->id]->similarite,
            'À désignation égale, partager la catégorie rapproche davantage.',
        );
    }

    public function test_un_produit_sans_aucun_mot_commun_n_apparait_pas_du_tout(): void
    {
        $reference = $this->produit('Panier tressé', $this->paniers, $this->vannier);
        $etranger = $this->produit('Masque cérémoniel', $this->masques, $this->sculpteur);

        $this->indexer();

        $this->assertNotContains(
            $etranger->id,
            $this->voisinsDe($reference, $this->criteres(seuil: 0.0))->pluck('produitId')->all(),
            'Sans terme partagé, la paire n\'existe pas : rien à classer.',
        );
    }

    public function test_le_meme_corps_de_metier_rapproche_deux_produits_identiques_par_ailleurs(): void
    {
        $reference = $this->produit('Corbeille ovale', $this->paniers, $this->vannier);

        // Un second vannier : même métier que la référence, mais pas le
        // même artisan. C'est ce qui permet de mesurer le métier sans
        // mesurer, du même coup, la majoration du même artisan.
        $autreVannier = Artisan::create([
            'nom' => 'Noubissi',
            'corps_metier_id' => $this->vannerie->id,
            'village_id' => $this->village->id,
        ]);

        // Même désignation, même catégorie : seul le corps de métier de
        // l'artisan distingue les deux candidats.
        $memeMetier = $this->produit('Tabouret bas', $this->paniers, $autreVannier);
        $autreMetier = $this->produit('Tabouret bas', $this->paniers, $this->sculpteur);

        $this->indexer();

        $voisins = $this->voisinsDe($reference, $this->criteres(seuil: 0.0))->keyBy('produitId');

        $this->assertGreaterThan(
            $voisins[$autreMetier->id]->similarite,
            $voisins[$memeMetier->id]->similarite,
            'Partager le corps de métier rapproche, toutes choses égales par ailleurs.',
        );
    }

    public function test_le_produit_courant_n_est_jamais_son_propre_voisin(): void
    {
        $reference = $this->produit('Panier tressé');
        $this->produit('Panier de marché');

        $this->indexer();

        $voisins = $this->voisinsDe($reference, $this->criteres(seuil: 0.0));

        $this->assertNotContains($reference->id, $voisins->pluck('produitId')->all());
    }

    // =================================================================
    //  MAJORATION DU MÊME ARTISAN
    // =================================================================

    public function test_la_majoration_du_meme_artisan_classe_devant_a_similarite_egale(): void
    {
        $reference = $this->produit('Panier tressé raphia', $this->paniers, $this->vannier);

        // Deux produits de désignation identique, l'un du même artisan,
        // l'autre non : seule la majoration peut les départager.
        $memeArtisan = $this->produit('Corbeille osier', $this->paniers, $this->vannier);
        $autreArtisan = $this->produit('Corbeille osier', $this->paniers, $this->sculpteur);

        $this->indexer();

        $voisins = $this->voisinsDe($reference, $this->criteres(seuil: 0.0));
        $premier = $voisins->first();

        $this->assertSame($memeArtisan->id, $premier->produitId);
        $this->assertTrue($premier->memeArtisan);
        $this->assertGreaterThan(
            $premier->similarite,
            $premier->score,
            'Le score porte la majoration ; la similarité reste la mesure brute.',
        );

        $concurrent = $voisins->firstWhere('produitId', $autreArtisan->id);
        $this->assertFalse($concurrent->memeArtisan);
        $this->assertSame($concurrent->similarite, $concurrent->score, 'Sans majoration, score et similarité coïncident.');
    }

    public function test_une_majoration_neutre_laisse_le_score_egal_a_la_similarite(): void
    {
        $reference = $this->produit('Panier tressé');
        $this->produit('Panier de marché');

        $this->indexer();

        $voisin = $this->voisinsDe($reference, $this->criteres(seuil: 0.0, bonus: 1.0))->first();

        $this->assertEqualsWithDelta($voisin->similarite, $voisin->score, 0.000001);
    }

    public function test_la_majoration_ne_repeche_pas_un_voisin_sous_le_seuil(): void
    {
        $reference = $this->produit('Panier tressé', $this->paniers, $this->vannier);
        $faible = $this->produit('Statue de pierre', $this->masques, $this->vannier);

        $this->indexer();

        $brut = $this->voisinsDe($reference, $this->criteres(seuil: 0.0))
            ->firstWhere('produitId', $faible->id);

        $this->assertNotNull($brut);

        // Un seuil placé juste au-dessus de la similarité brute, mais en
        // dessous du score majoré : le voisin doit malgré tout tomber.
        $seuil = $brut->similarite + 0.0001;

        $this->assertLessThan($seuil, $brut->similarite);
        $this->assertGreaterThan($seuil, $brut->score, 'Le score majoré passerait, lui.');

        $voisins = $this->voisinsDe($reference, $this->criteres(seuil: $seuil));

        $this->assertNotContains(
            $faible->id,
            $voisins->pluck('produitId')->all(),
            'Le seuil porte sur la similarité, pas sur le score.',
        );
    }

    // =================================================================
    //  SEUIL ET CARDINALITÉ
    // =================================================================

    public function test_un_seuil_inatteignable_ne_restitue_rien(): void
    {
        $reference = $this->produit('Panier tressé');
        $this->produit('Masque cérémoniel', $this->masques, $this->sculpteur);

        $this->indexer();

        $this->assertTrue($this->voisinsDe($reference, $this->criteres(seuil: 0.99))->isEmpty());
    }

    public function test_un_catalogue_trop_petit_rend_moins_que_le_nombre_demande(): void
    {
        $reference = $this->produit('Panier tressé');
        $this->produit('Panier de marché');

        $this->indexer();

        $voisins = $this->voisinsDe($reference, $this->criteres(limite: 5, seuil: 0.0));

        $this->assertCount(1, $voisins, 'Deux produits en tout : un seul voisin possible.');
    }

    public function test_un_produit_seul_au_catalogue_n_a_aucun_voisin(): void
    {
        $reference = $this->produit('Panier tressé');

        $this->indexer();

        $this->assertTrue($this->voisinsDe($reference, $this->criteres(seuil: 0.0))->isEmpty());
    }

    public function test_le_nombre_de_voisins_est_borne_par_le_parametre(): void
    {
        $reference = $this->produit('Panier tressé');

        foreach (['Panier de marché', 'Panier à linge', 'Panier rond', 'Panier plat'] as $designation) {
            $this->produit($designation);
        }

        $this->indexer();

        $this->assertCount(2, $this->voisinsDe($reference, $this->criteres(limite: 2, seuil: 0.0)));
    }

    // =================================================================
    //  EXCLUSIONS SYSTÉMATIQUES
    // =================================================================

    public function test_un_produit_non_valide_n_est_jamais_recommande(): void
    {
        $reference = $this->produit('Panier tressé');
        $soumis = $this->produit('Panier de marché', statut: StatutValidationProduit::SOUMIS);

        $this->indexer();

        $this->assertNotContains(
            $soumis->id,
            $this->voisinsDe($reference, $this->criteres(seuil: 0.0))->pluck('produitId')->all(),
        );
    }

    public function test_un_produit_inactif_n_est_jamais_recommande(): void
    {
        $reference = $this->produit('Panier tressé');
        $inactif = $this->produit('Panier de marché', actif: false);

        $this->indexer();

        $this->assertNotContains(
            $inactif->id,
            $this->voisinsDe($reference, $this->criteres(seuil: 0.0))->pluck('produitId')->all(),
        );
    }

    // =================================================================
    //  LE STOCK : PARAMÈTRE DE SURFACE, PAS RÈGLE DU SERVICE
    // =================================================================

    public function test_le_stock_epuise_est_conserve_par_defaut(): void
    {
        $reference = $this->produit('Panier tressé');
        $epuise = $this->produit('Panier de marché');

        $this->indexer();

        $this->assertContains(
            $epuise->id,
            $this->voisinsDe($reference, $this->criteres(seuil: 0.0, stock: false))->pluck('produitId')->all(),
            'Sur le portail, un produit épuisé est annoncé « sur commande », pas masqué.',
        );
    }

    public function test_le_stock_epuise_est_ecarte_quand_la_surface_le_demande(): void
    {
        $reference = $this->produit('Panier tressé');
        $epuise = $this->produit('Panier de marché');
        $enStock = $this->produit('Panier à linge');

        $this->approvisionner($enStock);
        $this->indexer();

        $voisins = $this->voisinsDe($reference, $this->criteres(seuil: 0.0, stock: true))
            ->pluck('produitId')->all();

        $this->assertContains($enStock->id, $voisins);
        $this->assertNotContains($epuise->id, $voisins);
    }

    // =================================================================
    //  ROBUSTESSE
    // =================================================================

    public function test_un_index_absent_ne_leve_pas_mais_ne_restitue_rien(): void
    {
        $reference = $this->produit('Panier tressé');
        $this->produit('Panier de marché');

        // Volontairement pas d'indexation.
        $this->assertTrue($this->voisinsDe($reference)->isEmpty());
        $this->assertNull(app(ServiceRecommandationProduit::class)->nomDuMoteur());
    }

    public function test_le_moteur_lexical_se_nomme(): void
    {
        $this->produit('Panier tressé');
        $this->indexer();

        $this->assertSame(
            'Similarité lexicale (TF-IDF)',
            app(ServiceRecommandationProduit::class)->nomDuMoteur(),
        );
    }

    public function test_les_modeles_sont_rendus_dans_l_ordre_du_classement(): void
    {
        $reference = $this->produit('Panier tressé raphia');
        $this->produit('Masque cérémoniel', $this->masques, $this->sculpteur);
        $proche = $this->produit('Panier tressé osier', $this->paniers);

        $this->indexer();

        $modeles = app(ServiceRecommandationProduit::class)
            ->produitsVoisins($reference, $this->criteres(seuil: 0.0));

        $this->assertSame($proche->id, $modeles->first()->id);
    }

    // =================================================================
    //  LECTURE DU CATALOGUE — PRODUITS ISOLÉS
    // =================================================================

    public function test_un_produit_isole_est_detecte(): void
    {
        // Trois paniers qui se ressemblent, et un produit d'un tout
        // autre monde.
        $this->produit('Panier tressé');
        $this->produit('Panier de marché');
        $this->produit('Panier à linge');
        $isole = $this->produit('Vin de palme fermenté', $this->masques, $this->sculpteur);

        $this->indexer();

        $isoles = app(ServiceAnalyseCatalogue::class)->produitsIsoles(seuil: 0.20);

        $this->assertSame(
            [$isole->id],
            $isoles->pluck('produit_id')->all(),
            'Seul le produit sans parenté lexicale est isolé.',
        );
        $this->assertSame(1, app(ServiceAnalyseCatalogue::class)->nombreDeProduitsIsoles(seuil: 0.20));
    }

    public function test_un_produit_sans_aucun_terme_commun_est_isole_au_sens_le_plus_fort(): void
    {
        $this->produit('Panier tressé');
        $orphelin = $this->produit('Xylophone', $this->masques, $this->sculpteur);

        $this->indexer();

        $ligne = app(ServiceAnalyseCatalogue::class)
            ->produitsIsoles(seuil: 0.99)
            ->firstWhere('produit_id', $orphelin->id);

        $this->assertNotNull($ligne, 'Un produit sans paire doit apparaître, pas disparaître.');
        $this->assertLessThan(0.99, $ligne['meilleure']);
    }

    public function test_un_catalogue_homogene_ne_signale_aucun_isole(): void
    {
        $this->produit('Panier tressé');
        $this->produit('Panier de marché');

        $this->indexer();

        $this->assertTrue(
            app(ServiceAnalyseCatalogue::class)->produitsIsoles(seuil: 0.05)->isEmpty(),
        );
    }

    // =================================================================
    //  LECTURE DU CATALOGUE — SEGMENTS SATURÉS
    // =================================================================

    public function test_un_segment_porte_par_plusieurs_artisans_est_signale(): void
    {
        $troisieme = Artisan::create([
            'nom' => 'Noubissi',
            'corps_metier_id' => $this->vannerie->id,
            'village_id' => $this->village->id,
        ]);

        // Le même article, trois artisans.
        $pivot = $this->produit('Panier tressé raphia', $this->paniers, $this->vannier);
        $this->produit('Panier tressé raphia', $this->paniers, $this->sculpteur);
        $this->produit('Panier tressé raphia', $this->paniers, $troisieme);

        $this->indexer();

        // Le seuil est déduit de ce que le catalogue produit réellement :
        // la pondération TF-IDF déplace les valeurs absolues selon la
        // taille du corpus, et un seuil écrit en dur rendrait ce test
        // fragile pour une raison qui n'a rien à voir avec ce qu'il
        // vérifie.
        $plancher = $this->voisinsDe($pivot, $this->criteres(seuil: 0.0))->min('similarite') - 0.0001;

        $segments = app(ServiceAnalyseCatalogue::class)
            ->segmentsSatures(seuil: $plancher, minimumArtisans: 2);

        $ligne = $segments->firstWhere('produit_id', $pivot->id);

        $this->assertNotNull($ligne, 'Le segment doit être signalé.');
        $this->assertSame(2, $ligne['concurrents'], 'Deux autres artisans portent le même article.');
        $this->assertGreaterThanOrEqual($plancher, $ligne['similarite_moyenne']);
    }

    public function test_une_gamme_d_un_seul_artisan_n_est_pas_une_saturation(): void
    {
        // Trois articles très proches, mais du même artisan : c'est une
        // gamme, pas une concurrence.
        $this->produit('Panier tressé raphia', $this->paniers, $this->vannier);
        $this->produit('Panier tressé raphia', $this->paniers, $this->vannier);
        $this->produit('Panier tressé raphia', $this->paniers, $this->vannier);

        $this->indexer();

        $this->assertTrue(
            app(ServiceAnalyseCatalogue::class)->segmentsSatures(seuil: 0.0, minimumArtisans: 2)->isEmpty(),
        );
    }

    public function test_un_seuil_de_saturation_eleve_ne_signale_rien(): void
    {
        $this->produit('Panier tressé', $this->paniers, $this->vannier);
        $this->produit('Masque cérémoniel', $this->masques, $this->sculpteur);

        $this->indexer();

        $this->assertTrue(
            app(ServiceAnalyseCatalogue::class)->segmentsSatures(seuil: 0.99, minimumArtisans: 2)->isEmpty(),
        );
    }

    // =================================================================
    //  LA COMMANDE DE CALIBRAGE
    // =================================================================

    public function test_la_commande_affiche_les_voisins_d_une_reference(): void
    {
        $reference = $this->produit('Panier tressé');
        $this->produit('Panier de marché');

        $this->indexer();

        $this->artisan('varbaf:voisins', [
            'reference' => $reference->reference,
            '--seuil' => '0',
        ])
            ->expectsOutputToContain('Panier de marché')
            ->assertSuccessful();
    }

    public function test_la_commande_refuse_une_reference_inconnue(): void
    {
        $this->artisan('varbaf:voisins', ['reference' => 'BTQ99-9999'])->assertFailed();
    }

    public function test_la_commande_previent_quand_aucun_voisin_n_atteint_le_seuil(): void
    {
        $reference = $this->produit('Panier tressé');
        $this->produit('Masque cérémoniel', $this->masques, $this->sculpteur);

        $this->indexer();

        $this->artisan('varbaf:voisins', [
            'reference' => $reference->reference,
            '--seuil' => '0.99',
        ])
            ->expectsOutputToContain('Aucun voisin')
            ->assertSuccessful();
    }
}
