<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\JournalAudit;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Tresorerie\Enums\EtatSectionCaisse;
use Modules\Tresorerie\Enums\NatureMouvementCaisse;
use Modules\Tresorerie\Enums\SensMouvementCaisse;
use Modules\Tresorerie\Filament\Pages\BrouillardCaissePage;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\LibelleMouvement;
use Modules\Tresorerie\Models\MouvementCaisse;
use Modules\Tresorerie\Models\SectionCaisse;
use Modules\Tresorerie\Services\ServiceTresorerie;
use Tests\TestCase;

/**
 * Écran du brouillard de caisse : accès, cloisonnement par section,
 * bornes de dates et trace de consultation.
 *
 * Le brouillard est un état de lecture, mais ce n'est pas un état
 * anodin : c'est la pièce qu'on sort quand une recette est contestée.
 * Trois choses doivent donc être vraies, et ce sont les trois que ces
 * tests tiennent. Il ne s'ouvre qu'à qui peut lire les mouvements de
 * caisse ; il ne montre que la section demandée, jamais celle d'à côté ;
 * et il laisse une trace de qui l'a consulté.
 */
class BrouillardCaissePageTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $exercice;

    protected Caisse $caisse;

    protected SectionCaisse $section;

    protected Utilisateur $caissier;

    protected MouvementCaisse $enJanvier;

    protected MouvementCaisse $enMars;

    protected function setUp(): void
    {
        parent::setUp();

        // Jeu réel : village, exercice, permissions, rôles, caisse
        // principale et section déjà ouverte.
        $this->seed();

        Filament::setCurrentPanel('admin');

        $this->village = VillageArtisanal::query()->firstOrFail();
        $this->exercice = Exercice::query()->where('en_cours', true)->firstOrFail();
        $this->caisse = Caisse::query()->where('code', 'CAISSE-PRINCIPALE')->firstOrFail();
        $this->section = SectionCaisse::query()->where('caisse_id', $this->caisse->id)->firstOrFail();

        $agent = Agent::create([
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
            'agent_id' => $agent->id,
        ]);

        $this->caissier->assignRole('chef_section_administrative_financiere');

        $this->actingAs($this->caissier);

        // Deux mouvements à deux mois d'écart : c'est ce qu'il faut pour
        // que les bornes de dates aient quelque chose à séparer.
        $this->enJanvier = $this->enregistrer('Redevance de janvier', '2026-01-10');
        $this->enMars = $this->enregistrer('Redevance de mars', '2026-03-14');
    }

    protected function enregistrer(string $libelle, string $date): MouvementCaisse
    {
        return app(ServiceTresorerie::class)->enregistrer(
            section: $this->section,
            nature: NatureMouvementCaisse::REDEVANCE,
            sens: SensMouvementCaisse::ENTREE,
            montant: 5_000,
            libelle: $libelle,
            libelleMouvement: LibelleMouvement::query()->where('code', 'REDEVANCE')->first(),
            dateOperation: new \DateTimeImmutable($date),
        );
    }

    protected function url(?Caisse $caisse = null, ?SectionCaisse $section = null): string
    {
        return BrouillardCaissePage::getUrl([
            'caisse' => ($caisse ?? $this->caisse)->id,
            'section' => ($section ?? $this->section)->id,
        ]);
    }

    // === ACCÈS ===

    public function test_la_page_s_ouvre_a_un_compte_habilite(): void
    {
        $this->get($this->url())->assertSuccessful();
    }

    public function test_un_compte_sans_permission_n_atteint_pas_la_page_par_l_url(): void
    {
        $sansRole = Utilisateur::create([
            'name' => 'Compte sans rôle',
            'email' => 'sans-role-brouillard@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
        ]);

        $this->actingAs($sansRole);

        $this->get($this->url())->assertForbidden();
    }

    /**
     * L'identifiant de section vient de l'URL : rien n'empêche de le
     * remplacer par celui d'une autre caisse. La page doit refuser, et
     * non servir le brouillard d'un tiers.
     */
    public function test_une_section_etrangere_a_la_caisse_de_l_url_est_refusee(): void
    {
        $autreCaisse = Caisse::create([
            'code' => 'CAISSE-ANNEXE',
            'libelle' => 'Caisse annexe',
            'etat' => 'ACTIVE',
            'village_id' => $this->village->id,
        ]);

        $this->get($this->url($autreCaisse))->assertNotFound();
    }

    // === CONTENU ===

    public function test_le_brouillard_montre_les_mouvements_de_sa_section(): void
    {
        Livewire::test(BrouillardCaissePage::class, [
            'caisse' => $this->caisse->id,
            'section' => $this->section->id,
        ])
            ->assertSee('Redevance de janvier')
            ->assertSee('Redevance de mars');
    }

    public function test_le_brouillard_ne_montre_pas_les_mouvements_d_une_autre_section(): void
    {
        $autreCaisse = Caisse::create([
            'code' => 'CAISSE-ANNEXE',
            'libelle' => 'Caisse annexe',
            'etat' => 'ACTIVE',
            'village_id' => $this->village->id,
        ]);

        $autreSection = SectionCaisse::create([
            'caisse_id' => $autreCaisse->id,
            'libelle' => 'Section annexe',
            'date_ouverture' => now(),
            'solde_ouverture' => 0,
            'etat' => EtatSectionCaisse::OUVERTE,
            'ouverte_par' => $this->caissier->id,
            'village_id' => $this->village->id,
            'exercice_id' => $this->exercice->id,
        ]);

        app(ServiceTresorerie::class)->enregistrer(
            section: $autreSection,
            nature: NatureMouvementCaisse::REDEVANCE,
            sens: SensMouvementCaisse::ENTREE,
            montant: 9_000,
            libelle: 'Redevance de la caisse annexe',
        );

        Livewire::test(BrouillardCaissePage::class, [
            'caisse' => $this->caisse->id,
            'section' => $this->section->id,
        ])
            ->assertSee('Redevance de janvier')
            ->assertDontSee('Redevance de la caisse annexe');
    }

    /**
     * Les bornes de dates portent sur `date_operation`.
     *
     * Le test vaut surtout pour ce qu'il a attrapé : le tableau filtrait
     * sur une colonne `date_mouvement` qui n'existe pas, pendant que les
     * totaux affichés juste au-dessus filtraient, eux, sur la bonne. Un
     * brouillard borné aurait donc montré des lignes et des totaux qui
     * ne se répondaient pas — le pire cas pour une pièce qu'on produit
     * en cas de contestation.
     */
    public function test_les_bornes_de_dates_filtrent_le_brouillard(): void
    {
        Livewire::test(BrouillardCaissePage::class, [
            'caisse' => $this->caisse->id,
            'section' => $this->section->id,
        ])
            ->set('date_debut', '2026-02-01')
            ->call('filter')
            ->assertDontSee('Redevance de janvier')
            ->assertSee('Redevance de mars')
            ->set('date_debut', null)
            ->set('date_fin', '2026-02-01')
            ->call('filter')
            ->assertSee('Redevance de janvier')
            ->assertDontSee('Redevance de mars');
    }

    public function test_les_totaux_suivent_les_memes_bornes_que_le_tableau(): void
    {
        $composant = Livewire::test(BrouillardCaissePage::class, [
            'caisse' => $this->caisse->id,
            'section' => $this->section->id,
        ]);

        $this->assertSame(10_000, $composant->instance()->total_entrees);

        $composant->set('date_debut', '2026-02-01');

        $this->assertSame(5_000, $composant->instance()->total_entrees);
        $this->assertSame(0, $composant->instance()->total_sorties);
    }

    // === TRACE ===

    public function test_la_consultation_laisse_une_trace_au_journal_d_audit(): void
    {
        $this->get($this->url())->assertSuccessful();

        $this->assertDatabaseHas('journaux_audit', [
            'action' => 'Consultation brouillard de caisse',
            'module' => 'TRESORERIE',
            'entite' => 'SectionCaisse',
            'entite_id' => $this->section->id,
            'utilisateur_id' => $this->caissier->id,
        ]);
    }
}
