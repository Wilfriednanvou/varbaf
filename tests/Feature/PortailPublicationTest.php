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
use Modules\Portail\Enums\DisponibilitePortail;
use Modules\Portail\Enums\StatutDemandeContact;
use Modules\Portail\Exceptions\PublicationPortailException;
use Modules\Portail\Models\ArtisanVedette;
use Modules\Portail\Models\ContenuPage;
use Modules\Portail\Models\DemandeContact;
use Modules\Portail\Models\PublicationProduit;
use Modules\Portail\Services\ServicePortail;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * Règles de publication du portail public.
 *
 * Trois conditions gouvernent la visibilité d'un produit : la fiche est
 * publiée, le produit est exposé, l'artisan autorise la publication. Ce
 * test les éprouve une par une, puis vérifie qu'en retirer une suffit à
 * faire disparaître le produit — sans qu'aucun enregistrement n'ait à
 * être repassé.
 *
 * Il vérifie aussi ce que le portail ne fait **pas** : exposer une
 * quantité en stock.
 */
class PortailPublicationTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;
    protected CorpsMetier $vannerie;
    protected CategorieProduit $paniers;
    protected CategorieProduit $sculptures;
    protected Boutique $boutique;
    protected Artisan $autorise;
    protected Artisan $sansAutorisation;
    protected Produit $panier;
    protected ServicePortail $portail;

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
        $this->sculptures = CategorieProduit::create(['code' => 'SCU', 'libelle' => 'Sculptures']);
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

        $this->panier = $this->creerProduit('Panier tressé', $this->paniers, $this->autorise, 5);

        $this->portail = app(ServicePortail::class);
    }

    // === PUBLICATION PAR DÉFAUT ===

    public function test_une_fiche_nait_non_publiee(): void
    {
        $publication = PublicationProduit::create(['produit_id' => $this->panier->id]);

        $this->assertFalse($publication->publie, 'Créer la fiche ne met rien en ligne.');
        $this->assertSame(0, $this->portail->catalogue()->total());
    }

    // === LES TROIS CONDITIONS ===

    public function test_un_produit_non_expose_ne_peut_pas_etre_publie(): void
    {
        // Validé mais pas exposé : la mise en vitrine n'a pas eu lieu.
        $valide = $this->creerProduit('Corbeille', $this->paniers, $this->autorise, 3, StatutValidationProduit::VALIDE);

        $this->expectException(PublicationPortailException::class);

        PublicationProduit::create(['produit_id' => $valide->id, 'publie' => true]);
    }

    public function test_un_produit_dont_l_artisan_n_autorise_pas_la_publication_est_refuse(): void
    {
        $produit = $this->creerProduit('Natte', $this->paniers, $this->sansAutorisation, 4);

        $this->expectException(PublicationPortailException::class);

        PublicationProduit::create(['produit_id' => $produit->id, 'publie' => true]);
    }

    public function test_le_catalogue_ne_montre_que_les_fiches_publiees(): void
    {
        $autre = $this->creerProduit('Corbeille', $this->paniers, $this->autorise, 2);

        $this->publier($this->panier);
        PublicationProduit::create(['produit_id' => $autre->id]); // brouillon

        $catalogue = $this->portail->catalogue();

        $this->assertSame(1, $catalogue->total());
        $this->assertSame($this->panier->id, $catalogue->first()->produit_id);
    }

    // === LES RETRAITS PRENNENT EFFET SANS REPASSAGE ===

    public function test_retirer_un_produit_de_la_vitrine_le_retire_du_catalogue(): void
    {
        $publication = $this->publier($this->panier);

        $this->assertSame(1, $this->portail->catalogue()->total());

        // EXPOSE → VALIDE : le geste de la section Promotion.
        $this->panier->changerStatut(StatutValidationProduit::VALIDE);

        $this->assertSame(
            0,
            $this->portail->catalogue()->total(),
            'Retirer de la vitrine dépublie, sans toucher à la fiche.',
        );
        $this->assertTrue(
            $publication->fresh()->publie,
            'La fiche reste marquée publiée : c\'est le statut du produit qui a changé.',
        );
    }

    public function test_retirer_l_autorisation_de_l_artisan_le_retire_du_catalogue(): void
    {
        $this->publier($this->panier);

        $this->assertSame(1, $this->portail->catalogue()->total());

        $this->autorise->update(['autorisation_publication' => false]);

        $this->assertSame(
            0,
            $this->portail->catalogue()->total(),
            "L'autorisation appartient à l'artisan : la retirer suffit.",
        );
    }

    // === LE STOCK N'EST JAMAIS EXPOSÉ ===

    public function test_le_catalogue_annonce_une_disponibilite_et_jamais_une_quantite(): void
    {
        $this->publier($this->panier);

        $publication = $this->portail->catalogue()->first();

        $this->assertSame(
            DisponibilitePortail::DISPONIBLE,
            $this->portail->disponibilite($publication),
        );

        // Aucun attribut de la ligne ne porte la quantité : seule la
        // présence, en 0 ou 1, a traversé depuis la base.
        $this->assertSame(1, (int) $publication->getAttribute('presence_en_stock'));

        foreach (array_keys($publication->getAttributes()) as $attribut) {
            $this->assertStringNotContainsString(
                'quantite',
                $attribut,
                "Aucun attribut du catalogue ne doit porter une quantité (trouvé : {$attribut}).",
            );
        }
    }

    public function test_un_produit_sans_stock_est_annonce_sur_commande(): void
    {
        $sansStock = $this->creerProduit('Grand panier', $this->paniers, $this->autorise, 0);
        $this->publier($sansStock);

        $publication = $this->portail->ficheProduit($sansStock->reference);

        $this->assertNotNull($publication);
        $this->assertSame(
            DisponibilitePortail::SUR_COMMANDE,
            $this->portail->disponibilite($publication),
        );
    }

    // === FILTRES ===

    public function test_les_filtres_ne_proposent_que_ce_qui_a_des_produits_visibles(): void
    {
        $this->publier($this->panier);

        $categories = $this->portail->categoriesDuCatalogue();

        $this->assertCount(1, $categories);
        $this->assertSame('VAN-PAN', $categories->first()->code);

        $this->assertCount(1, $this->portail->corpsMetiersDuCatalogue());
    }

    public function test_le_catalogue_se_filtre_par_categorie(): void
    {
        $sculpture = $this->creerProduit('Masque', $this->sculptures, $this->autorise, 2);

        $this->publier($this->panier);
        $this->publier($sculpture);

        $this->assertSame(2, $this->portail->catalogue()->total());
        $this->assertSame(1, $this->portail->catalogue(categorieId: $this->sculptures->id)->total());
    }

    // === ANNUAIRE DES ARTISANS ===

    public function test_un_artisan_sans_autorisation_est_absent_de_l_annuaire(): void
    {
        $annuaire = $this->portail->artisansPublies();

        $this->assertSame(1, $annuaire->total());
        $this->assertSame('Kamdem', $annuaire->first()->nom);

        $this->assertNull(
            $this->portail->ficheArtisan($this->sansAutorisation->matricule),
            "Sa fiche ne doit pas non plus être atteignable par son matricule.",
        );
    }

    public function test_une_mise_en_avant_exige_l_autorisation_de_l_artisan(): void
    {
        $this->expectException(PublicationPortailException::class);

        ArtisanVedette::create([
            'artisan_id' => $this->sansAutorisation->id,
            'date_debut' => now()->subDay(),
            'texte' => 'Portrait du mois',
        ]);
    }

    public function test_une_mise_en_avant_expiree_ne_s_affiche_plus(): void
    {
        ArtisanVedette::create([
            'artisan_id' => $this->autorise->id,
            'date_debut' => now()->subMonth(),
            'date_fin' => now()->subDay(),
            'texte' => 'Portrait du mois dernier',
        ]);

        $this->assertCount(
            0,
            $this->portail->artisansVedettes(),
            "Une période close s'éteint d'elle-même, sans qu'on ait à y penser.",
        );
    }

    // === CONTENUS ÉDITORIAUX ===

    public function test_un_contenu_desactive_n_est_pas_servi(): void
    {
        ContenuPage::create([
            'cle' => 'village.presentation',
            'titre' => 'Le village',
            'corps' => 'Texte de présentation.',
            'actif' => false,
        ]);

        $this->assertNull($this->portail->contenu('village.presentation'));
    }

    // === CONTACT ===

    public function test_une_demande_de_contact_est_enregistree(): void
    {
        $demande = $this->portail->enregistrerDemandeContact([
            'nom' => 'Awa Nguemo',
            'contact' => 'awa@example.test',
            'sujet' => 'Visite de groupe',
            'message' => 'Nous souhaitons visiter le village avec une classe.',
        ], '203.0.113.4');

        $this->assertSame(StatutDemandeContact::NOUVELLE, $demande->statut);
        $this->assertSame('203.0.113.4', $demande->adresse_ip);
        $this->assertSame(1, DemandeContact::query()->aTraiter()->count());
    }

    public function test_le_message_d_une_demande_ne_se_retouche_pas(): void
    {
        $demande = $this->portail->enregistrerDemandeContact([
            'nom' => 'Awa Nguemo',
            'contact' => 'awa@example.test',
            'message' => 'Message d\'origine.',
        ]);

        // Le suivi, lui, s'écrit.
        $demande->update([
            'statut' => StatutDemandeContact::TRAITEE,
            'date_traitement' => now(),
            'note_traitement' => 'Répondu par téléphone.',
        ]);

        $this->assertTrue($demande->fresh()->estTraitee());

        $this->expectException(PublicationPortailException::class);

        $demande->update(['message' => 'Message réécrit après coup.']);
    }

    // === HELPERS ===

    protected function creerProduit(
        string $designation,
        CategorieProduit $categorie,
        Artisan $artisan,
        int $stock,
        StatutValidationProduit $statut = StatutValidationProduit::EXPOSE,
    ): Produit {
        $produit = Produit::create([
            'designation' => $designation,
            'prix_unitaire' => 4000,
            'categorie_id' => $categorie->id,
            'artisan_id' => $artisan->id,
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

    protected function publier(Produit $produit): PublicationProduit
    {
        return PublicationProduit::create([
            'produit_id' => $produit->id,
            'publie' => true,
            'date_publication' => now(),
        ]);
    }
}
