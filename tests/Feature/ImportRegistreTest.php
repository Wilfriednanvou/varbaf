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
 * Reprise du registre de ventes transcrit.
 *
 * Le registre réel du village n'est pas rejoué ici : mille cent
 * quarante-neuf lignes mettraient plusieurs minutes et ne prouveraient
 * rien de plus. Le fichier ci-dessous est un extrait taillé pour porter
 * une fois chacune des difficultés qu'on y rencontre — un nom
 * orthographié de deux façons, un artisan absent, un code hors parc, un
 * guillemet de répétition, une date sans année, un total qui ne tombe
 * pas juste, une ligne trop lacunaire pour devenir une vente.
 */
class ImportRegistreTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $exercice;

    protected Utilisateur $compte;

    protected string $registre;

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

        // Deux locaux du parc, et rien de plus : tout le reste de ce que
        // le registre nomme devra se ranger hors parc.
        Boutique::create(['numero' => 'B01', 'village_id' => $this->village->id]);
        Boutique::create(['numero' => 'B02', 'village_id' => $this->village->id]);

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

        // Le Gate::before du Socle ouvre toutes les permissions au
        // super-utilisateur. La reprise n'en a besoin que d'une —
        // `valider_produit`, vérifiée par `ServiceValidationProduit` —
        // mais passer par le rôle plutôt que par la permission nominale
        // vérifie au passage que la commande emprunte bien le chemin
        // d'habilitation ordinaire, et non une porte dérobée.
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

        $this->repertoireRapports = storage_path('framework/testing/imports-'.uniqid());
        $this->registre = $this->ecrireLeRegistre();

        $this->actingAs($this->compte);
    }

    protected function tearDown(): void
    {
        File::delete($this->registre);
        File::deleteDirectory($this->repertoireRapports);

        parent::tearDown();
    }

    protected function ecrireLeRegistre(): string
    {
        $lignes = <<<'CSV'
        date,code_boutique_source,code_boutique_normalise,nom_artisan_source,designation,conditionnement,quantite,prix_unitaire,montant,coherence,vendeur_reference
        2026-02-03,No 1,B01,Bassi,Miel,Bouteille,2,2500,5000,OK,Marie
        2026-02-04,B-01,B01,BASSIE,Miel,Bouteille,1,2500,2500,OK,Marie
        2026-02-05,B 2,B02,,Curcuma,Sachet,1,1000,1000,OK,
        2026-02-06,Hall,HALL,Gabrielle,Vin d'Avocat,Bouteille,3,3000,9000,OK,Payé
        2026-02-07,B19,B19,Doriane,Chapeau,,1,5000,5000,OK,
        2026-02-08,b 01,B01,Bassi,Miel,Bouteille,2,3000,6000,OK,Marie
        2026-02-09,B 2,B02,Crousti delice,Croquette,Sachet,3,500,1500,OK,
        2026-02-10,B 2,B02,Crousti Delice NGASSAM,Croquette,Sachet,1,500,500,OK,
        2026-02-11,B-01,B01,Bassi,Savon,,1,7500,1500,ECART,
        12/02,B 2,B02,Bassi,Fève,,1,1000,1000,OK,
        "-""-","-""-","-""-","-""-","-""-","-""-",2,1000,2000,OK,
        2026-02-13,B-01,B01,Bassi,,,1,1000,1000,OK,
        2026-02-14,B-01,B01,Bassi,Beurre,,,,,,
        2026-02-15,B-01,B01,Bassi,Chocolat,Boite,,1000,3000,OK,
        CSV;

        $chemin = storage_path('framework/testing/registre-'.uniqid().'.csv');

        File::ensureDirectoryExists(dirname($chemin));
        File::put($chemin, $lignes."\n");

        return $chemin;
    }

    protected function importer(): RapportImport
    {
        return app(ServiceImportRegistre::class)->importer($this->registre, seuil: 85.0, marge: 10.0);
    }

    // =================================================================

    public function test_il_compte_les_lignes_traitees_importees_et_signalees(): void
    {
        $rapport = $this->importer();

        $this->assertSame(14, $rapport->valeur(RapportImport::LIGNES_TRAITEES));

        // Deux lignes ne peuvent pas devenir des ventes : celle dont la
        // désignation manque, et celle qui n'a ni quantité, ni prix, ni
        // montant. Elles sont signalées et tracées, jamais écartées en
        // silence.
        $this->assertSame(2, $rapport->valeur(RapportImport::LIGNES_NON_IMPORTEES));
        $this->assertSame(12, $rapport->valeur(RapportImport::LIGNES_IMPORTEES));
        $this->assertSame(12, Vente::count());

        $this->assertGreaterThan(0, $rapport->valeur(RapportImport::LIGNES_SIGNALEES));
        $this->assertSame(14, TraceLigneImportee::count());
        $this->assertSame(2, TraceLigneImportee::where('statut', TraceLigneImportee::STATUT_NON_IMPORTEE)->count());
    }

    public function test_une_ligne_sans_quantite_est_signalee_et_non_rejetee(): void
    {
        $rapport = $this->importer();

        // Quantité absente, prix et montant présents : la quantité se
        // déduit des deux autres et la ligne devient une vente.
        $chocolat = Produit::where('designation', 'Chocolat')->firstOrFail();
        $ligne = $chocolat->lignesDepot()->firstOrFail();

        $this->assertSame(3, $ligne->quantite);
        $this->assertGreaterThanOrEqual(1, $rapport->valeur(RapportImport::LIGNES_VALEURS_DEDUITES));

        // Les trois valeurs absentes : la ligne reste tracée, avec ses
        // anomalies, et sans vente.
        $beurre = TraceLigneImportee::where('numero_ligne', 13)->firstOrFail();
        $this->assertSame(TraceLigneImportee::STATUT_NON_IMPORTEE, $beurre->statut);
        $this->assertNull($beurre->vente_id);
        $this->assertNotEmpty($beurre->anomalies);
    }

    public function test_il_rapproche_les_ecritures_proches_et_signale_celles_qui_restent_sous_le_seuil(): void
    {
        $rapport = $this->importer();

        // « Bassi » et « BASSIE » se ressemblent au-delà du seuil : une
        // seule fiche, sous la forme la plus fréquente.
        $this->assertSame(1, Artisan::where('nom', 'Bassi')->count());
        $this->assertSame(0, Artisan::where('nom', 'BASSIE')->count());
        $this->assertSame(1, $rapport->valeur(RapportImport::ARTISANS_REGROUPES));

        // « Crousti delice » et « Crousti Delice NGASSAM » restent deux
        // fiches, mais le rapport dit que le rapprochement a été écarté.
        $this->assertSame(1, Artisan::where('nom', 'Crousti delice')->count());
        $this->assertSame(1, Artisan::where('nom', 'Crousti Delice NGASSAM')->count());

        $doutes = collect($rapport->doutes())->pluck('nom')->all();
        $this->assertContains('Crousti Delice NGASSAM', $doutes);
    }

    public function test_une_ligne_sans_artisan_va_sur_non_identifie_sans_nom_suppose(): void
    {
        $rapport = $this->importer();

        $anonyme = Artisan::where('nom', ServiceImportRegistre::ARTISAN_NON_IDENTIFIE)->firstOrFail();

        $curcuma = Produit::where('designation', 'Curcuma')->firstOrFail();
        $this->assertSame($anonyme->id, $curcuma->artisan_id);

        $this->assertSame(1, $rapport->valeur(RapportImport::LIGNES_SANS_ARTISAN));

        // Le registre ne porte pas le secteur d'activité : la colonne
        // reste vide plutôt que de recevoir un secteur inventé.
        $this->assertNull($anonyme->corps_metier_id);
    }

    public function test_les_codes_hors_boutiques_vont_sur_une_boutique_technique(): void
    {
        $rapport = $this->importer();

        $technique = Boutique::where('numero', ServiceImportRegistre::BOUTIQUE_TECHNIQUE)->firstOrFail();

        $libelles = EspaceLocatif::where('boutique_id', $technique->id)->pluck('libelle')->all();

        $this->assertContains('HALL', $libelles);

        // B19 a la forme d'un code de boutique mais ne correspond à
        // aucun local du parc : le créer gonflerait le parc et fausserait
        // tout taux d'occupation.
        $this->assertContains('B19', $libelles);
        $this->assertSame(0, Boutique::where('numero', 'B19')->count());

        $this->assertSame(2, $rapport->valeur(RapportImport::ESPACES_HORS_PARC));
        $this->assertCount(2, $rapport->horsParc());
    }

    public function test_il_regroupe_les_ecritures_de_boutiques(): void
    {
        $rapport = $this->importer();

        // « No 1 », « B-01 » et « b 01 » désignent le même local.
        $this->assertGreaterThanOrEqual(1, $rapport->valeur(RapportImport::BOUTIQUES_REGROUPEES));
        $this->assertSame(4, $rapport->valeur(RapportImport::BOUTIQUES_RETENUES));
    }

    public function test_chaque_artisan_recoit_son_propre_espace_locatif_et_une_attribution(): void
    {
        $this->importer();

        $b02 = Boutique::where('numero', 'B02')->firstOrFail();

        // Quatre artisans vendent depuis B02 : quatre espaces, et non
        // quatre attributions concurrentes sur le même.
        $this->assertSame(4, EspaceLocatif::where('boutique_id', $b02->id)->count());

        $bassi = Artisan::where('nom', 'Bassi')->firstOrFail();

        $attributions = AttributionEspace::where('artisan_id', $bassi->id)->get();

        // Bassi occupe B01 et B02 : deux attributions, chacune sur son
        // espace, sans chevauchement.
        $this->assertCount(2, $attributions);

        foreach ($attributions as $attribution) {
            $this->assertSame(StatutAttribution::ACTIVE, $attribution->statut);
            $this->assertSame($this->exercice->id, $attribution->exercice_id);
            // Le registre ne dit pas la redevance négociée ; l'inventer
            // reviendrait à facturer un montant qui n'a jamais été
            // convenu.
            $this->assertNull($attribution->redevance_convenue);
        }

        // L'occupation commence à la plus ancienne vente relevée, pas au
        // jour de l'import.
        $enB01 = $attributions->first(
            fn (AttributionEspace $attribution) => $attribution->espaceLocatif->boutique_id !== $b02->id
        );
        $this->assertSame('2026-02-03', $enB01->date_debut->toDateString());
    }

    public function test_le_prix_du_produit_est_celui_de_la_premiere_occurrence(): void
    {
        $this->importer();

        $miel = Produit::where('designation', 'Miel')->firstOrFail();

        // Le miel a été vendu 2 500 puis 3 000 F : la fiche produit
        // retient le premier prix rencontré.
        $this->assertSame(2500, (int) round((float) $miel->prix_unitaire));

        // La vente du 8 février porte le prix réellement pratiqué ce
        // jour-là : c'est le figement de RG-10, et non le prix du
        // catalogue.
        $venteDuHuit = Vente::whereDate('date_vente', '2026-02-08')->firstOrFail();
        $this->assertSame(3000, (int) $venteDuHuit->lignes()->firstOrFail()->prix_unitaire);
        $this->assertSame(6000, (int) $venteDuHuit->montant_total);

        // La référence est produite par le modèle, jamais saisie.
        $this->assertNotEmpty($miel->reference);
        $this->assertStringStartsWith('BTQ', $miel->reference);

        // Le registre ne porte pas la famille du produit.
        $this->assertNull($miel->categorie_id);
    }

    public function test_une_ligne_en_ecart_est_importee_telle_quelle_et_listee(): void
    {
        $rapport = $this->importer();

        $savon = Produit::where('designation', 'Savon')->firstOrFail();
        $vente = Vente::whereHas('lignes', fn ($requete) => $requete->where('produit_id', $savon->id))
            ->firstOrFail();

        // Ni le prix ni la quantité ne sont retouchés pour faire tomber
        // le total transcrit : le montant de la vente est celui qu'impose
        // l'invariant du système, et l'écart est consigné.
        $this->assertSame(7500, (int) $vente->montant_total);

        $this->assertSame(1, $rapport->valeur(RapportImport::ECARTS_A_LA_SOURCE));
        $this->assertGreaterThanOrEqual(1, $rapport->valeur(RapportImport::ECARTS_DE_CALCUL));

        $signalees = collect($rapport->signalements())->pluck('ligne')->all();
        $this->assertContains('9', $signalees);
    }

    public function test_les_guillemets_de_repetition_reprennent_la_ligne_precedente(): void
    {
        $this->importer();

        // La ligne 11 ne porte que des guillemets : elle reprend la date,
        // le local, l'artisan et la désignation de la ligne 10.
        $trace = TraceLigneImportee::where('numero_ligne', 11)->firstOrFail();
        $this->assertSame(TraceLigneImportee::STATUT_IMPORTEE, $trace->statut);

        $vente = Vente::findOrFail($trace->vente_id);
        $this->assertSame('2026-02-12', $vente->date_vente->toDateString());
        $this->assertSame('Fève', $vente->lignes()->firstOrFail()->designation);
    }

    public function test_une_date_sans_annee_reprend_celle_de_la_ligne_precedente(): void
    {
        $rapport = $this->importer();

        $trace = TraceLigneImportee::where('numero_ligne', 10)->firstOrFail();
        $vente = Vente::findOrFail($trace->vente_id);

        $this->assertSame('2026-02-12', $vente->date_vente->toDateString());
        $this->assertGreaterThanOrEqual(1, $rapport->valeur(RapportImport::LIGNES_SANS_DATE_PROPRE));
    }

    public function test_chaque_ligne_produit_un_depot_puis_une_vente_et_laisse_le_stock_a_zero(): void
    {
        $this->importer();

        $this->assertSame(12, Depot::count());
        $this->assertSame(12, Depot::valide()->count());

        $miel = Produit::where('designation', 'Miel')->firstOrFail();

        // Cinq unités déposées, cinq vendues : le journal de stock est
        // équilibré et n'est jamais passé par un solde négatif.
        $this->assertSame(0, $miel->getQuantiteEnStock());
    }

    public function test_elle_est_relancable_sans_creer_de_doublon(): void
    {
        $this->importer();

        $ventes = Vente::count();
        $produits = Produit::count();
        $artisans = Artisan::count();
        $espaces = EspaceLocatif::count();
        $attributions = AttributionEspace::count();

        $second = $this->importer();

        $this->assertSame(14, $second->valeur(RapportImport::LIGNES_TRAITEES));
        $this->assertSame(14, $second->valeur(RapportImport::LIGNES_DEJA_REPRISES));
        $this->assertSame(0, $second->valeur(RapportImport::LIGNES_IMPORTEES));

        $this->assertSame($ventes, Vente::count());
        $this->assertSame($produits, Produit::count());
        $this->assertSame($artisans, Artisan::count());
        $this->assertSame($espaces, EspaceLocatif::count());
        $this->assertSame($attributions, AttributionEspace::count());
        $this->assertSame(14, TraceLigneImportee::count());
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
        $this->assertStringContainsString('Lignes en écart de calcul', $contenu);
        $this->assertStringContainsString('Lignes sans artisan identifiable', $contenu);
        $this->assertStringContainsString('Écritures regroupées automatiquement', $contenu);
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
