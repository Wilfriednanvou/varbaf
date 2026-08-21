<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Contracts\JournalDeCaisse;
use Modules\Commerce\Enums\EtatVente;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Enums\TypeMouvementStock;
use Modules\Commerce\Exceptions\VenteInvalideException;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\LigneVente;
use Modules\Commerce\Models\MouvementStock;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Models\TauxCommission;
use Modules\Commerce\Models\Vente;
use Modules\Commerce\Services\JournalDeCaisseEnAttente;
use Modules\Commerce\Services\ServiceMouvementStock;
use Modules\Commerce\Services\ServiceVente;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * Enregistrement des ventes : figement, commission, atomicité.
 */
class VenteTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Boutique $boutique;

    protected Boutique $autreBoutique;

    protected Artisan $artisan;

    protected Produit $produit;

    protected ServiceVente $service;

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

        Exercice::create([
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

        $this->artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $this->boutique = Boutique::create(['numero' => 'B-12', 'village_id' => $this->village->id]);
        $this->autreBoutique = Boutique::create(['numero' => 'B-03', 'village_id' => $this->village->id]);

        $categorie = CategorieProduit::create(['code' => 'VAN-PAN', 'libelle' => 'Paniers']);

        $this->produit = $this->creerProduit($categorie, 'Panier tressé', 3333, $this->boutique);

        // Le vendeur est constaté depuis le compte connecté (RG-14).
        $agent = Agent::create([
            'nom' => 'Ngassa',
            'prenom' => 'Alice',
            'fonction' => 'Agent commercial',
            'actif' => true,
            'village_id' => $this->village->id,
        ]);

        $vendeur = Utilisateur::create([
            'name' => 'Alice Ngassa',
            'email' => 'alice@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
            'agent_id' => $agent->id,
        ]);

        $this->actingAs($vendeur);

        $this->service = app(ServiceVente::class);
    }

    protected function creerProduit(CategorieProduit $categorie, string $designation, int $prix, Boutique $boutique, int $stock = 10): Produit
    {
        $produit = Produit::create([
            'designation' => $designation,
            'prix_unitaire' => $prix,
            'categorie_id' => $categorie->id,
            'artisan_id' => $this->artisan->id,
            'boutique_id' => $boutique->id,
        ]);

        $produit->changerStatut(StatutValidationProduit::VALIDE);

        if ($stock > 0) {
            app(ServiceMouvementStock::class)->deposer($produit->fresh(), $stock);
        }

        return $produit->fresh();
    }

    // ===================================================================
    // Arrondi
    // ===================================================================

    /**
     * Le cas demandé : 3 333 F à 15 % ne tombe pas juste — 499,95.
     * Un arrondi sur la commission, la part artisan par différence, et
     * les deux totalisent exactement le montant encaissé.
     */
    public function test_la_commission_est_arrondie_et_la_part_artisan_obtenue_par_difference(): void
    {
        $vente = $this->service->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 1],
        ]);

        $this->assertSame('3333.00', $vente->montant_total);
        $this->assertSame('500.00', $vente->montant_commission);
        $this->assertSame('2833.00', $vente->part_artisan);

        $this->assertSame(
            (float) $vente->montant_total,
            (float) $vente->montant_commission + (float) $vente->part_artisan,
            'Commission et part artisan doivent totaliser exactement le montant encaissé.'
        );
    }

    public function test_l_invariant_de_repartition_tient_sur_des_montants_qui_ne_tombent_pas_juste(): void
    {
        foreach ([[3333, 15], [999, 12.5], [7, 15], [1, 15], [1234567, 10]] as [$montant, $taux]) {
            [$commission, $partArtisan] = $this->service->repartir($montant, $taux);

            $this->assertSame(
                (float) $montant,
                $commission + $partArtisan,
                "La répartition de {$montant} F à {$taux} % ne totalise pas le montant encaissé."
            );
            $this->assertSame(
                $commission,
                round($commission),
                'La commission doit être un nombre entier de francs.'
            );
        }
    }

    public function test_le_taux_est_fige_sur_la_vente(): void
    {
        $vente = $this->service->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 1],
        ]);

        $this->assertSame('15.00', $vente->taux_commission);

        // Un nouveau taux plus tard ne réécrit pas la vente passée.
        TauxCommission::create([
            'taux' => 5.00,
            'date_effet' => now()->addMonth()->toDateString(),
            'village_id' => $this->village->id,
        ]);

        $this->assertSame('15.00', $vente->fresh()->taux_commission);
    }

    // ===================================================================
    // Figement
    // ===================================================================

    public function test_les_lignes_recopient_la_reference_le_libelle_et_le_prix(): void
    {
        $vente = $this->service->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 2],
        ]);

        $ligne = $vente->lignes()->first();

        $this->assertSame($this->produit->reference, $ligne->reference_produit);
        $this->assertSame('Panier tressé', $ligne->designation);
        $this->assertSame('3333.00', $ligne->prix_unitaire);
        $this->assertSame('6666.00', $ligne->montant_ligne);
    }

    public function test_renommer_le_produit_ne_change_pas_la_vente_passee(): void
    {
        $vente = $this->service->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 1],
        ]);

        $this->produit->update(['designation' => 'Grand panier de cérémonie', 'prix_unitaire' => 9000]);

        $ligne = $vente->fresh()->lignes()->first();

        $this->assertSame('Panier tressé', $ligne->designation);
        $this->assertSame('3333.00', $ligne->prix_unitaire);
    }

    public function test_une_vente_enregistree_ne_peut_pas_etre_modifiee(): void
    {
        $vente = $this->service->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 1],
        ]);

        $this->expectException(VenteInvalideException::class);

        $vente->update(['montant_total' => 1]);
    }

    public function test_une_vente_ne_peut_pas_etre_supprimee(): void
    {
        $vente = $this->service->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 1],
        ]);

        $this->expectException(VenteInvalideException::class);

        $vente->delete();
    }

    public function test_une_ligne_de_vente_ne_peut_pas_etre_retouchee(): void
    {
        $vente = $this->service->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 1],
        ]);

        $this->expectException(VenteInvalideException::class);

        $vente->lignes()->first()->update(['quantite' => 99]);
    }

    // ===================================================================
    // Stock et caisse
    // ===================================================================

    public function test_la_vente_sort_le_stock_par_le_service(): void
    {
        $this->service->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 3],
        ]);

        $this->assertSame(7, $this->produit->fresh()->getQuantiteEnStock());

        $mouvement = MouvementStock::where('type', TypeMouvementStock::VENTE->value)->firstOrFail();
        $this->assertSame(3, $mouvement->quantite);
        $this->assertSame('Vente', $mouvement->origine_type);
    }

    public function test_une_vente_au_dela_du_stock_est_refusee(): void
    {
        $this->expectException(\Modules\Commerce\Exceptions\StockInsuffisantException::class);

        $this->service->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 11],
        ]);
    }

    /**
     * Le brouillard n'existe pas encore, mais l'appel est observable :
     * c'est ce qui permet de vérifier aujourd'hui ce que la Trésorerie
     * consommera la semaine prochaine.
     */
    public function test_l_encaissement_est_depose_sur_le_port_de_la_caisse(): void
    {
        /** @var JournalDeCaisseEnAttente $caisse */
        $caisse = app(JournalDeCaisse::class);
        $caisse->oublier();

        $vente = $this->service->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 1],
        ]);

        $this->assertCount(1, $caisse->encaissements);
        $this->assertSame($vente->numero, $caisse->encaissements[0]['numero']);
        $this->assertSame('3333.00', $caisse->encaissements[0]['montant']);
        $this->assertFalse($caisse->estOperationnel());
    }

    // ===================================================================
    // Atomicité
    // ===================================================================

    /**
     * Le scénario redouté : la troisième écriture échoue.
     *
     * Sans transaction englobante, on obtiendrait un stock décrémenté et
     * des lignes de vente orphelines, découverts des semaines plus tard
     * à l'inventaire. Le test remplace le port de la caisse par une
     * implémentation qui lève, et vérifie qu'il ne reste rien : ni
     * vente, ni ligne, ni mouvement de stock.
     */
    public function test_l_echec_de_l_ecriture_en_caisse_annule_la_vente_entiere(): void
    {
        $stockAvant = $this->produit->getQuantiteEnStock();

        app()->instance(JournalDeCaisse::class, new class implements JournalDeCaisse
        {
            public function enregistrerEncaissementDeVente(\Modules\Commerce\Models\Vente $vente): ?int
            {
                throw new \RuntimeException('Caisse indisponible');
            }

            public function contrepasserEncaissementDeVente(\Modules\Commerce\Models\Vente $vente, string $motif): ?int
            {
                return null;
            }

            public function estOperationnel(): bool
            {
                return true;
            }
        });

        // Résolu après la substitution : le service reçoit la caisse
        // défaillante à la construction.
        $service = app(ServiceVente::class);

        try {
            $service->enregistrer([
                ['produit_id' => $this->produit->id, 'quantite' => 3],
            ]);
            $this->fail('L\'écriture en caisse aurait dû échouer.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Caisse indisponible', $exception->getMessage());
        }

        $this->assertSame(0, Vente::count(), 'Aucune vente ne doit subsister.');
        $this->assertSame(0, LigneVente::count(), 'Aucune ligne de vente ne doit subsister.');
        $this->assertSame(
            0,
            MouvementStock::where('type', TypeMouvementStock::VENTE->value)->count(),
            'Aucun mouvement de stock de vente ne doit subsister.'
        );
        $this->assertSame(
            $stockAvant,
            $this->produit->fresh()->getQuantiteEnStock(),
            'Le stock doit être exactement celui d\'avant la tentative.'
        );
    }

    // ===================================================================
    // Règles de composition
    // ===================================================================

    public function test_une_vente_ne_porte_que_sur_une_boutique(): void
    {
        $categorie = CategorieProduit::where('code', 'VAN-PAN')->firstOrFail();
        $ailleurs = $this->creerProduit($categorie, 'Natte de raphia', 5000, $this->autreBoutique);

        $this->expectException(VenteInvalideException::class);

        $this->service->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 1],
            ['produit_id' => $ailleurs->id, 'quantite' => 1],
        ]);
    }

    public function test_un_produit_non_valide_ne_peut_pas_etre_vendu(): void
    {
        $categorie = CategorieProduit::where('code', 'VAN-PAN')->firstOrFail();

        $soumis = Produit::create([
            'designation' => 'Corbeille en cours de validation',
            'prix_unitaire' => 4000,
            'categorie_id' => $categorie->id,
            'artisan_id' => $this->artisan->id,
            'boutique_id' => $this->boutique->id,
        ]);

        $this->expectException(VenteInvalideException::class);

        $this->service->enregistrer([
            ['produit_id' => $soumis->id, 'quantite' => 1],
        ]);
    }

    public function test_une_vente_sans_ligne_est_refusee(): void
    {
        $this->expectException(VenteInvalideException::class);

        $this->service->enregistrer([]);
    }

    public function test_un_compte_sans_agent_ne_peut_pas_vendre(): void
    {
        $sansAgent = Utilisateur::create([
            'name' => 'Compte sans agent',
            'email' => 'sans-agent@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
        ]);

        $this->actingAs($sansAgent);

        $this->expectException(VenteInvalideException::class);

        $this->service->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 1],
        ]);
    }

    // ===================================================================
    // Annulation
    // ===================================================================

    public function test_l_annulation_remet_le_stock_et_contrepasse_la_caisse(): void
    {
        /** @var JournalDeCaisseEnAttente $caisse */
        $caisse = app(JournalDeCaisse::class);
        $caisse->oublier();

        $vente = $this->service->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 4],
        ]);

        $this->assertSame(6, $this->produit->fresh()->getQuantiteEnStock());

        $this->service->annuler($vente, 'Client revenu sur son achat');

        $this->assertSame(EtatVente::ANNULEE, $vente->fresh()->etat);
        $this->assertSame(10, $this->produit->fresh()->getQuantiteEnStock());
        $this->assertCount(1, $caisse->contrepassations);

        // Les montants ne bougent pas : c'est l'état qui change.
        $this->assertSame('13332.00', $vente->fresh()->montant_total);
    }

    public function test_une_vente_ne_s_annule_pas_deux_fois(): void
    {
        $vente = $this->service->enregistrer([
            ['produit_id' => $this->produit->id, 'quantite' => 1],
        ]);

        $this->service->annuler($vente, 'Première annulation');

        $this->expectException(VenteInvalideException::class);

        $this->service->annuler($vente->fresh(), 'Seconde annulation');
    }

    public function test_le_numero_de_ticket_est_sequentiel_par_annee(): void
    {
        $premiere = $this->service->enregistrer([['produit_id' => $this->produit->id, 'quantite' => 1]]);
        $seconde = $this->service->enregistrer([['produit_id' => $this->produit->id, 'quantite' => 1]]);

        $this->assertMatchesRegularExpression('/^VTE-\d{4}-000001$/', $premiere->numero);
        $this->assertMatchesRegularExpression('/^VTE-\d{4}-000002$/', $seconde->numero);
    }
}
