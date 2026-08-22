<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\EtatVente;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Services\ServiceMouvementStock;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Tresorerie\Filament\Pages\ManageCaisseSession;
use Modules\Tresorerie\Filament\Resources\CaisseResource\Pages\ManageCaisses;
use Modules\Tresorerie\Livewire\MouvementsCaisseTable;
use Modules\Tresorerie\Livewire\VentesCaisseTable;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\LibelleMouvement;
use Modules\Tresorerie\Models\MouvementCaisse;
use Modules\Tresorerie\Models\SectionCaisse;
use Tests\TestCase;

/**
 * Écran de session de caisse : accès, en-tête, onglets, lecture seule
 * et cohérence de l'annulation d'une vente.
 */
class SessionCaisseTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $exercice;

    protected Caisse $caisse;

    protected SectionCaisse $sectionOuverte;

    protected Boutique $boutique;

    protected Produit $produit;

    protected Utilisateur $caissier;

    protected Utilisateur $vendeuse;

    protected function setUp(): void
    {
        parent::setUp();

        // Jeu réel : village, exercice, permissions, rôles, caisse et
        // section déjà ouverte (SuperUtilisateurSeeder, CaisseSeeder,
        // SectionCaisseSeeder).
        $this->seed();

        Filament::setCurrentPanel('admin');

        $this->village = VillageArtisanal::query()->firstOrFail();
        $this->exercice = Exercice::query()->where('en_cours', true)->firstOrFail();
        $this->caisse = Caisse::query()->where('code', 'CAISSE-PRINCIPALE')->firstOrFail();
        $this->sectionOuverte = SectionCaisse::query()->where('caisse_id', $this->caisse->id)->firstOrFail();

        $corpsMetier = CorpsMetier::query()->firstOrCreate(['code' => 'VAN'], ['libelle' => 'Vannerie']);
        $artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);
        $this->boutique = Boutique::query()->where('numero', 'B-12')->firstOrFail();

        $categorie = CategorieProduit::query()->where('code', 'VAN-PAN')->firstOrFail();
        $this->produit = Produit::create([
            'designation' => 'Panier tressé',
            'prix_unitaire' => 3000,
            'categorie_id' => $categorie->id,
            'artisan_id' => $artisan->id,
            'boutique_id' => $this->boutique->id,
        ]);
        $this->produit->changerStatut(StatutValidationProduit::VALIDE);
        app(ServiceMouvementStock::class)->deposer($this->produit->fresh(), 10);
        $this->produit->refresh();

        // Le taux provisoire du seeder (10 % au 01/01/2026) suffit : la
        // date du test lui est postérieure.

        $agentCaissier = Agent::create([
            'nom' => 'Talla',
            'prenom' => 'Marie',
            'fonction' => 'Chef de section Administrative et Financière',
            'actif' => true,
            'village_id' => $this->village->id,
        ]);
        $this->caissier = Utilisateur::create([
            'name' => 'Marie Talla',
            'email' => 'marie.talla@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
            'agent_id' => $agentCaissier->id,
        ]);
        $this->caissier->assignRole('chef_section_administrative_financiere');

        $agentVendeuse = Agent::create([
            'nom' => 'Ngassa',
            'prenom' => 'Alice',
            'fonction' => 'Agent commercial',
            'actif' => true,
            'village_id' => $this->village->id,
        ]);
        $this->vendeuse = Utilisateur::create([
            'name' => 'Alice Ngassa',
            'email' => 'alice@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
            'agent_id' => $agentVendeuse->id,
        ]);
        $this->vendeuse->assignRole('chef_section_promotion_commercialisation');
    }

    // === ACCÈS ===

    public function test_l_action_de_ligne_ouvre_la_session_sur_la_section_ouverte(): void
    {
        $this->actingAs($this->caissier);

        $url = ManageCaisseSession::getUrl([
            'caisse' => $this->caisse->id,
            'section' => $this->sectionOuverte->id,
        ]);

        $this->assertStringContainsString(
            "/caisses/{$this->caisse->id}/session/{$this->sectionOuverte->id}",
            $url,
        );

        Livewire::test(ManageCaisses::class)
            ->assertTableActionVisible('session', $this->caisse);
    }

    /**
     * `ManageCaisseSession` a `shouldRegisterNavigation = false` : Filament
     * ne consulte donc `canAccess()` que pour construire la navigation,
     * jamais pour une requête directe sur l'URL. Sans garde explicite
     * dans `mount()`, un compte connecté sans aucune permission pourrait
     * atteindre l'écran rien qu'en connaissant l'URL.
     */
    public function test_un_compte_sans_permission_n_atteint_pas_la_page_par_l_url(): void
    {
        $sansRole = Utilisateur::create([
            'name' => 'Compte sans rôle',
            'email' => 'sans-role-caisse@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
        ]);

        $this->actingAs($sansRole);

        $url = ManageCaisseSession::getUrl([
            'caisse' => $this->caisse->id,
            'section' => $this->sectionOuverte->id,
        ]);

        $this->get($url)->assertForbidden();
    }

    /**
     * Changer de section dans la liste déroulante doit naviguer vers
     * l'URL de cette section — c'est ce qui garantit qu'un
     * rafraîchissement (F5) ne perd pas le contexte, l'URL restant la
     * seule source de vérité plutôt qu'un état Livewire local.
     */
    public function test_changer_de_section_navigue_vers_l_url_de_cette_section(): void
    {
        $this->actingAs($this->caissier);

        $this->sectionOuverte->cloturer();

        $autreSection = SectionCaisse::create([
            'caisse_id' => $this->caisse->id,
            'libelle' => 'Section suivante',
            'date_ouverture' => now(),
            'solde_ouverture' => 0,
            'etat' => 'OUVERTE',
            'ouverte_par' => $this->caissier->id,
            'village_id' => $this->village->id,
            'exercice_id' => $this->exercice->id,
        ]);

        Livewire::test(ManageCaisseSession::class, [
            'caisse' => $this->caisse->id,
            'section' => $this->sectionOuverte->id,
        ])
            ->set('selectedSectionId', $autreSection->id)
            ->assertRedirect(ManageCaisseSession::getUrl([
                'caisse' => $this->caisse->id,
                'section' => $autreSection->id,
            ]));
    }

    // === EN-TÊTE ===

    public function test_la_page_affiche_caisse_caissier_exercice_et_section_ouverte_par_defaut(): void
    {
        $this->actingAs($this->caissier);

        $composant = Livewire::test(ManageCaisseSession::class, [
            'caisse' => $this->caisse->id,
        ])->assertSuccessful();

        $this->assertSame($this->caisse->id, $composant->instance()->caisseId);
        $this->assertSame($this->sectionOuverte->id, $composant->instance()->selectedSectionId);
        $this->assertSame($this->exercice->id, $composant->instance()->exercice()->id);

        $composant->assertSee($this->caisse->libelle)
            ->assertSee('Section ouverte');
    }

    public function test_section_cloturee_affiche_le_solde_de_cloture_et_le_bandeau_lecture_seule(): void
    {
        $this->actingAs($this->caissier);

        $this->sectionOuverte->cloturer();

        $composant = Livewire::test(ManageCaisseSession::class, [
            'caisse' => $this->caisse->id,
            'section' => $this->sectionOuverte->id,
        ])->assertSuccessful();

        $composant->assertSee('Section clôturée — consultation seule')
            ->assertSee('Solde de clôture');
    }

    public function test_aucune_section_ouverte_propose_l_ouverture_plutot_qu_un_ecran_vide(): void
    {
        $this->actingAs($this->caissier);

        $this->sectionOuverte->cloturer();

        Livewire::test(ManageCaisseSession::class, [
            'caisse' => $this->caisse->id,
        ])
            ->assertSuccessful()
            ->assertSee('Aucune section n\'est actuellement ouverte', escape: false)
            ->assertActionVisible('ouvrir_section');
    }

    public function test_une_caisse_sans_aucune_section_propose_aussi_l_ouverture(): void
    {
        $this->actingAs($this->caissier);

        $caisseVierge = Caisse::create([
            'code' => 'CAISSE-DEUX',
            'libelle' => 'Deuxième caisse',
            'etat' => 'ACTIVE',
            'village_id' => $this->village->id,
        ]);

        Livewire::test(ManageCaisseSession::class, ['caisse' => $caisseVierge->id])
            ->assertSuccessful()
            ->assertSee('Aucune section de caisse n\'a été créée', escape: false)
            ->assertActionVisible('ouvrir_section');
    }

    // === LECTURE SEULE — défense côté serveur ===

    public function test_un_appel_direct_a_la_saisie_de_mouvement_sur_section_fermee_renvoie_un_message_lisible(): void
    {
        $this->actingAs($this->caissier);

        $this->sectionOuverte->cloturer();

        $libelle = LibelleMouvement::query()->where('code', 'REDEVANCE')->firstOrFail();

        Livewire::test(MouvementsCaisseTable::class, ['sectionId' => $this->sectionOuverte->id])
            ->call('creerMouvement', [
                'libelle_mouvement_id' => $libelle->id,
                'sens' => 'ENTREE',
                'montant' => 5000,
                'piece_justificative' => null,
            ])
            ->assertNotified('Section clôturée');

        $this->assertSame(
            0,
            MouvementCaisse::query()->where('section_id', $this->sectionOuverte->id)->count(),
            'Aucun mouvement ne doit avoir été créé sur une section clôturée.'
        );
    }

    public function test_un_appel_direct_a_la_saisie_de_vente_sur_section_fermee_renvoie_un_message_lisible(): void
    {
        $this->actingAs($this->vendeuse);

        $this->sectionOuverte->cloturer();

        Livewire::test(VentesCaisseTable::class, ['sectionId' => $this->sectionOuverte->id])
            ->call('creerVente', [
                'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
                'mode_reglement' => 'ESPECES',
            ])
            ->assertNotified('Section clôturée');

        $this->assertSame(
            0,
            \Modules\Commerce\Models\Vente::query()->count(),
            'Aucune vente ne doit avoir été créée sur une section clôturée.'
        );
    }

    public function test_les_boutons_de_saisie_disparaissent_sur_une_section_cloturee(): void
    {
        $this->actingAs($this->caissier);

        $this->sectionOuverte->cloturer();

        Livewire::test(MouvementsCaisseTable::class, ['sectionId' => $this->sectionOuverte->id])
            ->assertTableActionHidden('creer_mouvement');

        $this->actingAs($this->vendeuse);

        Livewire::test(VentesCaisseTable::class, ['sectionId' => $this->sectionOuverte->id])
            ->assertTableActionHidden('creer_vente');
    }

    // === COHÉRENCE ===

    public function test_une_vente_annulee_passe_a_l_etat_annulee_et_produit_une_contrepassation_visible_au_brouillard(): void
    {
        $this->actingAs($this->vendeuse);

        $venteComponent = Livewire::test(VentesCaisseTable::class, ['sectionId' => $this->sectionOuverte->id]);
        $venteComponent->call('creerVente', [
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
            'mode_reglement' => 'ESPECES',
        ]);

        $vente = \Modules\Commerce\Models\Vente::query()->firstOrFail();
        $mouvementVente = MouvementCaisse::query()
            ->where('origine_type', 'Vente')
            ->where('origine_id', $vente->id)
            ->firstOrFail();

        $venteComponent->call('annulerVente', $vente->id, 'Erreur de saisie, test');

        $this->assertSame(EtatVente::ANNULEE, $vente->fresh()->etat);

        $contrepassation = MouvementCaisse::query()
            ->where('mouvement_contrepasse_id', $mouvementVente->id)
            ->first();

        $this->assertNotNull($contrepassation, 'La contre-passation doit apparaître au brouillard.');
        $this->assertSame('SORTIE', $contrepassation->sens->value);
        $this->assertSame($mouvementVente->montant, $contrepassation->montant);
    }
}
