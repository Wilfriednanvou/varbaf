<?php

namespace Tests\Feature;

use App\Import\ServiceSegmentationExercices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Enums\StatutParticipationArtisan;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\AttributionEspace;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Artisanat\Models\EspaceLocatif;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Depot;
use Modules\Commerce\Models\LigneDepot;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Models\TauxCommission;
use Modules\Commerce\Services\ServiceVente;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Enums\StatutExercice;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\SectionCaisse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Segmentation par exercice réel de données déjà en base — voir
 * `ServiceSegmentationExercices` pour le motif complet.
 *
 * Le jeu d'essai reproduit le cas qui a motivé cette classe : un seul
 * exercice existant (2026) porte des ventes de deux années
 * antérieures, dont un artisan actif sur les trois.
 */
class SegmentationExercicesTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $exercice2026;

    protected Artisan $artisanMultiAnnees;

    protected Artisan $artisanSeulement2025;

    protected Artisan $artisanParcSansVente;

    protected Produit $produitMultiAnnees;

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

        $this->exercice2026 = Exercice::create([
            'libelle' => '2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'village_id' => $this->village->id,
        ]);
        $this->exercice2026->activer();

        TauxCommission::create([
            'taux' => 10.00,
            'date_effet' => '2023-01-01',
            'reference_decision' => 'Note de service de test',
            'village_id' => $this->village->id,
        ]);

        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);
        $boutique = Boutique::create(['numero' => 'B01', 'village_id' => $this->village->id]);
        $espace = EspaceLocatif::create(['boutique_id' => $boutique->id]);
        $categorie = CategorieProduit::create(['code' => 'VAN-PAN', 'libelle' => 'Paniers']);

        $agent = Agent::create([
            'nom' => 'Ngassa', 'prenom' => 'Alice', 'fonction' => 'Agent commercial',
            'actif' => true, 'village_id' => $this->village->id,
        ]);
        $utilisateur = Utilisateur::create([
            'name' => 'Alice Ngassa', 'email' => 'alice@varbaf.local',
            'password' => 'motdepasse', 'actif' => true, 'agent_id' => $agent->id,
        ]);
        Role::create(['name' => Utilisateur::ROLE_SUPER_UTILISATEUR, 'guard_name' => 'web']);
        $utilisateur->assignRole(Utilisateur::ROLE_SUPER_UTILISATEUR);
        $this->actingAs($utilisateur);

        // Artisan actif sur trois années.
        $this->artisanMultiAnnees = Artisan::create(['nom' => 'Multi', 'corps_metier_id' => $corpsMetier->id, 'village_id' => $this->village->id]);

        // Artisan qui n'a jamais vendu qu'en 2025 — c'est celui dont la
        // participation initiale (posée sur 2026 à sa création) est
        // fausse et doit être corrigée.
        $this->artisanSeulement2025 = Artisan::create(['nom' => 'Seulement2025', 'corps_metier_id' => $corpsMetier->id, 'village_id' => $this->village->id]);

        // Artisan du parc complété, sans aucune vente : ne doit pas être
        // touché par la reconstruction.
        $this->artisanParcSansVente = Artisan::create(['nom' => 'ParcSansVente', 'corps_metier_id' => $corpsMetier->id, 'village_id' => $this->village->id]);

        $this->produitMultiAnnees = Produit::create([
            'designation' => 'Panier tressé', 'prix_unitaire' => 4000, 'categorie_id' => $categorie->id,
            'artisan_id' => $this->artisanMultiAnnees->id, 'boutique_id' => $boutique->id,
        ]);
        $this->produitMultiAnnees->changerStatut(StatutValidationProduit::VALIDE);

        $produit2025 = Produit::create([
            'designation' => 'Chapeau', 'prix_unitaire' => 2000, 'categorie_id' => $categorie->id,
            'artisan_id' => $this->artisanSeulement2025->id, 'boutique_id' => $boutique->id,
        ]);
        $produit2025->changerStatut(StatutValidationProduit::VALIDE);

        AttributionEspace::create([
            'date_debut' => '2024-08-01', 'redevance_convenue' => 3000, 'statut' => StatutAttribution::ACTIVE,
            'artisan_id' => $this->artisanMultiAnnees->id, 'espace_locatif_id' => $espace->id,
            'exercice_id' => $this->exercice2026->id,
        ]);

        $caisse = Caisse::create(['code' => 'CAISSE-TEST', 'libelle' => 'Caisse de test', 'etat' => 'ACTIVE', 'village_id' => $this->village->id]);
        SectionCaisse::create([
            'caisse_id' => $caisse->id, 'libelle' => 'Section de test', 'date_ouverture' => now(),
            'solde_ouverture' => 0, 'etat' => 'OUVERTE', 'ouverte_par' => $utilisateur->id,
            'village_id' => $this->village->id, 'exercice_id' => $this->exercice2026->id,
        ]);

        $ventes = app(ServiceVente::class);

        $deposer = function (Produit $produit, Artisan $artisan, string $date) use ($boutique): void {
            $depot = Depot::create([
                'date_depot' => $date,
                'artisan_id' => $artisan->id,
                'boutique_id' => $boutique->id,
                'exercice_id' => $this->exercice2026->id,
            ]);
            LigneDepot::create(['depot_id' => $depot->id, 'produit_id' => $produit->id, 'quantite' => 10]);
            $depot->valider();
        };

        $deposer($this->produitMultiAnnees, $this->artisanMultiAnnees, '2024-08-01');
        $ventes->enregistrer([['produit_id' => $this->produitMultiAnnees->id, 'quantite' => 1]], dateVente: '2024-08-01');

        $deposer($this->produitMultiAnnees, $this->artisanMultiAnnees, '2025-06-15');
        $ventes->enregistrer([['produit_id' => $this->produitMultiAnnees->id, 'quantite' => 1]], dateVente: '2025-06-15');

        $deposer($this->produitMultiAnnees, $this->artisanMultiAnnees, '2026-01-10');
        $ventes->enregistrer([['produit_id' => $this->produitMultiAnnees->id, 'quantite' => 1]], dateVente: '2026-01-10');

        $deposer($produit2025, $this->artisanSeulement2025, '2025-03-20');
        $ventes->enregistrer([['produit_id' => $produit2025->id, 'quantite' => 1]], dateVente: '2025-03-20');
    }

    public function test_elle_cree_les_exercices_2024_et_2025(): void
    {
        app(ServiceSegmentationExercices::class)->segmenter();

        $this->assertNotNull(Exercice::where('libelle', '2024')->first());
        $this->assertNotNull(Exercice::where('libelle', '2025')->first());
    }

    public function test_les_ventes_sont_reaffectees_par_annee_reelle(): void
    {
        app(ServiceSegmentationExercices::class)->segmenter();

        $e2024 = Exercice::where('libelle', '2024')->firstOrFail();
        $e2025 = Exercice::where('libelle', '2025')->firstOrFail();

        $this->assertSame(1, \Modules\Commerce\Models\Vente::where('exercice_id', $e2024->id)->count());
        $this->assertSame(2, \Modules\Commerce\Models\Vente::where('exercice_id', $e2025->id)->count());
        $this->assertSame(1, \Modules\Commerce\Models\Vente::where('exercice_id', $this->exercice2026->fresh()->id)->count());
    }

    public function test_les_depots_suivent_la_meme_reaffectation(): void
    {
        app(ServiceSegmentationExercices::class)->segmenter();

        $e2024 = Exercice::where('libelle', '2024')->firstOrFail();
        $this->assertSame(1, \Modules\Commerce\Models\Depot::where('exercice_id', $e2024->id)->count());
    }

    public function test_l_attribution_suit_l_annee_de_sa_date_debut(): void
    {
        app(ServiceSegmentationExercices::class)->segmenter();

        $e2024 = Exercice::where('libelle', '2024')->firstOrFail();
        $attribution = AttributionEspace::where('artisan_id', $this->artisanMultiAnnees->id)->firstOrFail();

        $this->assertSame($e2024->id, $attribution->exercice_id);
    }

    public function test_un_artisan_multi_annees_obtient_une_participation_par_annee(): void
    {
        app(ServiceSegmentationExercices::class)->segmenter();

        $participations = $this->artisanMultiAnnees->participationsExercices()->get();
        $this->assertCount(3, $participations);

        foreach ($participations as $participation) {
            $this->assertSame(StatutParticipationArtisan::ACTIF, $participation->statut);
        }
    }

    public function test_un_artisan_dont_la_seule_vente_est_en_2025_n_est_plus_dit_actif_en_2026(): void
    {
        app(ServiceSegmentationExercices::class)->segmenter();

        $e2025 = Exercice::where('libelle', '2025')->firstOrFail();
        $participations = $this->artisanSeulement2025->participationsExercices()->get();

        $this->assertCount(1, $participations);
        $this->assertSame($e2025->id, $participations->first()->exercice_id);
    }

    public function test_un_artisan_sans_aucune_vente_n_est_pas_touche(): void
    {
        $avant = $this->artisanParcSansVente->participationsExercices()->get();

        app(ServiceSegmentationExercices::class)->segmenter();

        $apres = $this->artisanParcSansVente->fresh()->participationsExercices()->get();

        $this->assertCount($avant->count(), $apres);
        if ($avant->isNotEmpty()) {
            $this->assertSame($avant->first()->exercice_id, $apres->first()->exercice_id);
        }
    }

    public function test_un_produit_multi_annees_obtient_une_participation_par_annee(): void
    {
        app(ServiceSegmentationExercices::class)->segmenter();

        $this->assertCount(3, $this->produitMultiAnnees->participationsExercices()->get());
    }

    public function test_2024_et_2025_sont_clotures_2026_reste_actif(): void
    {
        app(ServiceSegmentationExercices::class)->segmenter();

        $e2024 = Exercice::where('libelle', '2024')->firstOrFail();
        $e2025 = Exercice::where('libelle', '2025')->firstOrFail();

        $this->assertSame(StatutExercice::CLOTURE, $e2024->statut);
        $this->assertSame(StatutExercice::CLOTURE, $e2025->statut);

        $e2026 = $this->exercice2026->fresh();
        $this->assertSame(StatutExercice::ACTIF, $e2026->statut);
        $this->assertTrue($e2026->en_cours);
    }

    public function test_elle_est_relancable_sans_effet_supplementaire(): void
    {
        app(ServiceSegmentationExercices::class)->segmenter();

        $ventesParExercice = \Modules\Commerce\Models\Vente::selectRaw('exercice_id, count(*) as n')->groupBy('exercice_id')->pluck('n', 'exercice_id')->all();
        $participationsMulti = $this->artisanMultiAnnees->participationsExercices()->count();

        $rapport = app(ServiceSegmentationExercices::class)->segmenter();

        $this->assertSame(0, $rapport['ventes_reaffectees']['2024']);
        $this->assertSame(0, $rapport['ventes_reaffectees']['2025']);
        $this->assertSame(0, $rapport['ventes_reaffectees']['2026']);
        $this->assertSame(
            $ventesParExercice,
            \Modules\Commerce\Models\Vente::selectRaw('exercice_id, count(*) as n')->groupBy('exercice_id')->pluck('n', 'exercice_id')->all(),
        );
        $this->assertSame($participationsMulti, $this->artisanMultiAnnees->fresh()->participationsExercices()->count());
    }
}
