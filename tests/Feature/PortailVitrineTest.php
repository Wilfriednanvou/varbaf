<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Enums\NatureContenant;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Services\ServiceMouvementStock;
use Modules\Portail\Exceptions\PublicationPortailException;
use Modules\Portail\Models\PublicationProduit;
use Modules\Portail\Services\ServicePortail;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * La vitrine — ce que le visiteur voit, et non ce que le service rend.
 *
 * `PortailPublicTest` éprouve les règles de visibilité ; celui-ci
 * éprouve la couche au-dessus : la page des boutiques, les repères de
 * l'accueil, les coordonnées du pied de page et la chaîne d'illustration.
 *
 * Ces quatre points ont en commun de ne lever aucune erreur quand ils
 * échouent. Un pied de page sans téléphone, un repère à zéro, une
 * vignette sans image : la page reste servie, le code reste vert, et
 * seul un œil sur l'écran s'en aperçoit. C'est exactement la famille de
 * défauts que le journal du 27/08 recense — quelque chose qui a l'air de
 * marcher et qui regarde ailleurs.
 */
class PortailVitrineTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected CorpsMetier $vannerie;

    protected CorpsMetier $broderie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->village = VillageArtisanal::create([
            'code' => 'VARBAF',
            'nom' => 'Village Artisanal Régional de Bafoussam',
            'categorie' => CategorieVillage::REGIONAL,
            'region' => 'Ouest',
            'adresse' => 'Quartier Banengo, Bafoussam',
            'telephone' => '+237 6 99 12 34 56',
            'email' => 'contact@varbaf.cm',
            'actif' => true,
        ]);

        // La vannerie a une photographie declaree en configuration ; la
        // broderie n'en a pas. Les deux cas de la chaine d'illustration
        // sont donc representes.
        $this->vannerie = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);
        $this->broderie = CorpsMetier::create(['code' => 'BRD', 'libelle' => 'Broderie traditionnelle']);
    }

    // === LA PAGE DES BOUTIQUES ===

    public function test_la_page_des_boutiques_liste_les_locaux_de_vente(): void
    {
        Boutique::create(['numero' => 'B01', 'village_id' => $this->village->id, 'nature' => NatureContenant::BOUTIQUE]);
        Boutique::create(['numero' => 'B02', 'village_id' => $this->village->id, 'nature' => NatureContenant::BOUTIQUE]);

        $this->get(route('portail.boutiques'))
            ->assertOk()
            ->assertSee('Boutique B01')
            ->assertSee('Boutique B02');
    }

    public function test_une_emprise_hors_vente_n_apparait_pas_sur_la_page_des_boutiques(): void
    {
        Boutique::create(['numero' => 'B01', 'village_id' => $this->village->id, 'nature' => NatureContenant::BOUTIQUE]);
        Boutique::create(['numero' => 'SS01', 'village_id' => $this->village->id, 'nature' => NatureContenant::SOUS_SOL]);
        Boutique::create(['numero' => 'EV01', 'village_id' => $this->village->id, 'nature' => NatureContenant::ESPACE_VERT]);

        // Le sous-sol et l'espace vert sont loues et font partie du parc,
        // mais ce ne sont pas des lieux ou l'on entre regarder des
        // creations : les afficher enverrait un visiteur devant une
        // emprise sans vitrine.
        $this->get(route('portail.boutiques'))
            ->assertOk()
            ->assertSee('Boutique B01')
            ->assertDontSee('SS01')
            ->assertDontSee('EV01');
    }

    // === LES REPÈRES DE L'ACCUEIL ===

    public function test_les_reperes_de_l_accueil_ne_comptent_que_ce_qui_est_visible(): void
    {
        Boutique::create(['numero' => 'B01', 'village_id' => $this->village->id, 'nature' => NatureContenant::BOUTIQUE]);
        Boutique::create(['numero' => 'SS01', 'village_id' => $this->village->id, 'nature' => NatureContenant::SOUS_SOL]);

        $vitrine = $this->creerArtisan('Kamdem', $this->vannerie, autorise: true);
        $discret = $this->creerArtisan('Fotso', $this->vannerie, autorise: true);

        $this->publier($this->creerProduit('Panier tressé', $vitrine));

        // Une publication existante mais retiree de la vitrine : c'est
        // le cas que les reperes doivent ecarter *par eux-memes*.
        // L'artisan sans autorisation ne convient pas ici — le modele
        // lui refuse toute ligne de publication, donc les reperes
        // n'auraient rien a filtrer. C'est le test suivant qui retient
        // cette garantie-la, et c'est sa place.
        $this->publier($this->creerProduit('Corbeille', $discret), visible: false);

        $reperes = app(ServicePortail::class)->reperes();

        // Un seul artisan est en vitrine : celui qui a donne son accord.
        $this->assertSame(1, $reperes['artisans']);
        // Une seule creation visible, pour la meme raison.
        $this->assertSame(1, $reperes['creations']);
        // Un seul corps de metier represente : la broderie n'a rien de publie.
        $this->assertSame(1, $reperes['metiers']);
        // Un seul local de vente : le sous-sol n'en est pas un.
        $this->assertSame(1, $reperes['locaux']);
    }

    // === LES COORDONNÉES VIENNENT DE LA BASE ===

    public function test_le_pied_de_page_porte_les_coordonnees_saisies_dans_le_panneau(): void
    {
        // Recopier ces valeurs dans le gabarit ferait du site la seule
        // source d'une information que la base detient deja : le jour ou
        // la coordination change de numero, le panneau serait a jour et
        // le site faux.
        $this->get(route('portail.accueil'))
            ->assertOk()
            ->assertSee('Quartier Banengo, Bafoussam')
            ->assertSee('+237 6 99 12 34 56')
            ->assertSee('contact@varbaf.cm');
    }

    public function test_la_page_de_contact_affiche_aussi_les_coordonnees(): void
    {
        // Le compositeur est pose sur `portail::*` et non sur le seul
        // gabarit : Blade rend la vue enfant avant la mise en page, donc
        // une variable attachee au gabarit manquerait dans le corps de
        // cette page-ci. Ce test retient la portee du compositeur.
        $this->get(route('portail.contact'))
            ->assertOk()
            ->assertSee('Quartier Banengo, Bafoussam');
    }

    // === LA CHAÎNE D'ILLUSTRATION ===

    public function test_un_metier_photographie_affiche_sa_photographie(): void
    {
        $artisan = $this->creerArtisan('Kamdem', $this->vannerie, autorise: true);
        $this->publier($this->creerProduit('Panier tressé', $artisan));

        $chemin = config('portail.visuels.metiers.VAN');
        $this->assertNotNull($chemin, 'La vannerie doit avoir un visuel declare en configuration.');

        $this->get(route('portail.catalogue'))
            ->assertOk()
            ->assertSee($chemin.'-800.webp', escape: false);
    }

    public function test_un_metier_sans_photographie_retombe_sur_le_motif_dessine(): void
    {
        $artisan = $this->creerArtisan('Nana', $this->broderie, autorise: true);
        $this->publier($this->creerProduit('Boubou brodé', $artisan));

        $this->assertNull(
            config('portail.visuels.metiers.BRD'),
            'La broderie ne doit pas avoir de visuel declare : illustrer un metier par la photo d\'un autre tromperait le visiteur.',
        );

        // Le repli est un motif trace dans la page, pas une image
        // manquante : la grille se tient, et le jour ou la photo arrivera
        // elle prendra la place sans qu'une vue change.
        $reponse = $this->get(route('portail.catalogue'))->assertOk();

        $reponse->assertSee('Boubou brodé');
        // `motif-` prefixe l'identifiant du motif de repli. Chercher un
        // simple `<svg` ne prouverait rien : le gabarit en contient deja
        // un pour l'icone du menu.
        $reponse->assertSee('id="motif-', escape: false);
        $reponse->assertDontSee('images/portail/metiers/', escape: false);
    }

    // === OUTILLAGE ===

    /**
     * L'autorisation de l'artisan ferme la porte a l'ecriture, pas a la
     * lecture.
     *
     * Ce test ne double pas un filtre des reperes : il constate que le
     * filtre n'a rien a faire, parce que la ligne de publication ne peut
     * pas naitre. La difference compte — un comptage qui ecarte peut
     * cesser d'ecarter sans que rien ne le dise ; une contrainte
     * d'ecriture, non.
     */
    public function test_un_artisan_sans_autorisation_ne_peut_pas_etre_publie(): void
    {
        $cache = $this->creerArtisan('Fotso', $this->vannerie, autorise: false);
        $produit = $this->creerProduit('Corbeille', $cache);

        $this->expectException(PublicationPortailException::class);

        $this->publier($produit);
    }

    protected function creerArtisan(string $nom, CorpsMetier $metier, bool $autorise): Artisan
    {
        return Artisan::create([
            'nom' => $nom,
            'corps_metier_id' => $metier->id,
            'village_id' => $this->village->id,
            'autorisation_publication' => $autorise,
        ]);
    }

    protected function creerProduit(string $designation, Artisan $artisan): Produit
    {
        $categorie = CategorieProduit::firstOrCreate(
            ['code' => 'DIV'],
            ['libelle' => 'Divers'],
        );

        // **B01 et non un numero a part.** Une fabrique de test qui
        // cree en coulisse une entite que d'autres tests comptent est un
        // piege : le test des reperes declarait un local de vente,
        // attendait 1, et en trouvait 2 — le second pose ici, hors de sa
        // vue. La fabrique reutilise donc le local que les tests
        // declarent, au lieu d'en ajouter un qu'ils ignorent.
        $boutique = Boutique::firstOrCreate(
            ['numero' => 'B01', 'village_id' => $this->village->id],
            ['nature' => NatureContenant::BOUTIQUE],
        );

        $produit = Produit::create([
            'designation' => $designation,
            'artisan_id' => $artisan->id,
            'categorie_id' => $categorie->id,
            'boutique_id' => $boutique->id,
            'prix_unitaire' => 5000,
        ]);

        $produit->changerStatut(StatutValidationProduit::VALIDE);
        $produit->changerStatut(StatutValidationProduit::EXPOSE);

        app(ServiceMouvementStock::class)->deposer($produit->fresh(), 3);

        return $produit->fresh();
    }

    protected function publier(Produit $produit, bool $visible = true): PublicationProduit
    {
        return PublicationProduit::create([
            'produit_id' => $produit->id,
            'publie' => $visible,
            'date_publication' => now(),
        ]);
    }
}
