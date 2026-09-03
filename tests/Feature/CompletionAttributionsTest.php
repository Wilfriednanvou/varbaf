<?php

namespace Tests\Feature;

use App\Import\ServiceCompletionAttributions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\AttributionEspace;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Artisanat\Models\EspaceLocatif;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * Complétion du parc locatif pour les occupants que le registre des
 * ventes ne révèle pas (`varbaf:completer-attributions`) — voir
 * `ServiceCompletionAttributions` pour le motif complet.
 */
class CompletionAttributionsTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $exercice;

    protected Utilisateur $compte;

    protected string $fichier;

    protected EspaceLocatif $espaceDejaAttribue;

    protected EspaceLocatif $espaceALivrer;

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

        CorpsMetier::create(['code' => 'MEN', 'libelle' => 'Menuiserie', 'description' => '—']);

        $b01 = Boutique::create(['numero' => 'B01', 'village_id' => $this->village->id]);
        $b02 = Boutique::create(['numero' => 'B02', 'village_id' => $this->village->id]);

        $this->espaceDejaAttribue = EspaceLocatif::create(['boutique_id' => $b01->id]); // B0101
        $this->espaceALivrer = EspaceLocatif::create(['boutique_id' => $b02->id]);      // B0201

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

        $this->actingAs($this->compte);

        // Une attribution déjà posée par le registre : la complétion ne
        // doit pas y toucher.
        $artisanExistant = Artisan::create([
            'nom' => 'Bassi',
            'village_id' => $this->village->id,
        ]);

        AttributionEspace::create([
            'date_debut' => '2026-02-03',
            'redevance_convenue' => 3000,
            'statut' => StatutAttribution::ACTIVE,
            'artisan_id' => $artisanExistant->id,
            'espace_locatif_id' => $this->espaceDejaAttribue->id,
            'exercice_id' => $this->exercice->id,
        ]);

        $this->fichier = $this->ecrireLeReleve();
    }

    protected function ecrireLeReleve(): string
    {
        $repertoire = storage_path('framework/testing/parc-'.uniqid());
        File::ensureDirectoryExists($repertoire);

        $contenu = <<<'CSV'
        ligne_source;contenant;nature;espace;occupant;metier;redevance;du_2026;paye_2026;paye_mensuel_2026;ecart_paye;reste_2026
        1;B01;BOUTIQUE;B0101;Bassi;Production des vins;3000;36000;0;0;0;36000
        2;B02;BOUTIQUE;B0201;Coopérative des Artisans menuisiers;Coopérative des Artisans menuisiers;10000;120000;0;0;0;120000
        3;B13;BOUTIQUE;;MAKAMTE Bibiane;;10000;80000;30000;30000;0;50000
        CSV;

        $chemin = $repertoire.'/parc-locatif.csv';
        File::put($chemin, $contenu."\n");

        return $chemin;
    }

    public function test_un_espace_deja_attribue_par_le_registre_n_est_pas_touche(): void
    {
        $avant = $this->espaceDejaAttribue->attributions()->count();

        app(ServiceCompletionAttributions::class)->completer($this->fichier);

        $this->assertSame($avant, $this->espaceDejaAttribue->fresh()->attributions()->count());
        $this->assertSame(1, Artisan::where('nom', 'Bassi')->count());
    }

    public function test_un_occupant_sans_aucune_vente_recoit_une_attribution(): void
    {
        $rapport = app(ServiceCompletionAttributions::class)->completer($this->fichier);

        $cooperative = Artisan::where('nom', 'Coopérative des Artisans menuisiers')->firstOrFail();
        $attribution = AttributionEspace::where('artisan_id', $cooperative->id)->firstOrFail();

        $this->assertSame($this->espaceALivrer->id, $attribution->espace_locatif_id);
        $this->assertSame(10000, (int) $attribution->redevance_convenue);
        $this->assertSame(StatutAttribution::ACTIVE, $attribution->statut);

        // Le métier du relevé se range sous le secteur officiel.
        $men = CorpsMetier::where('code', 'MEN')->firstOrFail();
        $this->assertSame($men->id, $cooperative->corps_metier_id);

        $this->assertSame(1, $rapport['attributions_creees']);
    }

    public function test_la_date_d_entree_est_celle_du_debut_de_l_exercice(): void
    {
        app(ServiceCompletionAttributions::class)->completer($this->fichier);

        $cooperative = Artisan::where('nom', 'Coopérative des Artisans menuisiers')->firstOrFail();
        $attribution = AttributionEspace::where('artisan_id', $cooperative->id)->firstOrFail();

        $this->assertSame('2026-01-01', $attribution->date_debut->toDateString());
    }

    public function test_un_occupant_sans_code_d_espace_devient_un_artisan_sans_attribution(): void
    {
        $rapport = app(ServiceCompletionAttributions::class)->completer($this->fichier);

        $makamte = Artisan::where('nom', 'MAKAMTE Bibiane')->firstOrFail();
        $this->assertSame(0, AttributionEspace::where('artisan_id', $makamte->id)->count());
        $this->assertContains('MAKAMTE Bibiane', $rapport['occupants_sans_espace']);
    }

    public function test_elle_est_relancable_sans_creer_de_doublon(): void
    {
        app(ServiceCompletionAttributions::class)->completer($this->fichier);

        $attributions = AttributionEspace::count();
        $artisans = Artisan::count();

        $second = app(ServiceCompletionAttributions::class)->completer($this->fichier);

        $this->assertSame(0, $second['attributions_creees']);
        $this->assertSame(0, $second['artisans_crees']);
        $this->assertSame($attributions, AttributionEspace::count());
        $this->assertSame($artisans, Artisan::count());
    }
}
