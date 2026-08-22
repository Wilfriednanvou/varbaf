<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Contracts\JournalDeCaisse;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Tresorerie\Enums\EtatSectionCaisse;
use Modules\Tresorerie\Enums\NatureMouvementCaisse;
use Modules\Tresorerie\Enums\SensMouvementCaisse;
use Modules\Tresorerie\Exceptions\MouvementCaisseImmuableException;
use Modules\Tresorerie\Exceptions\SectionCaisseException;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\MouvementCaisse;
use Modules\Tresorerie\Models\SectionCaisse;
use Modules\Tresorerie\Services\ServiceArreteCaisse;
use Modules\Tresorerie\Services\ServiceTresorerie;
use Tests\TestCase;

/**
 * Brouillard de caisse : les quatre garanties du service.
 *
 * Numérotation sans rupture (RG-04), immuabilité (RG-05), écriture
 * via service unique (RG-06), et règles de section (RG-01, RG-03,
 * RG-07). Calqué sur MouvementStockTest.
 */
class MouvementCaisseTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;
    protected Caisse $caisse;
    protected SectionCaisse $section;
    protected ServiceTresorerie $service;

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
            'actif' => true,
            'village_id' => $this->village->id,
        ]);

        $utilisateur = Utilisateur::create([
            'name' => 'Test',
            'email' => 'test@varbaf.local',
            'password' => bcrypt('test'),
            'est_super_utilisateur' => true,
        ]);

        $this->actingAs($utilisateur);

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
            'exercice_id' => $exercice->id,
        ]);

        $this->service = app(ServiceTresorerie::class);
    }

    // === NUMÉROTATION (RG-04) ===

    public function test_la_numerotation_est_sequentielle_et_sans_rupture(): void
    {
        $m1 = $this->enregistrerEntree(1000);
        $m2 = $this->enregistrerEntree(2000);
        $m3 = $this->enregistrerSortie(500);

        $this->assertSame(1, $m1->numero_ordre);
        $this->assertSame(2, $m2->numero_ordre);
        $this->assertSame(3, $m3->numero_ordre);
    }

    // === SOLDE PROGRESSIF ===

    public function test_le_solde_apres_est_calcule_a_chaque_ligne(): void
    {
        $m1 = $this->enregistrerEntree(5000);
        $m2 = $this->enregistrerEntree(3000);
        $m3 = $this->enregistrerSortie(2000);

        $this->assertEquals(5000, (float) $m1->solde_apres);
        $this->assertEquals(8000, (float) $m2->solde_apres);
        $this->assertEquals(6000, (float) $m3->solde_apres);
    }

    public function test_le_solde_du_service_correspond_au_cumul(): void
    {
        $this->enregistrerEntree(10000);
        $this->enregistrerSortie(3000);

        $this->assertEquals(7000, $this->service->solde($this->section));
    }

    // === IMMUABILITÉ (RG-05) ===

    public function test_un_mouvement_ne_peut_pas_etre_modifie(): void
    {
        $mouvement = $this->enregistrerEntree(1000);

        $this->expectException(MouvementCaisseImmuableException::class);

        $mouvement->update(['montant' => 9999]);
    }

    public function test_un_mouvement_ne_peut_pas_etre_supprime(): void
    {
        $mouvement = $this->enregistrerEntree(1000);

        $this->expectException(MouvementCaisseImmuableException::class);

        $mouvement->delete();
    }

    // === CONTRE-PASSATION ===

    public function test_la_contrepassation_annule_sans_effacer(): void
    {
        $entree = $this->enregistrerEntree(5000);
        $contrepassation = $this->service->contrepasser($entree, 'Erreur de saisie');

        // Le mouvement d'origine est intact
        $this->assertTrue($entree->fresh()->exists);

        // La contre-passation est de sens inverse
        $this->assertEquals(SensMouvementCaisse::SORTIE, $contrepassation->sens);
        $this->assertEquals(5000, (float) $contrepassation->montant);
        $this->assertEquals(NatureMouvementCaisse::CONTREPASSATION, $contrepassation->nature);
        $this->assertEquals($entree->id, $contrepassation->mouvement_contrepasse_id);

        // Le solde revient à zéro
        $this->assertEquals(0, $this->service->solde($this->section));
    }

    public function test_un_mouvement_ne_se_contrepasse_pas_deux_fois(): void
    {
        $entree = $this->enregistrerEntree(5000);
        $this->service->contrepasser($entree, 'Première correction');

        $this->expectException(MouvementCaisseImmuableException::class);

        $this->service->contrepasser($entree, 'Deuxième tentative');
    }

    public function test_une_contrepassation_ne_se_contrepasse_pas(): void
    {
        $entree = $this->enregistrerEntree(5000);
        $contrepassation = $this->service->contrepasser($entree, 'Correction');

        $this->expectException(MouvementCaisseImmuableException::class);

        $this->service->contrepasser($contrepassation, 'Re-correction');
    }

    // === SECTION OUVERTE (RG-01, RG-03) ===

    public function test_aucune_ecriture_hors_section_ouverte(): void
    {
        // Clôturer la section
        $this->section->forceFill([
            'etat' => EtatSectionCaisse::CLOTUREE,
            'date_cloture' => now(),
            'solde_cloture' => 0,
            'cloturee_par' => auth()->id(),
        ])->save();

        $this->expectException(SectionCaisseException::class);

        $this->service->enregistrer(
            $this->section,
            NatureMouvementCaisse::VENTE,
            SensMouvementCaisse::ENTREE,
            1000,
            'Test interdit',
        );
    }

    public function test_une_seule_section_ouverte_par_caisse(): void
    {
        // La première section (créée au setUp) est déjà ouverte : en
        // ouvrir une deuxième sur la même caisse doit être refusé, pas
        // seulement constaté.
        $this->expectException(SectionCaisseException::class);

        SectionCaisse::create([
            'caisse_id' => $this->caisse->id,
            'libelle' => 'Deuxième section',
            'date_ouverture' => now(),
            'solde_ouverture' => 0,
            'etat' => EtatSectionCaisse::OUVERTE,
            'ouverte_par' => auth()->id(),
            'village_id' => $this->village->id,
            'exercice_id' => $this->section->exercice_id,
        ]);
    }

    public function test_une_section_ouverte_sur_une_autre_caisse_est_acceptee(): void
    {
        // La contrainte est par caisse, pas globale : une deuxième
        // caisse peut avoir sa propre section ouverte.
        $autreCaisse = Caisse::create([
            'code' => 'CAISSE-DEUX',
            'libelle' => 'Deuxième caisse',
            'etat' => 'ACTIVE',
            'village_id' => $this->village->id,
        ]);

        $section = SectionCaisse::create([
            'caisse_id' => $autreCaisse->id,
            'libelle' => 'Section de la deuxième caisse',
            'date_ouverture' => now(),
            'solde_ouverture' => 0,
            'etat' => EtatSectionCaisse::OUVERTE,
            'ouverte_par' => auth()->id(),
            'village_id' => $this->village->id,
            'exercice_id' => $this->section->exercice_id,
        ]);

        $this->assertTrue($section->exists);
    }

    // === CLÔTURE IRRÉVERSIBLE (RG-07) ===

    public function test_la_cloture_est_irreversible(): void
    {
        $this->section->forceFill([
            'etat' => EtatSectionCaisse::CLOTUREE,
            'date_cloture' => now(),
            'solde_cloture' => 0,
            'cloturee_par' => auth()->id(),
        ])->save();

        $this->expectException(SectionCaisseException::class);

        $this->section->update(['etat' => 'OUVERTE']);
    }

    /**
     * RG-07 : « la clôture n'est possible que si toutes ses journées ont
     * été arrêtées ». Sans ce contrôle, un exercice entier se clôturait
     * sans qu'aucun comptage physique n'ait eu lieu — et l'arrêté
     * journalier, seul mécanisme de contrôle interne du module, ne
     * servait plus à rien.
     */
    public function test_une_section_ne_se_cloture_pas_tant_qu_une_journee_n_est_pas_arretee(): void
    {
        $this->enregistrerEntree(5000);

        $this->assertNotEmpty(
            $this->section->journeesNonArretees(),
            "La journée du mouvement n'a pas été arrêtée.",
        );

        $this->expectException(SectionCaisseException::class);

        $this->section->cloturer();
    }

    public function test_une_section_dont_les_journees_sont_arretees_se_cloture(): void
    {
        $this->enregistrerEntree(5000);

        app(ServiceArreteCaisse::class)->arreter($this->section, now(), 5000);

        $this->assertSame([], $this->section->journeesNonArretees());
        $this->assertSame(5000, $this->section->cloturer());
        $this->assertTrue($this->section->fresh()->estCloturee());
    }

    public function test_une_section_sans_mouvement_se_cloture_sans_arrete(): void
    {
        // Rien à compter, rien à arrêter : une section ouverte par
        // erreur et refermée aussitôt ne doit pas exiger un arrêté.
        $this->assertSame(0, $this->section->cloturer());
        $this->assertTrue($this->section->fresh()->estCloturee());
    }

    // === RG-02 : SOLDE D'OUVERTURE HÉRITÉ ===

    public function test_le_solde_d_ouverture_reprend_la_cloture_precedente(): void
    {
        $this->enregistrerEntree(12000);
        $this->enregistrerSortie(2000);

        app(ServiceArreteCaisse::class)->arreter($this->section, now(), 10000);
        $this->assertSame(10000, $this->section->cloturer());

        $suivante = SectionCaisse::create([
            'caisse_id' => $this->caisse->id,
            'libelle' => 'Section suivante',
            'date_ouverture' => now(),
            // Une valeur fausse est fournie à dessein : le modèle la
            // remplace. RG-02 ne se laisse pas écraser à la saisie.
            'solde_ouverture' => 999999,
            'etat' => EtatSectionCaisse::OUVERTE,
            'ouverte_par' => auth()->id(),
            'village_id' => $this->village->id,
            'exercice_id' => $this->section->exercice_id,
        ]);

        $this->assertSame(
            10000,
            $suivante->solde_ouverture,
            "Le solde d'ouverture est celui de clôture de la section précédente (RG-02).",
        );
    }

    public function test_la_premiere_section_d_une_caisse_ouvre_a_zero(): void
    {
        $autreCaisse = Caisse::create([
            'code' => 'CAISSE-NEUVE',
            'libelle' => 'Caisse neuve',
            'etat' => 'ACTIVE',
            'village_id' => $this->village->id,
        ]);

        $section = SectionCaisse::create([
            'caisse_id' => $autreCaisse->id,
            'libelle' => 'Première section',
            'date_ouverture' => now(),
            'solde_ouverture' => 50000,
            'etat' => EtatSectionCaisse::OUVERTE,
            'ouverte_par' => auth()->id(),
            'village_id' => $this->village->id,
            'exercice_id' => $this->section->exercice_id,
        ]);

        $this->assertSame(0, $section->solde_ouverture);
    }

    // === MONTANT INVALIDE ===

    public function test_un_montant_nul_ou_negatif_est_refuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->enregistrer(
            $this->section,
            NatureMouvementCaisse::DEPENSE,
            SensMouvementCaisse::SORTIE,
            0,
            'Montant invalide',
        );
    }

    // === PORT JOURNAL DE CAISSE ===

    public function test_le_port_journal_de_caisse_est_operationnel(): void
    {
        $journal = app(JournalDeCaisse::class);

        $this->assertTrue($journal->estOperationnel());
        $this->assertInstanceOf(ServiceTresorerie::class, $journal);
    }

    // === VERROU DE NUMÉROTATION (RG-04, §7.3) ===

    /**
     * `SELECT ... FOR UPDATE` ne verrouille que les lignes qu'il
     * retourne. Verrouiller les mouvements d'une section encore vide ne
     * verrouille donc rien, et deux saisies simultanées obtiennent le
     * même numéro d'ordre. Le verrou doit porter sur la ligne de
     * section, qui existe toujours.
     *
     * Le test lit le SQL réellement émis : la concurrence, elle, ne se
     * laisse pas éprouver de façon déterministe.
     */
    public function test_l_ecriture_verrouille_la_ligne_de_section_et_non_le_brouillard(): void
    {
        $this->enregistrerEntree(1000);

        $requetes = [];
        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $this->enregistrerEntree(2000);

        $verrous = array_values(array_filter(
            $requetes,
            fn (string $sql) => str_contains($sql, 'for update'),
        ));

        $this->assertNotEmpty($verrous, 'Une écriture au brouillard doit poser un verrou.');

        foreach ($verrous as $sql) {
            $this->assertStringContainsString(
                'sections_caisse',
                $sql,
                'Le verrou doit porter sur la ligne de section, qui existe toujours.',
            );
            $this->assertStringNotContainsString(
                'mouvements_caisse',
                $sql,
                'Verrouiller les mouvements ne verrouille rien sur une section vide, et charge toute la section sur une section pleine.',
            );
        }
    }

    /**
     * La boucle de reprise n'existe que pour le doublon de numéro
     * d'ordre. Une erreur qui ne se résoudra jamais — ici un libellé
     * plus long que sa colonne — doit remonter du premier coup.
     */
    public function test_une_erreur_autre_qu_un_doublon_n_est_pas_reessayee(): void
    {
        $tentatives = 0;

        MouvementCaisse::creating(function () use (&$tentatives): void {
            $tentatives++;
        });

        try {
            $this->service->enregistrer(
                $this->section,
                NatureMouvementCaisse::DEPENSE,
                SensMouvementCaisse::SORTIE,
                1000,
                str_repeat('x', 300), // `libelle` est un varchar(255)
            );

            $this->fail("Une erreur SQL autre qu'un doublon doit remonter.");
        } catch (QueryException) {
            // Attendu : l'exception remonte, c'est le nombre de
            // tentatives qui est en cause ici.
        }

        $this->assertSame(
            1,
            $tentatives,
            'Une erreur qui ne se résoudra jamais ne doit pas être réessayée trois fois.',
        );
    }

    // === HELPERS ===

    protected function enregistrerEntree(int $montant, string $libelle = 'Test entrée'): MouvementCaisse
    {
        return $this->service->enregistrer(
            $this->section,
            NatureMouvementCaisse::VENTE,
            SensMouvementCaisse::ENTREE,
            $montant,
            $libelle,
        );
    }

    protected function enregistrerSortie(int $montant, string $libelle = 'Test sortie'): MouvementCaisse
    {
        return $this->service->enregistrer(
            $this->section,
            NatureMouvementCaisse::DEPENSE,
            SensMouvementCaisse::SORTIE,
            $montant,
            $libelle,
        );
    }
}
