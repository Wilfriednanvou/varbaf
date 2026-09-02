<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Enums\StatutExercice;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * Le statut a quatre valeurs, dérivé des deux booléens historiques.
 *
 * `en_cours` et `cloture` restent la source écrite par le formulaire et
 * par activer()/cloturer() — ce fichier ne les remplace pas, il éprouve
 * que `statut` en reste le reflet fidèle à chaque écriture, y compris
 * pour ARCHIVE, seul état sans équivalent dans les deux booléens.
 */
class StatutExerciceTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

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
    }

    protected function nouvelExercice(string $libelle = '2026'): Exercice
    {
        return Exercice::create([
            'libelle' => $libelle,
            'date_debut' => "{$libelle}-01-01",
            'date_fin' => "{$libelle}-12-31",
            'village_id' => $this->village->id,
        ]);
    }

    public function test_un_exercice_cree_sans_rien_d_autre_est_en_preparation(): void
    {
        $exercice = $this->nouvelExercice();

        $this->assertSame(StatutExercice::EN_PREPARATION, $exercice->statut);
    }

    public function test_activer_fait_passer_le_statut_a_actif(): void
    {
        $exercice = $this->nouvelExercice();

        $exercice->activer();

        $this->assertSame(StatutExercice::ACTIF, $exercice->fresh()->statut);
    }

    public function test_cloturer_fait_passer_le_statut_a_cloture(): void
    {
        $exercice = $this->nouvelExercice();
        $exercice->activer();

        $exercice->cloturer();

        $this->assertSame(StatutExercice::CLOTURE, $exercice->fresh()->statut);
    }

    public function test_archiver_un_exercice_non_cloture_est_refuse_sans_lever(): void
    {
        $exercice = $this->nouvelExercice();
        $exercice->activer();

        $this->assertFalse($exercice->archiver());
        $this->assertSame(StatutExercice::ACTIF, $exercice->fresh()->statut);
    }

    public function test_archiver_un_exercice_cloture_le_range_en_archive(): void
    {
        $exercice = $this->nouvelExercice();
        $exercice->activer();
        $exercice->cloturer();

        $this->assertTrue($exercice->archiver());
        $this->assertSame(StatutExercice::ARCHIVE, $exercice->fresh()->statut);
    }

    public function test_une_sauvegarde_anodine_apres_archivage_ne_revient_pas_a_cloture(): void
    {
        // C'est le garde-fou du crochet : sans lui, ARCHIVE serait
        // défait par la première écriture qui suit, aussi anodine
        // soit-elle — ici une simple correction de libellé.
        $exercice = $this->nouvelExercice();
        $exercice->activer();
        $exercice->cloturer();
        $exercice->archiver();

        $exercice->fresh()->update(['libelle' => '2026 bis']);

        $this->assertSame(StatutExercice::ARCHIVE, $exercice->fresh()->statut);
    }
}
