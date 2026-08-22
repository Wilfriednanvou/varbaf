<?php

namespace Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Tresorerie\Enums\EtatSectionCaisse;
use Modules\Tresorerie\Enums\NatureMouvementCaisse;
use Modules\Tresorerie\Enums\StatutCampagneReversement;
use Modules\Tresorerie\Enums\StatutReversement;
use Modules\Tresorerie\Enums\TypeLigneReversement;
use Modules\Tresorerie\Exceptions\CampagneReversementException;
use Modules\Tresorerie\Exceptions\SectionCaisseException;
use Modules\Tresorerie\Filament\Resources\CampagneReversementResource\Pages\ManageCampagnesReversement;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\CampagneReversement;
use Modules\Tresorerie\Models\LibelleMouvement;
use Modules\Tresorerie\Models\MouvementCaisse;
use Modules\Tresorerie\Models\SectionCaisse;
use Modules\Tresorerie\Services\ServiceCampagneReversement;
use Tests\TestCase;

/**
 * Campagnes de reversement (RG-16 à RG-21).
 *
 * Les quatre situations qui font la valeur du module : une vente
 * antérieure rattrapée en régularisation (RG-19), un solde négatif qui
 * ne se paie pas et se reporte (RG-20), l'impossibilité de reverser
 * deux fois les mêmes ventes (RG-21), et l'atomicité de la validation —
 * des ventes marquées reversées sans que l'argent soit sorti seraient
 * découvertes le mois suivant, quand elles ne réapparaîtraient dans
 * aucune campagne.
 *
 * Prix unitaire 4 000 F à 15 % : commission 600, part artisan 3 400.
 */
class CampagneReversementTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;
    protected Exercice $exercice;
    protected Artisan $artisan;
    protected Produit $produit;
    protected Caisse $caisse;
    protected SectionCaisse $section;
    protected ServiceVente $ventes;
    protected ServiceCampagneReversement $service;

    /** Part revenant à l'artisan pour une unité vendue. */
    protected const PART_ARTISAN = 3400;

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

        $this->artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $boutique = Boutique::create(['numero' => 'B-12', 'village_id' => $this->village->id]);
        $categorie = CategorieProduit::create(['code' => 'VAN-PAN', 'libelle' => 'Paniers']);

        $this->produit = Produit::create([
            'designation' => 'Panier tressé',
            'prix_unitaire' => 4000,
            'categorie_id' => $categorie->id,
            'artisan_id' => $this->artisan->id,
            'boutique_id' => $boutique->id,
        ]);
        $this->produit->changerStatut(StatutValidationProduit::VALIDE);
        app(ServiceMouvementStock::class)->deposer($this->produit->fresh(), 20);
        $this->produit = $this->produit->fresh();

        $agent = Agent::create([
            'nom' => 'Ngassa',
            'prenom' => 'Alice',
            'fonction' => 'Agent commercial',
            'actif' => true,
            'village_id' => $this->village->id,
        ]);

        // Agent pour pouvoir vendre (RG-14), super-utilisateur pour
        // atteindre les actions de l'écran sans rejouer tout le
        // PermissionSeeder ici. La séparation des permissions elle-même
        // est éprouvée par `SeparationDesRolesTest`, à sa place.
        $utilisateur = Utilisateur::create([
            'name' => 'Alice Ngassa',
            'email' => 'alice@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
            'agent_id' => $agent->id,
        ]);

        \Spatie\Permission\Models\Role::create(['name' => Utilisateur::ROLE_SUPER_UTILISATEUR, 'guard_name' => 'web']);
        $utilisateur->assignRole(Utilisateur::ROLE_SUPER_UTILISATEUR);

        $this->actingAs($utilisateur);

        Filament::setCurrentPanel('admin');

        $this->caisse = Caisse::create([
            'code' => 'CAISSE-TEST',
            'libelle' => 'Caisse de test',
            'etat' => 'ACTIVE',
            'village_id' => $this->village->id,
        ]);

        $this->section = SectionCaisse::create([
            'caisse_id' => $this->caisse->id,
            'libelle' => 'Section test',
            'date_ouverture' => now(),
            'solde_ouverture' => 0,
            'etat' => 'OUVERTE',
            'ouverte_par' => $utilisateur->id,
            'village_id' => $this->village->id,
            'exercice_id' => $this->exercice->id,
        ]);

        // Le libellé que le décaissement reprendra au référentiel.
        LibelleMouvement::create([
            'code' => NatureMouvementCaisse::REVERSEMENT->value,
            'libelle' => 'Reversement part artisan',
            'sens' => 'SORTIE',
            'actif' => true,
        ]);

        $this->ventes = app(ServiceVente::class);
        $this->service = app(ServiceCampagneReversement::class);
    }

    // === RG-19 : régularisation d'une vente antérieure ===

    public function test_une_vente_anterieure_est_retenue_en_regularisation_avec_sa_date_d_origine(): void
    {
        $venteDeJuillet = $this->vendre('2026-07-10');
        $venteDAout = $this->vendre('2026-08-05');

        $campagne = $this->service->preparer($this->campagne('2026-08-01', '2026-08-31'));

        $reversement = $campagne->reversements()->with('lignes')->firstOrFail();

        $this->assertSame(
            self::PART_ARTISAN,
            $reversement->montant_periode,
            'La vente du mois de la campagne relève de la période.',
        );
        $this->assertSame(
            self::PART_ARTISAN,
            $reversement->montant_regularisation,
            'La vente de juillet, retenue par une campagne d\'août, est une régularisation (RG-19).',
        );

        $regularisation = $reversement->lignes
            ->firstWhere('type', TypeLigneReversement::REGULARISATION);

        $this->assertNotNull($regularisation, 'La régularisation doit apparaître au détail.');
        $this->assertSame($venteDeJuillet->id, $regularisation->vente_id);
        $this->assertSame(
            '2026-07-10',
            $regularisation->date_origine->toDateString(),
            'RG-19 exige la mention de la date d\'origine, pas celle de la campagne.',
        );

        $periode = $reversement->lignes->firstWhere('type', TypeLigneReversement::PERIODE);
        $this->assertNotNull($periode);
        $this->assertSame($venteDAout->id, $periode->vente_id);

        // Le total à payer additionne bien les deux.
        $this->assertSame(2 * self::PART_ARTISAN, $reversement->montant_paye);
        $this->assertSame(0, $reversement->solde_reporte);
    }

    // === RG-20 : solde négatif non payé et reporté ===

    public function test_un_solde_negatif_n_est_pas_paye_et_se_reporte(): void
    {
        $vente = $this->vendre('2026-07-10');

        // Campagne de juillet : l'artisan est payé.
        $juillet = $this->service->valider(
            $this->service->preparer($this->campagne('2026-07-01', '2026-07-31'))
        );

        $this->assertSame(self::PART_ARTISAN, $juillet->montant_total);
        $this->assertSame(1, $juillet->nombre_beneficiaires);

        $decaissementsAvant = $this->nombreDeDecaissements();

        // La vente est annulée le mois suivant : l'artisan a touché une
        // part qui n'est plus due.
        $this->ventes->annuler($vente, 'Client revenu sur son achat');

        $aout = $this->service->valider(
            $this->service->preparer($this->campagne('2026-08-01', '2026-08-31'))
        );

        $reversement = $aout->reversements()->firstOrFail();

        $this->assertSame(
            -self::PART_ARTISAN,
            $reversement->montant_regularisation,
            'La vente annulée après avoir été payée revient en reprise.',
        );
        $this->assertSame(0, $reversement->montant_paye, 'RG-20 : aucun décaissement sur un solde négatif.');
        $this->assertSame(-self::PART_ARTISAN, $reversement->solde_reporte);
        $this->assertSame(StatutReversement::REPORTE, $reversement->statut);
        $this->assertNull($reversement->mouvement_caisse_id);

        $this->assertSame(
            $decaissementsAvant,
            $this->nombreDeDecaissements(),
            'Un solde négatif ne produit aucun décaissement au brouillard.',
        );
    }

    public function test_une_reprise_ne_se_reprend_pas_a_chaque_campagne(): void
    {
        $vente = $this->vendre('2026-07-10');

        $this->service->valider(
            $this->service->preparer($this->campagne('2026-07-01', '2026-07-31'))
        );

        $this->ventes->annuler($vente, 'Client revenu sur son achat');

        // Août reprend la part indûment payée...
        $this->service->valider(
            $this->service->preparer($this->campagne('2026-08-01', '2026-08-31'))
        );

        // ...et septembre ne la reprend pas une seconde fois : elle ne
        // porte plus que le report du solde négatif d'août.
        $septembre = $this->service->preparer($this->campagne('2026-09-01', '2026-09-30'));

        $reversement = $septembre->reversements()->with('lignes')->firstOrFail();

        $this->assertCount(
            0,
            $reversement->lignes,
            'La reprise a déjà eu lieu : septembre ne retient plus aucune vente.',
        );
        $this->assertSame(
            -self::PART_ARTISAN,
            $reversement->montant_regularisation,
            'Il ne reste que le report du solde négatif d\'août, pas une seconde reprise.',
        );
    }

    // === RG-21 : pas de second reversement des mêmes ventes ===

    public function test_les_memes_ventes_ne_se_reversent_pas_deux_fois(): void
    {
        $vente = $this->vendre('2026-07-10');

        $juillet = $this->service->valider(
            $this->service->preparer($this->campagne('2026-07-01', '2026-07-31'))
        );

        $this->assertSame(
            $juillet->id,
            $vente->fresh()->campagne_reversement_id,
            'La validation rattache définitivement la vente (RG-21).',
        );

        $aout = $this->service->preparer($this->campagne('2026-08-01', '2026-08-31'));

        $this->assertSame(
            0,
            $aout->reversements()->count(),
            'Une vente déjà rattachée ne revient dans aucune campagne suivante.',
        );

        $this->expectException(CampagneReversementException::class);

        $this->service->valider($aout);
    }

    public function test_une_campagne_validee_ne_se_prepare_ni_ne_se_valide_a_nouveau(): void
    {
        $this->vendre('2026-08-05');

        $campagne = $this->service->valider(
            $this->service->preparer($this->campagne('2026-08-01', '2026-08-31'))
        );

        $this->assertSame(StatutCampagneReversement::VALIDEE, $campagne->statut);

        $this->expectException(CampagneReversementException::class);

        $this->service->preparer($campagne);
    }

    // === ATOMICITÉ DE LA VALIDATION ===

    /**
     * Le rattachement des ventes est posé avant les décaissements, dans
     * la même transaction. Si une écriture en caisse échoue — ici parce
     * que la section est clôturée — le rattachement doit disparaître
     * avec elle. Sans quoi les ventes seraient marquées « reversées »
     * sans que l'argent soit sorti, et ne réapparaîtraient dans aucune
     * campagne.
     */
    public function test_une_validation_qui_echoue_ne_laisse_aucune_trace(): void
    {
        $vente = $this->vendre('2026-08-05');

        $campagne = $this->service->preparer($this->campagne('2026-08-01', '2026-08-31'));

        $decaissementsAvant = $this->nombreDeDecaissements();

        // La section est fermée de force, sans passer par
        // `cloturer()` : depuis RG-07, celle-ci refuse de clôturer
        // une section dont les journées ne sont pas arrêtées, et ce
        // test a justement besoin de mouvements non arrêtés. On veut
        // ici l'état, pas le geste métier — même procédé que
        // `MouvementCaisseTest`.
        $this->section->forceFill([
            'etat' => EtatSectionCaisse::CLOTUREE,
            'date_cloture' => now(),
            'solde_cloture' => $this->section->soldeCourant(),
            'cloturee_par' => auth()->id(),
        ])->save();

        try {
            $this->service->valider($campagne, $this->section);
            $this->fail('Valider sur une section clôturée doit échouer.');
        } catch (SectionCaisseException) {
            // Attendu.
        }

        $this->assertNull(
            $vente->fresh()->campagne_reversement_id,
            'Le rattachement de la vente doit avoir été annulé avec la transaction.',
        );
        $this->assertSame(
            StatutCampagneReversement::EN_PREPARATION,
            $campagne->fresh()->statut,
            'La campagne doit rester en préparation.',
        );
        $this->assertSame(
            StatutReversement::A_PAYER,
            $campagne->reversements()->firstOrFail()->statut,
            'Aucun reversement ne doit avoir été marqué payé.',
        );
        $this->assertSame(
            $decaissementsAvant,
            $this->nombreDeDecaissements(),
            'Aucun décaissement ne doit subsister au brouillard.',
        );
    }

    // === DÉCAISSEMENT PAR LE BROUILLARD (RG-06, RG-18) ===

    public function test_le_decaissement_passe_par_le_brouillard_et_reference_le_reversement(): void
    {
        $this->vendre('2026-08-05');

        $campagne = $this->service->valider(
            $this->service->preparer($this->campagne('2026-08-01', '2026-08-31'))
        );

        $reversement = $campagne->reversements()->firstOrFail();

        $this->assertSame(StatutReversement::PAYE, $reversement->statut);
        $this->assertNotNull($reversement->mouvement_caisse_id);
        $this->assertNotNull($reversement->date_paiement);

        $mouvement = MouvementCaisse::findOrFail($reversement->mouvement_caisse_id);

        $this->assertSame('SORTIE', $mouvement->sens->value);
        $this->assertSame(self::PART_ARTISAN, $mouvement->montant);
        $this->assertSame(NatureMouvementCaisse::REVERSEMENT, $mouvement->nature);
        $this->assertSame('Reversement', $mouvement->origine_type);
        $this->assertSame($reversement->id, (int) $mouvement->origine_id);
    }

    // === LES ACTIONS DE L'ÉCRAN, APPELÉES ===

    /**
     * Vérifier qu'un bouton s'affiche ne prouve rien de ce qu'il fait :
     * c'est l'appel qui éprouve le `->action()`, où vivent la
     * délégation au service, l'audit et la notification.
     */
    public function test_l_action_preparer_calcule_effectivement_la_campagne(): void
    {
        $this->vendre('2026-08-05');

        $campagne = $this->campagne('2026-08-01', '2026-08-31');

        Livewire::test(ManageCampagnesReversement::class)
            ->callAction(TestAction::make('preparer')->table($campagne));

        $campagne->refresh();

        $this->assertSame(1, $campagne->reversements()->count());
        $this->assertSame(self::PART_ARTISAN, $campagne->montant_total);
        $this->assertSame(1, $campagne->nombre_beneficiaires);
        $this->assertNotNull($campagne->date_generation);
    }

    public function test_l_action_valider_decaisse_et_ferme_la_campagne(): void
    {
        $vente = $this->vendre('2026-08-05');

        $campagne = $this->service->preparer($this->campagne('2026-08-01', '2026-08-31'));

        Livewire::test(ManageCampagnesReversement::class)
            ->callAction(TestAction::make('valider')->table($campagne));

        $campagne->refresh();

        $this->assertSame(StatutCampagneReversement::VALIDEE, $campagne->statut);
        $this->assertNotNull($campagne->date_validation);
        $this->assertSame(1, $this->nombreDeDecaissements());
        $this->assertSame($campagne->id, $vente->fresh()->campagne_reversement_id);
    }

    /**
     * L'action ne doit pas laisser remonter une exception métier : elle
     * la transforme en message lisible, comme les autres écrans du
     * module.
     */
    public function test_l_action_valider_sur_une_campagne_vide_renvoie_un_message(): void
    {
        $campagne = $this->service->preparer($this->campagne('2026-08-01', '2026-08-31'));

        Livewire::test(ManageCampagnesReversement::class)
            ->callAction(TestAction::make('valider')->table($campagne))
            ->assertNotified('Validation impossible');

        $this->assertSame(
            StatutCampagneReversement::EN_PREPARATION,
            $campagne->fresh()->statut,
        );
    }

    // === HELPERS ===

    protected function vendre(string $date): Vente
    {
        return $this->ventes->enregistrer(
            lignes: [['produit_id' => $this->produit->id, 'quantite' => 1]],
            dateVente: $date,
        );
    }

    protected function campagne(string $periode, string $dateArrete): CampagneReversement
    {
        return CampagneReversement::create([
            'periode' => $periode,
            'date_arrete' => $dateArrete,
            'exercice_id' => $this->exercice->id,
        ]);
    }

    protected function nombreDeDecaissements(): int
    {
        return MouvementCaisse::query()
            ->where('nature', NatureMouvementCaisse::REVERSEMENT->value)
            ->count();
    }
}
