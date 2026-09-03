<?php

namespace Tests\Feature;

use App\Import\ServiceImportRedevances;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\AttributionEspace;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\EspaceLocatif;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Tresorerie\Enums\NatureMouvementCaisse;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\LibelleMouvement;
use Modules\Tresorerie\Models\MouvementCaisse;
use Modules\Tresorerie\Models\SectionCaisse;
use Tests\TestCase;

/**
 * Reprise des redevances déjà encaissées (`varbaf:importer-redevances`)
 * — voir `ServiceImportRedevances` pour le motif complet.
 */
class ImportRedevancesTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Utilisateur $compte;

    protected AttributionEspace $attribution;

    protected string $fichier;

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

        $exercice = Exercice::create([
            'libelle' => '2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'en_cours' => true,
            'village_id' => $this->village->id,
        ]);

        $boutique = Boutique::create(['numero' => 'B01', 'village_id' => $this->village->id]);
        $espace = EspaceLocatif::create(['boutique_id' => $boutique->id]); // B0101

        $artisan = Artisan::create(['nom' => 'Bassi', 'village_id' => $this->village->id]);

        $this->attribution = AttributionEspace::create([
            'date_debut' => '2026-01-01',
            'redevance_convenue' => 3000,
            'statut' => StatutAttribution::ACTIVE,
            'artisan_id' => $artisan->id,
            'espace_locatif_id' => $espace->id,
            'exercice_id' => $exercice->id,
        ]);

        LibelleMouvement::create([
            'code' => NatureMouvementCaisse::REDEVANCE->value,
            'libelle' => 'Redevance boutique',
            'sens' => 'ENTREE',
            'actif' => true,
        ]);

        $agent = Agent::create([
            'nom' => 'Ngassa', 'prenom' => 'Alice', 'fonction' => 'Agent commercial',
            'actif' => true, 'village_id' => $this->village->id,
        ]);

        $this->compte = Utilisateur::create([
            'name' => 'Alice Ngassa', 'email' => 'alice@varbaf.local',
            'password' => 'motdepasse', 'actif' => true, 'agent_id' => $agent->id,
        ]);

        $this->actingAs($this->compte);

        $caisse = Caisse::create([
            'code' => 'CAISSE-TEST', 'libelle' => 'Caisse de test', 'etat' => 'ACTIVE',
            'village_id' => $this->village->id,
        ]);

        SectionCaisse::create([
            'caisse_id' => $caisse->id, 'libelle' => 'Section de test', 'date_ouverture' => now(),
            'solde_ouverture' => 0, 'etat' => 'OUVERTE', 'ouverte_par' => $this->compte->id,
            'village_id' => $this->village->id, 'exercice_id' => $exercice->id,
        ]);

        $this->fichier = $this->ecrireLeReleve();
    }

    protected function ecrireLeReleve(): string
    {
        $repertoire = storage_path('framework/testing/redevances-'.uniqid());
        File::ensureDirectoryExists($repertoire);

        $contenu = <<<'CSV'
        ligne_source;contenant;nature;espace;occupant;metier;redevance;du_2026;paye_2026;paye_mensuel_2026;ecart_paye;reste_2026
        1;B01;BOUTIQUE;B0101;Bassi;Production des vins;3000;36000;18000;18000;0;18000
        2;B04;BOUTIQUE;B0401;Discordant;;12000;0;0;36000;36000;0
        3;B13;BOUTIQUE;;MAKAMTE Bibiane;;10000;80000;30000;30000;0;50000
        4;B05;BOUTIQUE;B0501;Sans paiement;;12000;144000;0;0;0;0
        CSV;

        $chemin = $repertoire.'/parc-locatif.csv';
        File::put($chemin, $contenu."\n");

        return $chemin;
    }

    public function test_un_encaissement_est_cree_et_rattache_a_l_attribution(): void
    {
        app(ServiceImportRedevances::class)->importer($this->fichier);

        $mouvement = MouvementCaisse::where('nature', NatureMouvementCaisse::REDEVANCE->value)
            ->where('origine_id', $this->attribution->id)
            ->firstOrFail();

        $this->assertSame(18000, (int) $mouvement->montant);
        $this->assertSame('AttributionEspace', $mouvement->origine_type);
    }

    public function test_le_detail_mensuel_prime_sur_la_synthese_annuelle_en_cas_d_ecart(): void
    {
        $rapport = app(ServiceImportRedevances::class)->importer($this->fichier);

        $mouvement = MouvementCaisse::where('libelle', 'like', '%Discordant%')->firstOrFail();

        // La synthèse annuelle dit 0, le détail mensuel dit 36 000 : le
        // détail est retenu, et le désaccord est rapporté plutôt que tu.
        $this->assertSame(36000, (int) $mouvement->montant);
        $this->assertCount(1, $rapport['ecarts_paye']);
        $this->assertSame('Discordant', $rapport['ecarts_paye'][0]['occupant']);
    }

    public function test_un_occupant_sans_espace_produit_un_encaissement_sans_origine(): void
    {
        app(ServiceImportRedevances::class)->importer($this->fichier);

        $mouvement = MouvementCaisse::where('libelle', 'like', '%MAKAMTE%')->firstOrFail();

        $this->assertNull($mouvement->origine_id);
        $this->assertSame(30000, (int) $mouvement->montant);
    }

    public function test_une_ligne_sans_paiement_ne_produit_aucun_mouvement(): void
    {
        $rapport = app(ServiceImportRedevances::class)->importer($this->fichier);

        $this->assertSame(0, MouvementCaisse::where('libelle', 'like', '%Sans paiement%')->count());
        $this->assertSame(1, $rapport['sans_paiement']);
    }

    public function test_elle_est_relancable_sans_creer_de_doublon(): void
    {
        app(ServiceImportRedevances::class)->importer($this->fichier);

        $total = MouvementCaisse::where('nature', NatureMouvementCaisse::REDEVANCE->value)->count();

        $second = app(ServiceImportRedevances::class)->importer($this->fichier);

        $this->assertSame(0, $second['encaissements_crees']);
        $this->assertSame($total, MouvementCaisse::where('nature', NatureMouvementCaisse::REDEVANCE->value)->count());
    }
}
