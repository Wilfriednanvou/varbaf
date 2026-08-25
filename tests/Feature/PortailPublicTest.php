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
use Modules\Portail\Enums\StatutDemandeContact;
use Modules\Portail\Models\ArtisanVedette;
use Modules\Portail\Models\ContenuPage;
use Modules\Portail\Models\DemandeContact;
use Modules\Portail\Models\PublicationProduit;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * Le site public, éprouvé par ses routes.
 *
 * Les règles du temps 1 sont vérifiées au niveau du service ; ce test
 * les reprend là où elles comptent vraiment — dans la réponse HTTP
 * qu'un visiteur anonyme obtient réellement.
 *
 * Deux invariants y sont vérifiés dans le HTML rendu et pas ailleurs :
 * aucune quantité en stock n'y figure, et un produit invisible répond
 * 404 plutôt que 403. Distinguer « ça n'existe pas » de « vous n'y avez
 * pas droit » renseignerait déjà le visiteur sur ce que le village a en
 * réserve.
 */
class PortailPublicTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;
    protected CorpsMetier $vannerie;
    protected CategorieProduit $paniers;
    protected Boutique $boutique;
    protected Artisan $autorise;
    protected Artisan $sansAutorisation;
    protected Produit $publie;

    protected function setUp(): void
    {
        parent::setUp();

        // Le portail est servi sans assets compilés en test : sans cela,
        // @vite chercherait un manifeste qui n'existe pas.
        $this->withoutVite();

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

        // Un produit publié, avec 7 unités en stock : le nombre est
        // choisi pour être introuvable ailleurs dans la page.
        $this->publie = $this->creerProduit('Panier tressé', $this->autorise, 7);
        PublicationProduit::create([
            'produit_id' => $this->publie->id,
            'publie' => true,
            'date_publication' => now(),
        ]);
    }

    // === LES PAGES RÉPONDENT ===

    public function test_les_pages_publiques_sont_accessibles_sans_authentification(): void
    {
        $this->get(route('portail.accueil'))->assertOk();
        $this->get(route('portail.catalogue'))->assertOk();
        $this->get(route('portail.artisans'))->assertOk();
        $this->get(route('portail.village'))->assertOk();
        $this->get(route('portail.contact'))->assertOk();
        $this->get(route('portail.produit', $this->publie->reference))->assertOk();
        $this->get(route('portail.artisan', $this->autorise->matricule))->assertOk();
    }

    // === CE QUI N'EST PAS PUBLIÉ N'EXISTE PAS ===

    public function test_un_produit_non_publie_repond_404(): void
    {
        $brouillon = $this->creerProduit('Corbeille', $this->autorise, 3);
        PublicationProduit::create(['produit_id' => $brouillon->id]); // publie = false

        $this->get(route('portail.produit', $brouillon->reference))->assertNotFound();

        $this->get(route('portail.catalogue'))
            ->assertOk()
            ->assertDontSee('Corbeille');
    }

    public function test_un_produit_valide_mais_non_expose_est_absent_du_catalogue(): void
    {
        // Le produit est publié, puis retiré de la vitrine : EXPOSE reste
        // la porte d'entrée, le drapeau ne suffit pas.
        $this->publie->changerStatut(StatutValidationProduit::VALIDE);

        $this->get(route('portail.catalogue'))
            ->assertOk()
            ->assertDontSee('Panier tressé');

        $this->get(route('portail.produit', $this->publie->reference))->assertNotFound();
    }

    public function test_un_produit_sans_fiche_du_tout_repond_404(): void
    {
        $sansFiche = $this->creerProduit('Natte', $this->autorise, 2);

        $this->get(route('portail.produit', $sansFiche->reference))->assertNotFound();
    }

    // === L'AUTORISATION APPARTIENT À L'ARTISAN ===

    public function test_un_artisan_sans_autorisation_repond_404(): void
    {
        $this->get(route('portail.artisan', $this->sansAutorisation->matricule))
            ->assertNotFound();

        $this->get(route('portail.artisans'))
            ->assertOk()
            ->assertSee('Kamdem')
            ->assertDontSee('Fotso');
    }

    public function test_retirer_l_autorisation_retire_aussi_les_produits_de_l_artisan(): void
    {
        $this->get(route('portail.catalogue'))->assertOk()->assertSee('Panier tressé');

        $this->autorise->update(['autorisation_publication' => false]);

        $this->get(route('portail.catalogue'))->assertOk()->assertDontSee('Panier tressé');
        $this->get(route('portail.produit', $this->publie->reference))->assertNotFound();
    }

    // === LE STOCK N'APPARAÎT PAS DANS LE HTML ===

    public function test_aucune_quantite_en_stock_n_apparait_dans_le_html_rendu(): void
    {
        foreach ([
            route('portail.accueil'),
            route('portail.catalogue'),
            route('portail.produit', $this->publie->reference),
            route('portail.artisan', $this->autorise->matricule),
        ] as $adresse) {
            $reponse = $this->get($adresse)->assertOk();

            $reponse->assertSee('Disponible en boutique');

            // 7 est la quantité réelle. Elle ne doit apparaître nulle
            // part, ni en chiffre isolé ni accompagnée d'un mot de stock.
            $reponse->assertDontSee('en stock');
            $reponse->assertDontSee('7 unité');
            $reponse->assertDontSee('Stock :');

            $html = $reponse->getContent();

            $this->assertDoesNotMatchRegularExpression(
                '/\b7\s*(unité|article|pièce|en stock)/iu',
                $html,
                "La quantité en stock ne doit apparaître sur aucune page publique ({$adresse}).",
            );
        }
    }

    public function test_un_produit_epuise_est_annonce_sur_commande(): void
    {
        $epuise = $this->creerProduit('Grand panier', $this->autorise, 0);
        PublicationProduit::create([
            'produit_id' => $epuise->id,
            'publie' => true,
            'date_publication' => now(),
        ]);

        $this->get(route('portail.produit', $epuise->reference))
            ->assertOk()
            ->assertSee('Sur commande');
    }

    // === FILTRES ===

    public function test_le_catalogue_se_filtre_par_categorie_et_par_metier(): void
    {
        $sculptures = CategorieProduit::create(['code' => 'SCU', 'libelle' => 'Sculptures']);
        $masque = $this->creerProduit('Masque', $this->autorise, 1, $sculptures);
        PublicationProduit::create(['produit_id' => $masque->id, 'publie' => true]);

        $this->get(route('portail.catalogue', ['categorie' => $sculptures->id]))
            ->assertOk()
            ->assertSee('Masque')
            ->assertDontSee('Panier tressé');

        $this->get(route('portail.catalogue', ['metier' => $this->vannerie->id]))
            ->assertOk()
            ->assertSee('Panier tressé');
    }

    // === CONTENUS ÉDITORIAUX ===

    public function test_la_page_du_village_affiche_les_contenus_actifs(): void
    {
        ContenuPage::create([
            'cle' => 'village.presentation',
            'titre' => 'Notre histoire',
            'corps' => 'Le village a été créé pour rassembler les artisans de la région.',
        ]);

        ContenuPage::create([
            'cle' => 'village.brouillon',
            'titre' => 'Texte en attente',
            'corps' => 'Ne doit pas paraître.',
            'actif' => false,
        ]);

        $this->get(route('portail.village'))
            ->assertOk()
            ->assertSee('Notre histoire')
            ->assertDontSee('Texte en attente');
    }

    public function test_un_artisan_vedette_apparait_sur_l_accueil(): void
    {
        ArtisanVedette::create([
            'artisan_id' => $this->autorise->id,
            'date_debut' => now()->subDay(),
            'texte' => 'Vannier depuis vingt ans.',
        ]);

        $this->get(route('portail.accueil'))
            ->assertOk()
            ->assertSee('Vannier depuis vingt ans.');
    }

    // === CONTACT ===

    public function test_une_demande_de_contact_est_enregistree_depuis_le_formulaire(): void
    {
        $this->post(route('portail.contact.envoi'), [
            'nom' => 'Awa Nguemo',
            'contact' => 'awa@example.test',
            'sujet' => 'Visite de groupe',
            'message' => 'Nous souhaitons visiter le village avec une classe de trente élèves.',
        ])
            ->assertRedirect(route('portail.contact'))
            ->assertSessionHas('succes');

        $demande = DemandeContact::query()->firstOrFail();

        $this->assertSame('Awa Nguemo', $demande->nom);
        $this->assertSame(StatutDemandeContact::NOUVELLE, $demande->statut);
    }

    public function test_un_formulaire_incomplet_est_refuse_sans_rien_enregistrer(): void
    {
        $this->post(route('portail.contact.envoi'), [
            'nom' => 'A',
            'contact' => '',
            'message' => 'court',
        ])->assertSessionHasErrors(['nom', 'contact', 'message']);

        $this->assertSame(0, DemandeContact::query()->count());
    }

    // === HELPERS ===

    protected function creerProduit(
        string $designation,
        Artisan $artisan,
        int $stock,
        ?CategorieProduit $categorie = null,
    ): Produit {
        $produit = Produit::create([
            'designation' => $designation,
            'prix_unitaire' => 4000,
            'categorie_id' => ($categorie ?? $this->paniers)->id,
            'artisan_id' => $artisan->id,
            'boutique_id' => $this->boutique->id,
        ]);

        $produit->changerStatut(StatutValidationProduit::VALIDE);
        $produit->changerStatut(StatutValidationProduit::EXPOSE);

        if ($stock > 0) {
            app(ServiceMouvementStock::class)->deposer($produit->fresh(), $stock);
        }

        return $produit->fresh();
    }
}
