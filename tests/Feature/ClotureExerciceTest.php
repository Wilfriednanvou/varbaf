<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Socle\Contracts\VerrouDeCloture;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Exceptions\ExerciceNonCloturableException;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Socle\Services\VerrousDeCloture;
use Modules\Tresorerie\Enums\EtatSectionCaisse;
use Modules\Tresorerie\Enums\StatutCampagneReversement;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\CampagneReversement;
use Modules\Tresorerie\Models\SectionCaisse;
use Modules\Tresorerie\Services\VerrouTresorerie;
use Tests\TestCase;

/**
 * Clôture d'un exercice — la dette DT-01, échue.
 *
 * Elle était ouverte parce que le Socle ne peut pas connaître la
 * Trésorerie : la règle de dépendance descendante le lui interdit. Le
 * registre des verrous renverse le sens de la connaissance — le Socle
 * expose un point d'accroche, la Trésorerie vient s'y déclarer — et
 * c'est ce renversement que ce fichier éprouve, autant que la règle
 * elle-même.
 */
class ClotureExerciceTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $exercice;

    protected Utilisateur $compte;

    protected Caisse $caisse;

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

        $this->compte = Utilisateur::create([
            'name' => 'Alice Ngassa',
            'email' => 'alice@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
        ]);

        $this->caisse = Caisse::create([
            'code' => 'CAISSE-TEST',
            'libelle' => 'Caisse de test',
            'etat' => 'ACTIVE',
            'village_id' => $this->village->id,
        ]);
    }

    protected function ouvrirUneSection(): SectionCaisse
    {
        return SectionCaisse::create([
            'caisse_id' => $this->caisse->id,
            'libelle' => 'Section de test',
            'date_ouverture' => now(),
            'solde_ouverture' => 0,
            'etat' => EtatSectionCaisse::OUVERTE,
            'ouverte_par' => $this->compte->id,
            'village_id' => $this->village->id,
            'exercice_id' => $this->exercice->id,
        ]);
    }

    protected function ouvrirUneCampagne(): CampagneReversement
    {
        return CampagneReversement::create([
            'periode' => '2026-08',
            'date_arrete' => '2026-08-31',
            'date_generation' => now(),
            'montant_total' => 0,
            'nombre_beneficiaires' => 0,
            'statut' => StatutCampagneReversement::EN_PREPARATION,
            'exercice_id' => $this->exercice->id,
            'generee_par' => $this->compte->id,
        ]);
    }

    // =================================================================

    public function test_la_tresorerie_a_bien_depose_son_verrou(): void
    {
        // Si ce test tombe, ce n'est pas la règle qui est cassée mais le
        // câblage : le fournisseur du module 4 n'a pas été chargé, et la
        // clôture ne protège plus rien sans que rien ne le signale.
        $this->assertGreaterThan(0, app(VerrousDeCloture::class)->compte());
    }

    public function test_un_exercice_sans_caisse_ni_campagne_se_cloture(): void
    {
        $this->assertTrue($this->exercice->cloturer());

        $this->assertTrue($this->exercice->fresh()->cloture);
        $this->assertFalse($this->exercice->fresh()->en_cours);
    }

    public function test_une_section_de_caisse_ouverte_empeche_la_cloture(): void
    {
        $this->ouvrirUneSection();

        try {
            $this->exercice->cloturer();
            $this->fail('La clôture aurait dû être refusée.');
        } catch (ExerciceNonCloturableException $refus) {
            $this->assertSame(
                ['une section de caisse est encore ouverte'],
                $refus->obstacles,
            );
        }

        // Et l'exercice n'a pas bougé : un refus ne laisse pas la base à
        // moitié modifiée.
        $this->assertFalse($this->exercice->fresh()->cloture);
        $this->assertTrue($this->exercice->fresh()->en_cours);
    }

    public function test_une_campagne_en_preparation_empeche_la_cloture(): void
    {
        $this->ouvrirUneCampagne();

        $this->expectException(ExerciceNonCloturableException::class);

        $this->exercice->cloturer();
    }

    public function test_les_obstacles_sont_annonces_ensemble_et_non_un_par_un(): void
    {
        $this->ouvrirUneSection();
        $this->ouvrirUneCampagne();

        try {
            $this->exercice->cloturer();
            $this->fail('La clôture aurait dû être refusée.');
        } catch (ExerciceNonCloturableException $refus) {
            $this->assertCount(2, $refus->obstacles);
            $this->assertStringContainsString('section de caisse', $refus->getMessage());
            $this->assertStringContainsString('campagne de reversement', $refus->getMessage());
            $this->assertStringContainsString('2026', $refus->getMessage());
        }
    }

    public function test_une_section_cloturee_et_une_campagne_validee_ne_l_empechent_plus(): void
    {
        $section = $this->ouvrirUneSection();
        $campagne = $this->ouvrirUneCampagne();

        // `forceFill` plutôt que les méthodes métier : ce test porte sur
        // la clôture de l'exercice, pas sur le chemin qui mène une
        // section à se fermer — lequel a ses propres tests.
        $section->forceFill(['etat' => EtatSectionCaisse::CLOTUREE])->saveQuietly();
        $campagne->forceFill(['statut' => StatutCampagneReversement::VALIDEE])->saveQuietly();

        $this->assertTrue($this->exercice->cloturer());
    }

    public function test_une_section_d_un_autre_exercice_ne_bloque_pas_celui_ci(): void
    {
        $autre = Exercice::create([
            'libelle' => '2025',
            'date_debut' => '2025-01-01',
            'date_fin' => '2025-12-31',
            'en_cours' => false,
            'village_id' => $this->village->id,
        ]);

        // Une seconde caisse : RG-01 n'autorise qu'une section ouverte
        // par caisse, et ce test a besoin de deux sections ouvertes en
        // même temps sur deux exercices différents.
        $autreCaisse = Caisse::create([
            'code' => 'CAISSE-2025',
            'libelle' => 'Caisse de 2025',
            'etat' => 'ACTIVE',
            'village_id' => $this->village->id,
        ]);

        SectionCaisse::create([
            'caisse_id' => $autreCaisse->id,
            'libelle' => 'Section de 2025',
            'date_ouverture' => now(),
            'solde_ouverture' => 0,
            'etat' => EtatSectionCaisse::OUVERTE,
            'ouverte_par' => $this->compte->id,
            'village_id' => $this->village->id,
            'exercice_id' => $autre->id,
        ]);

        $this->assertTrue($this->exercice->cloturer());
    }

    public function test_un_exercice_deja_cloture_renvoie_faux_sans_lever(): void
    {
        $this->exercice->cloturer();

        // Redemander une clôture faite n'est pas une faute : c'est un
        // doublon sans effet, et il ne mérite pas une exception.
        $this->assertFalse($this->exercice->cloturer());
    }

    public function test_le_registre_accueille_un_verrou_d_un_autre_module(): void
    {
        // Le point d'accroche n'est pas taillé pour la seule Trésorerie.
        app(VerrousDeCloture::class)->ajouter(new class implements VerrouDeCloture
        {
            public function obstacles(Exercice $exercice): array
            {
                return ['un inventaire est en cours'];
            }
        });

        try {
            $this->exercice->cloturer();
            $this->fail('La clôture aurait dû être refusée.');
        } catch (ExerciceNonCloturableException $refus) {
            $this->assertContains('un inventaire est en cours', $refus->obstacles);
        }
    }

    public function test_le_verrou_de_tresorerie_ne_repond_rien_sur_un_exercice_propre(): void
    {
        $this->assertSame([], (new VerrouTresorerie)->obstacles($this->exercice));
    }
}
