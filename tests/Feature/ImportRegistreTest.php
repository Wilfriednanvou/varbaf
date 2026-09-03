<?php

namespace Tests\Feature;

use App\Import\RapportImport;
use App\Import\ServiceImportRegistre;
use App\Import\TraceLigneImportee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\AttributionEspace;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Artisanat\Models\EspaceLocatif;
use Modules\Commerce\Models\Depot;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Models\TauxCommission;
use Modules\Commerce\Models\Vente;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\SectionCaisse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Reprise du registre de ventes reconstruit (pipeline du 2 septembre
 * 2026) — `registre.csv` + `rattachements.csv` + `parc-locatif.csv`.
 *
 * **Ce fichier a changé de forme, pas d'intention.** Il protégeait un
 * lecteur qui parsait un cahier manuscrit brut — guillemets de
 * répétition, dates à trois écritures, quantité/prix/montant à
 * recouper. Ce travail vit maintenant dans `docs/donnees/*.py`, avant
 * que PHP ne voie quoi que ce soit, et les tests qui le protégeaient
 * n'ont plus d'objet : ils ont disparu avec lui plutôt que d'être
 * maquillés en tests du nouveau format.
 *
 * Ce qui reste, parce que ça n'a pas bougé : la façon dont
 * `ServiceImportRegistre` écrit — un artisan par occupant résolu,
 * jamais d'espace ni de secteur inventé, le prix d'un produit figé sur
 * sa première occurrence, une occupation refusée qui n'emporte jamais
 * la vente, la relançabilité sans doublon.
 */
class ImportRegistreTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $exercice;

    protected Utilisateur $compte;

    protected string $registre;

    protected string $repertoire;

    protected string $repertoireRapports;

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

        $this->exercice = Exercice::create([
            'libelle' => '2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'en_cours' => true,
            'village_id' => $this->village->id,
        ]);

        TauxCommission::create([
            'taux' => 10.00,
            'date_effet' => '2026-01-01',
            'reference_decision' => 'Note de service de test',
            'village_id' => $this->village->id,
        ]);

        // Référentiel des corps de métier : le seeder du module ne
        // tourne pas ici, ce test pose les deux codes qu'il désigne.
        CorpsMetier::create([
            'code' => 'AGR',
            'libelle' => 'Agroalimentaire',
            'description' => 'Transformation des produits du terroir',
        ]);

        CorpsMetier::create([
            'code' => 'MED',
            'libelle' => 'Produits médicinaux',
            'description' => 'Préparations de la pharmacopée traditionnelle',
        ]);

        // Le parc, seedé indépendamment du fichier de reprise — c'est
        // toujours BoutiqueSeeder/EspaceLocatifSeeder qui en font
        // autorité, jamais l'import. B0299 n'existe délibérément pas :
        // c'est l'espace que la table de correspondance nommera sans
        // que le parc le porte.
        $b01 = Boutique::create(['numero' => 'B01', 'village_id' => $this->village->id]);
        $b02 = Boutique::create(['numero' => 'B02', 'village_id' => $this->village->id]);

        EspaceLocatif::create(['boutique_id' => $b01->id]); // B0101
        EspaceLocatif::create(['boutique_id' => $b02->id]); // B0201
        EspaceLocatif::create(['boutique_id' => $b02->id]); // B0202

        $agent = Agent::create([
            'nom' => 'Ngassa',
            'prenom' => 'Alice',
            'fonction' => 'Agent commercial',
            'actif' => true,
            'village_id' => $this->village->id,
        ]);

        $this->compte = Utilisateur::create([
            'name' => 'Alice Ngassa',
            'email' => 'alice@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
            'agent_id' => $agent->id,
        ]);

        Role::findOrCreate(Utilisateur::ROLE_SUPER_UTILISATEUR, 'web');
        $this->compte->syncRoles([Utilisateur::ROLE_SUPER_UTILISATEUR]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $caisse = Caisse::create([
            'code' => 'CAISSE-TEST',
            'libelle' => 'Caisse de test',
            'etat' => 'ACTIVE',
            'village_id' => $this->village->id,
        ]);

        SectionCaisse::create([
            'caisse_id' => $caisse->id,
            'libelle' => 'Section de test',
            'date_ouverture' => now(),
            'solde_ouverture' => 0,
            'etat' => 'OUVERTE',
            'ouverte_par' => $this->compte->id,
            'village_id' => $this->village->id,
            'exercice_id' => $this->exercice->id,
        ]);

        $this->repertoire = storage_path('framework/testing/registre-'.uniqid());
        $this->repertoireRapports = storage_path('framework/testing/imports-'.uniqid());
        $this->registre = $this->ecrireLesFichiers();

        $this->actingAs($this->compte);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repertoire);
        File::deleteDirectory($this->repertoireRapports);

        parent::tearDown();
    }

    /**
     * Sept lignes, chacune posée pour porter une difficulté précise :
     * deux écritures pour le même occupant (Bassi/BASSIE), un montant
     * absent (Beurre), un déposant sans espace connu (Curcuma), deux
     * occupants distincts que la table envoie sur le même espace
     * (Crousti/Ngassam — l'occupation refusée), un espace nommé mais
     * hors du parc (Introuvable → B0299), et un même produit vendu deux
     * fois à des prix différents (Miel : 5000 puis 3000).
     */
    protected function ecrireLesFichiers(): string
    {
        File::ensureDirectoryExists($this->repertoire);

        $registre = <<<'CSV'
        ligne_source;date;date_lue;designation;montant;artisan;type_artisanat;observation;reste_a_payer
        1;2026-02-03;oui;Miel;5000;Bassi;;;0
        2;2026-02-04;oui;Miel;3000;BASSIE;;;0
        3;2026-02-05;oui;Curcuma;1000;Non installée;;;0
        4;2026-02-06;oui;Beurre;;Bassi;;;0
        5;2026-02-09;oui;Croquette;1500;Crousti delice;;;0
        6;2026-02-10;oui;Croquette;500;Crousti NGASSAM;;;0
        7;2026-02-15;oui;Boîte;1000;Introuvable;;;0
        CSV;

        $rattachements = <<<'CSV'
        ecriture_registre;nb_ventes;total_fcfa;decision;espace_locatif;occupant_parc;similarite;motif
        Bassi;2;8000;RATTACHE;B0101;Bassi;1.0;Identité établie
        BASSIE;1;3000;RATTACHE;B0101;Bassi;0.9;Variante orthographique
        Non installée;1;1000;SANS CORRESPONDANCE;;;0.0;Aucun occupant ne partage de mot distinctif
        Crousti delice;1;1500;RATTACHE;B0201;Crousti;1.0;Identité établie
        Crousti NGASSAM;1;500;RATTACHE;B0201;Ngassam;0.8;Identité établie
        Introuvable;1;1000;RATTACHE;B0299;Fantôme;1.0;Identité établie
        CSV;

        $parc = <<<'CSV'
        ligne_source;contenant;nature;espace;occupant;metier;redevance;du_2026;paye_2026;paye_mensuel_2026;ecart_paye;reste_2026
        1;B01;BOUTIQUE;B0101;Bassi;Production des vins;3000;0;0;0;0;0
        2;B02;BOUTIQUE;B0201;Crousti;Production de la pharmacopée traditionnelle;2500;0;0;0;0;0
        3;B02;BOUTIQUE;B0202;Ngassam;Produits de santé;2500;0;0;0;0;0
        CSV;

        File::put($this->repertoire.'/registre.csv', $registre."\n");
        File::put($this->repertoire.'/rattachements.csv', $rattachements."\n");
        File::put($this->repertoire.'/parc-locatif.csv', $parc."\n");

        return $this->repertoire.'/registre.csv';
    }

    protected function importer(): RapportImport
    {
        return app(ServiceImportRegistre::class)->importer($this->registre, seuil: 85.0, marge: 10.0);
    }

    // =================================================================

    public function test_il_compte_les_lignes_traitees_importees_et_signalees(): void
    {
        $rapport = $this->importer();

        $this->assertSame(7, $rapport->valeur(RapportImport::LIGNES_TRAITEES));

        // Une seule ligne ne peut pas devenir une vente : Beurre, sans
        // montant. Elle est tracée, jamais écartée en silence.
        $this->assertSame(1, $rapport->valeur(RapportImport::LIGNES_NON_IMPORTEES));
        $this->assertSame(6, $rapport->valeur(RapportImport::LIGNES_IMPORTEES));
        $this->assertSame(6, Vente::count());

        $this->assertGreaterThan(0, $rapport->valeur(RapportImport::LIGNES_SIGNALEES));
        $this->assertSame(7, TraceLigneImportee::count());
        $this->assertSame(1, TraceLigneImportee::where('statut', TraceLigneImportee::STATUT_NON_IMPORTEE)->count());
    }

    public function test_une_ligne_sans_montant_est_tracee_et_non_reprise(): void
    {
        $this->importer();

        $beurre = TraceLigneImportee::where('numero_ligne', 4)->firstOrFail();

        $this->assertSame(TraceLigneImportee::STATUT_NON_IMPORTEE, $beurre->statut);
        $this->assertNull($beurre->vente_id);
        $this->assertSame(0, Produit::where('designation', 'Beurre')->count());
    }

    public function test_deux_ecritures_rattachees_au_meme_occupant_produisent_un_seul_artisan(): void
    {
        $this->importer();

        // « Bassi » et « BASSIE » sont deux écritures du registre pour
        // le même occupant du parc : rattachements.csv les envoie
        // toutes deux sur « Bassi », et resoudreArtisan() ne crée
        // qu'une seule fiche.
        $this->assertSame(1, Artisan::where('nom', 'Bassi')->count());
        $this->assertSame(0, Artisan::where('nom', 'BASSIE')->count());

        $bassi = Artisan::where('nom', 'Bassi')->firstOrFail();
        $this->assertSame(2, Vente::where('artisan_id', $bassi->id)->count());
    }

    public function test_un_deposant_sans_espace_connu_va_sur_la_boutique_technique(): void
    {
        $rapport = $this->importer();

        $technique = Boutique::where('numero', ServiceImportRegistre::BOUTIQUE_TECHNIQUE)->firstOrFail();
        $curcuma = Produit::where('designation', 'Curcuma')->firstOrFail();

        $this->assertSame($technique->id, $curcuma->boutique_id);

        $nonInstallee = Artisan::where('nom', 'Non installée')->firstOrFail();
        $this->assertSame(0, AttributionEspace::where('artisan_id', $nonInstallee->id)->count());
        $this->assertGreaterThanOrEqual(1, $rapport->valeur(RapportImport::LIGNES_SIGNALEES));
    }

    public function test_l_import_ne_cree_aucun_espace_locatif(): void
    {
        $avant = EspaceLocatif::count();

        $rapport = $this->importer();

        $this->assertSame($avant, EspaceLocatif::count());
        $this->assertSame(0, $rapport->valeur(RapportImport::ESPACES_CREES));
    }

    public function test_un_espace_nomme_mais_absent_du_parc_est_signale(): void
    {
        $rapport = $this->importer();

        // B0299 figure dans rattachements.csv mais dans aucune des deux
        // tables qui font autorité sur le parc — ni les espaces réels,
        // ni parc-locatif.csv. La vente n'est pas perdue pour autant :
        // elle s'enregistre sur la boutique technique, faute de
        // contenant connu pour la porter.
        $this->assertSame(1, $rapport->valeur(RapportImport::ESPACES_HORS_PARC));
        $this->assertCount(1, $rapport->horsParc());

        $technique = Boutique::where('numero', ServiceImportRegistre::BOUTIQUE_TECHNIQUE)->firstOrFail();
        $boite = Produit::where('designation', 'Boîte')->firstOrFail();

        $this->assertSame($technique->id, $boite->boutique_id);
    }

    public function test_une_occupation_refusee_ne_fait_pas_tomber_la_vente(): void
    {
        $rapport = $this->importer();

        // Crousti et Ngassam sont deux occupants distincts que la table
        // de correspondance envoie tous deux sur B0201. Le second
        // contrat chevauche le premier et le modèle le refuse, à juste
        // titre — mais la croquette a bien été vendue, et l'argent est
        // entré en caisse : le refus ne doit pas l'effacer.
        $this->assertSame(1, $rapport->valeur(RapportImport::OCCUPATIONS_REFUSEES));

        $b0201 = EspaceLocatif::where('code', 'B0201')->firstOrFail();
        $this->assertSame(1, AttributionEspace::where('espace_locatif_id', $b0201->id)->count());

        $this->assertSame(6, Vente::count());
    }

    public function test_le_corps_de_metier_vient_du_releve_et_non_du_cahier(): void
    {
        $this->importer();

        $bassi = Artisan::where('nom', 'Bassi')->firstOrFail();
        $agro = CorpsMetier::where('code', 'AGR')->firstOrFail();

        // Le registre ne porte jamais de métier ; c'est la colonne
        // « metier » de parc-locatif.csv, rangée sous les secteurs
        // officiels, qui le fournit.
        $this->assertSame($agro->id, $bassi->corps_metier_id);

        // Un déposant sans ligne dans parc-locatif.csv reste sans
        // secteur, plutôt que d'en recevoir un inventé.
        $nonInstallee = Artisan::where('nom', 'Non installée')->firstOrFail();
        $this->assertNull($nonInstallee->corps_metier_id);
    }

    public function test_la_redevance_relevee_est_figee_sur_l_attribution(): void
    {
        $this->importer();

        $bassi = Artisan::where('nom', 'Bassi')->firstOrFail();
        $attribution = AttributionEspace::where('artisan_id', $bassi->id)->firstOrFail();

        // Le forfait vient de parc-locatif.csv, jamais d'une surface
        // (A-01).
        $this->assertSame(3000, (int) $attribution->redevance_convenue);
    }

    public function test_l_artisan_est_rattache_a_l_espace_que_la_table_designe(): void
    {
        $this->importer();

        $bassi = Artisan::where('nom', 'Bassi')->firstOrFail();
        $attribution = AttributionEspace::where('artisan_id', $bassi->id)->firstOrFail();

        $this->assertSame('B0101', $attribution->espaceLocatif->code);
        $this->assertSame(StatutAttribution::ACTIVE, $attribution->statut);
        $this->assertSame($this->exercice->id, $attribution->exercice_id);

        // L'occupation commence à la plus ancienne vente relevée pour
        // cet occupant, pas au jour de l'import.
        $this->assertSame('2026-02-03', $attribution->date_debut->toDateString());
    }

    public function test_le_prix_du_produit_est_celui_de_la_premiere_occurrence(): void
    {
        $this->importer();

        $miel = Produit::where('designation', 'Miel')->firstOrFail();

        // Vendu 5 000 puis 3 000 F : la fiche retient le premier prix
        // rencontré.
        $this->assertSame(5000, (int) round((float) $miel->prix_unitaire));

        // La seconde vente porte bien le montant réellement encaissé ce
        // jour-là, et non le prix du catalogue (RG-10).
        $venteDuQuatre = Vente::whereDate('date_vente', '2026-02-04')->firstOrFail();
        $this->assertSame(3000, (int) $venteDuQuatre->montant_total);

        $this->assertNotEmpty($miel->reference);
        $this->assertStringStartsWith('BTQ', $miel->reference);
        $this->assertNull($miel->categorie_id);
    }

    public function test_chaque_ligne_produit_un_depot_puis_une_vente_et_laisse_le_stock_a_zero(): void
    {
        $this->importer();

        $this->assertSame(6, Depot::count());
        $this->assertSame(6, Depot::valide()->count());

        $miel = Produit::where('designation', 'Miel')->firstOrFail();
        $this->assertSame(0, $miel->getQuantiteEnStock());
    }

    public function test_elle_est_relancable_sans_creer_de_doublon(): void
    {
        $this->importer();

        $ventes = Vente::count();
        $produits = Produit::count();
        $artisans = Artisan::count();
        $attributions = AttributionEspace::count();

        $second = $this->importer();

        $this->assertSame(7, $second->valeur(RapportImport::LIGNES_TRAITEES));
        $this->assertSame(7, $second->valeur(RapportImport::LIGNES_DEJA_REPRISES));
        $this->assertSame(0, $second->valeur(RapportImport::LIGNES_IMPORTEES));

        $this->assertSame($ventes, Vente::count());
        $this->assertSame($produits, Produit::count());
        $this->assertSame($artisans, Artisan::count());
        $this->assertSame($attributions, AttributionEspace::count());
        $this->assertSame(7, TraceLigneImportee::count());
    }

    public function test_les_ecritures_ecartees_par_rattachements_csv_ne_produisent_rien(): void
    {
        // Ajoute une écriture « A ARBITRER » et une « NON ARTISAN » au
        // jeu d'essai, pour vérifier qu'aucune des deux ne produit de
        // vente ni d'artisan — le lecteur les exclut avant même que
        // `estVendable()` ne soit interrogée.
        File::put($this->repertoire.'/registre.csv', File::get($this->repertoire.'/registre.csv')
            ."8;2026-02-16;oui;Sac;2000;À trancher;;;0\n"
            ."9;2026-02-17;oui;Don;500;Espace du village;;;0\n");

        File::put($this->repertoire.'/rattachements.csv', File::get($this->repertoire.'/rattachements.csv')
            ."À trancher;1;2000;A ARBITRER;;;0.6;Ambiguïté réelle\n"
            ."Espace du village;1;500;NON ARTISAN;;;0.0;Pas une personne\n");

        $import = app(ServiceImportRegistre::class);
        $rapport = $import->importer($this->registre, seuil: 85.0, marge: 10.0);

        // Les deux écritures écartées ne deviennent jamais des
        // LigneRegistre : elles n'atteignent pas la boucle qui compte
        // les lignes traitées, et c'est délibéré — le rapport porte sur
        // ce qu'il y avait à traiter, pas sur ce qu'un rattachement a
        // refusé en amont.
        $this->assertSame(7, $rapport->valeur(RapportImport::LIGNES_TRAITEES));
        $this->assertSame(6, $rapport->valeur(RapportImport::LIGNES_IMPORTEES));
        $this->assertSame(0, Artisan::where('nom', 'À trancher')->count());
        $this->assertSame(0, Artisan::where('nom', 'Espace du village')->count());
        $this->assertSame(0, Vente::whereHas('lignes', fn ($r) => $r->where('designation', 'Sac'))->count());

        $this->assertCount(2, $import->lecteur()->dernieresExclusions);
    }

    public function test_la_commande_exporte_le_rapport_en_csv(): void
    {
        $this->artisan('varbaf:importer', [
            'fichier' => $this->registre,
            '--compte' => $this->compte->email,
            '--rapport' => $this->repertoireRapports,
        ])->assertSuccessful();

        $fichiers = File::files($this->repertoireRapports);

        $this->assertCount(2, $fichiers);

        $synthese = collect($fichiers)->first(fn ($fichier) => ! str_contains($fichier->getFilename(), 'signalements'));
        $contenu = File::get($synthese->getPathname());

        $this->assertStringContainsString('Lignes traitées', $contenu);
        $this->assertStringContainsString('Attributions créées', $contenu);
    }

    public function test_la_commande_refuse_de_partir_sans_compte_reel(): void
    {
        $this->artisan('varbaf:importer', [
            'fichier' => $this->registre,
            '--compte' => 'inconnu@varbaf.local',
        ])->assertFailed();

        $this->assertSame(0, Vente::count());
    }
}
