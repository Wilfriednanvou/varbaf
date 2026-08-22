<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Enums\EtatBoutique;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Models\TauxCommission;
use Modules\Commerce\Models\Vente;
use Modules\Commerce\Services\ServiceMouvementStock;
use Modules\Commerce\Services\ServiceVente;
use Modules\Pilotage\Data\FiltreRapport;
use Modules\Pilotage\Services\RapportService;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Tresorerie\Enums\EtatSectionCaisse;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\CampagneReversement;
use Modules\Tresorerie\Models\LibelleMouvement;
use Modules\Tresorerie\Models\SectionCaisse;
use Modules\Tresorerie\Services\ServiceCampagneReversement;
use Tests\TestCase;

/**
 * Indicateurs du tableau de bord.
 *
 * Le jeu de données est volontairement minuscule et entièrement connu,
 * pour que chaque assertion porte sur un nombre qu'on peut recalculer de
 * tête :
 *
 * - Kamdem, boutique B-12, panier à 4 000 F — une vente, le 10/07 ;
 * - Fotso, boutique B-13, statue à 10 000 F — une vente de 2 unités,
 *   le 05/08 ;
 * - une troisième vente de 4 000 F, **annulée**, qui ne doit apparaître
 *   dans aucun indicateur.
 *
 * À 15 % de commission : 4 000 → 600 / 3 400, et 20 000 → 3 000 / 17 000.
 */
class RapportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;
    protected Exercice $exercice;
    protected Artisan $kamdem;
    protected Artisan $fotso;
    protected Boutique $boutiqueA;
    protected Boutique $boutiqueB;
    protected Produit $panier;
    protected Produit $statue;
    protected SectionCaisse $section;
    protected Utilisateur $vendeur;
    protected ServiceVente $ventes;
    protected RapportService $rapport;

    protected Vente $venteAnnulee;

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
            'taux' => 15.00,
            'date_effet' => '2026-01-01',
            'reference_decision' => 'Note de service de test',
            'village_id' => $this->village->id,
        ]);

        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);
        $categorie = CategorieProduit::create(['code' => 'VAN-PAN', 'libelle' => 'Paniers']);

        $this->kamdem = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $this->fotso = Artisan::create([
            'nom' => 'Fotso',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $this->boutiqueA = Boutique::create(['numero' => 'B-12', 'village_id' => $this->village->id]);
        $this->boutiqueB = Boutique::create(['numero' => 'B-13', 'village_id' => $this->village->id]);

        // Une boutique occupée sur deux : le taux d'occupation vaut 50 %.
        $this->boutiqueA->update(['etat' => EtatBoutique::OCCUPEE]);

        $this->panier = $this->creerProduit('Panier tressé', 4000, $categorie->id, $this->kamdem->id, $this->boutiqueA->id, null, 10);
        $this->statue = $this->creerProduit('Statue en bois', 10000, $categorie->id, $this->fotso->id, $this->boutiqueB->id, 20, 20);

        $agent = Agent::create([
            'nom' => 'Ngassa',
            'prenom' => 'Alice',
            'fonction' => 'Agent commercial',
            'actif' => true,
            'village_id' => $this->village->id,
        ]);

        $this->vendeur = Utilisateur::create([
            'name' => 'Alice Ngassa',
            'email' => 'alice@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
            'agent_id' => $agent->id,
        ]);

        $this->actingAs($this->vendeur);

        $caisse = Caisse::create([
            'code' => 'CAISSE-TEST',
            'libelle' => 'Caisse de test',
            'etat' => 'ACTIVE',
            'village_id' => $this->village->id,
        ]);

        $this->section = SectionCaisse::create([
            'caisse_id' => $caisse->id,
            'libelle' => 'Section test',
            'date_ouverture' => now(),
            'solde_ouverture' => 0,
            'etat' => 'OUVERTE',
            'ouverte_par' => $this->vendeur->id,
            'village_id' => $this->village->id,
            'exercice_id' => $this->exercice->id,
        ]);

        LibelleMouvement::create([
            'code' => 'REVERSEMENT',
            'libelle' => 'Reversement part artisan',
            'sens' => 'SORTIE',
            'actif' => true,
        ]);

        $this->ventes = app(ServiceVente::class);
        $this->rapport = app(RapportService::class);

        // === Le jeu connu ===
        $this->ventes->enregistrer(
            lignes: [['produit_id' => $this->panier->id, 'quantite' => 1]],
            client: ['provenance_client' => 'LOCAL'],
            dateVente: '2026-07-10',
        );

        $this->ventes->enregistrer(
            lignes: [['produit_id' => $this->statue->id, 'quantite' => 2]],
            dateVente: '2026-08-05',
        );

        // La vente qui ne doit compter nulle part.
        $this->venteAnnulee = $this->ventes->enregistrer(
            lignes: [['produit_id' => $this->panier->id, 'quantite' => 1]],
            dateVente: '2026-08-06',
        );
        $this->ventes->annuler($this->venteAnnulee, 'Erreur de saisie');
    }

    // === RECETTES ===

    public function test_le_chiffre_d_affaires_additionne_les_ventes_validees(): void
    {
        $this->assertSame(24000, $this->rapport->chiffreAffaires($this->toutLExercice()));
        $this->assertSame(2, $this->rapport->nombreDeVentes($this->toutLExercice()));
    }

    public function test_les_recettes_de_commission_sont_la_part_du_village(): void
    {
        // 600 sur la vente à 4 000, 3 000 sur celle à 20 000.
        $this->assertSame(3600, $this->rapport->recettesDeCommission($this->toutLExercice()));
    }

    public function test_les_dettes_envers_les_artisans_cumulent_les_parts_non_reversees(): void
    {
        // 3 400 + 17 000, la vente annulée exclue.
        $this->assertSame(20400, $this->rapport->dettesEnversLesArtisans());
    }

    // === TRÉSORERIE ===

    public function test_le_solde_de_caisse_suit_le_brouillard_contrepassation_comprise(): void
    {
        // 4 000 + 20 000 + 4 000 encaissés, moins la contre-passation de
        // 4 000 produite par l'annulation.
        $soldes = $this->rapport->soldesParCaisse();

        $this->assertCount(1, $soldes, 'Une seule caisse a une section ouverte.');
        $this->assertSame('CAISSE-TEST', $soldes[0]['code']);
        $this->assertSame(24000, $soldes[0]['solde']);
        $this->assertSame(24000, $this->rapport->soldeDeCaisseConsolide());
    }

    public function test_une_caisse_sans_section_ouverte_n_apparait_pas(): void
    {
        // Fermeture forcée : `cloturer()` refuse désormais une
        // section dont les journées ne sont pas arrêtées (RG-07), et
        // ce test veut seulement l'état « plus de section ouverte ».
        $this->section->forceFill([
            'etat' => EtatSectionCaisse::CLOTUREE,
            'date_cloture' => now(),
            'solde_cloture' => $this->section->soldeCourant(),
            'cloturee_par' => auth()->id(),
        ])->save();

        $this->assertSame([], $this->rapport->soldesParCaisse());
        $this->assertSame(0, $this->rapport->soldeDeCaisseConsolide());
    }

    // === VENTILATIONS ===

    public function test_les_ventes_se_ventilent_par_boutique(): void
    {
        $lignes = $this->rapport->ventesParBoutique($this->toutLExercice());

        $this->assertCount(2, $lignes);
        // Trié par montant décroissant : la statue d'abord.
        $this->assertSame('B-13', $lignes[0]['libelle']);
        $this->assertSame(20000, $lignes[0]['total']);
        $this->assertSame(1, $lignes[0]['nombre']);

        $this->assertSame('B-12', $lignes[1]['libelle']);
        $this->assertSame(4000, $lignes[1]['total']);
        $this->assertSame(
            1,
            $lignes[1]['nombre'],
            'La vente annulée de la boutique B-12 ne doit pas être comptée.',
        );
    }

    public function test_les_ventes_se_ventilent_par_artisan(): void
    {
        $lignes = $this->rapport->ventesParArtisan($this->toutLExercice());

        $this->assertCount(2, $lignes);
        $this->assertSame('Fotso', $lignes[0]['libelle']);
        $this->assertSame(20000, $lignes[0]['total']);
        $this->assertSame('Kamdem', $lignes[1]['libelle']);
        $this->assertSame(4000, $lignes[1]['total']);
    }

    public function test_les_ventes_se_ventilent_par_vendeur(): void
    {
        $lignes = $this->rapport->ventesParVendeur($this->toutLExercice());

        $this->assertCount(1, $lignes);
        $this->assertSame('Ngassa', $lignes[0]['libelle']);
        $this->assertSame(24000, $lignes[0]['total']);
        $this->assertSame(2, $lignes[0]['nombre']);
    }

    public function test_la_provenance_non_renseignee_forme_une_ligne_a_part(): void
    {
        $lignes = $this->rapport->ventesParProvenanceClient($this->toutLExercice());

        $libelles = array_column($lignes, 'libelle');

        $this->assertContains('Local', $libelles);
        $this->assertContains('Non renseignée', $libelles);

        $this->assertSame(
            24000,
            array_sum(array_column($lignes, 'total')),
            'La ventilation par provenance doit totaliser le chiffre d\'affaires.',
        );
    }

    // === PARC ET CATALOGUE ===

    public function test_le_taux_d_occupation_rapporte_les_boutiques_occupees_au_parc(): void
    {
        $occupation = $this->rapport->tauxOccupationBoutiques();

        $this->assertSame(1, $occupation['occupees']);
        $this->assertSame(2, $occupation['total']);
        $this->assertSame(50.0, $occupation['taux']);
    }

    public function test_les_produits_sous_le_seuil_sont_comptes_et_listes(): void
    {
        // La statue : seuil 20, dépôt 20, deux unités vendues → 18.
        // Le panier n'a pas de seuil : il n'est pas surveillé.
        $this->assertSame(1, $this->rapport->nombreDeProduitsSousLeSeuil());

        $produits = $this->rapport->produitsSousLeSeuil();

        $this->assertCount(1, $produits);
        $this->assertSame('Statue en bois', $produits[0]['designation']);
        $this->assertSame(18, $produits[0]['stock']);
        $this->assertSame(20, $produits[0]['seuil']);
        $this->assertSame('B-13', $produits[0]['boutique']);
    }

    // === FILTRES ===

    public function test_l_intervalle_de_dates_borne_le_chiffre_d_affaires(): void
    {
        $aout = new FiltreRapport(
            exerciceId: $this->exercice->id,
            du: \Illuminate\Support\Carbon::parse('2026-08-01'),
        );

        $this->assertSame(20000, $this->rapport->chiffreAffaires($aout));

        $juillet = new FiltreRapport(
            exerciceId: $this->exercice->id,
            au: \Illuminate\Support\Carbon::parse('2026-07-31'),
        );

        $this->assertSame(4000, $this->rapport->chiffreAffaires($juillet));
    }

    public function test_un_autre_exercice_ne_voit_aucune_vente(): void
    {
        $autre = Exercice::create([
            'libelle' => '2025',
            'date_debut' => '2025-01-01',
            'date_fin' => '2025-12-31',
            'village_id' => $this->village->id,
        ]);

        $this->assertSame(0, $this->rapport->chiffreAffaires(new FiltreRapport(exerciceId: $autre->id)));
        $this->assertSame([], $this->rapport->ventesParBoutique(new FiltreRapport(exerciceId: $autre->id)));
    }

    // === DERNIER REVERSEMENT ===

    public function test_le_dernier_reversement_est_celui_de_la_derniere_campagne_validee(): void
    {
        $this->assertSame(0, $this->rapport->montantDernierReversement());
        $this->assertNull($this->rapport->dernierReversement());

        $campagnes = app(ServiceCampagneReversement::class);

        $campagne = $campagnes->valider(
            $campagnes->preparer(CampagneReversement::create([
                'periode' => '2026-08-01',
                'date_arrete' => '2026-08-31',
                'exercice_id' => $this->exercice->id,
            ]))
        );

        // Les deux parts artisan : 3 400 + 17 000.
        $this->assertSame(20400, $campagne->montant_total);
        $this->assertSame(20400, $this->rapport->montantDernierReversement());

        // Et la dette tombe à zéro : les ventes sont rattachées.
        $this->assertSame(0, $this->rapport->dettesEnversLesArtisans());
    }

    // === L'INVARIANT ===

    /**
     * La question que ce module doit savoir traiter : une vente annulée
     * n'est pas une vente corrigée dans les états, elle en disparaît.
     */
    public function test_une_vente_annulee_n_est_comptee_dans_aucun_indicateur(): void
    {
        $filtre = $this->toutLExercice();

        // Si l'annulée comptait, le chiffre serait 28 000 et non 24 000.
        $this->assertSame(24000, $this->rapport->chiffreAffaires($filtre));
        $this->assertSame(3600, $this->rapport->recettesDeCommission($filtre));
        $this->assertSame(2, $this->rapport->nombreDeVentes($filtre));
        $this->assertSame(20400, $this->rapport->dettesEnversLesArtisans());

        $boutiqueA = collect($this->rapport->ventesParBoutique($filtre))->firstWhere('libelle', 'B-12');
        $this->assertSame(4000, $boutiqueA['total']);

        $kamdem = collect($this->rapport->ventesParArtisan($filtre))->firstWhere('libelle', 'Kamdem');
        $this->assertSame(4000, $kamdem['total']);

        $vendeur = collect($this->rapport->ventesParVendeur($filtre))->firstWhere('libelle', 'Ngassa');
        $this->assertSame(24000, $vendeur['total']);

        $this->assertSame(
            24000,
            array_sum(array_column($this->rapport->ventesParProvenanceClient($filtre), 'total')),
        );

        // Et le stock du panier est bien revenu : 10 déposés, une seule
        // vente ferme.
        $this->assertSame(9, $this->panier->fresh()->getQuantiteEnStock());
    }

    // === HELPERS ===

    protected function toutLExercice(): FiltreRapport
    {
        return new FiltreRapport(exerciceId: $this->exercice->id);
    }

    protected function creerProduit(
        string $designation,
        int $prix,
        int $categorieId,
        int $artisanId,
        int $boutiqueId,
        ?int $seuil,
        int $depot,
    ): Produit {
        $produit = Produit::create([
            'designation' => $designation,
            'prix_unitaire' => $prix,
            'seuil_alerte' => $seuil,
            'categorie_id' => $categorieId,
            'artisan_id' => $artisanId,
            'boutique_id' => $boutiqueId,
        ]);

        $produit->changerStatut(StatutValidationProduit::VALIDE);
        app(ServiceMouvementStock::class)->deposer($produit->fresh(), $depot);

        return $produit->fresh();
    }
}
